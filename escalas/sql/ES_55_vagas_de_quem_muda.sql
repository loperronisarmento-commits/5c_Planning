-- =====================================================================
-- ES_55_vagas_de_quem_muda.sql  (31/10)
-- Vagas fixas que ficaram abertas depois que a pessoa muda de unidade ou de turno.
-- O ES_48 (e o ES_46/ES_50) fecharam as vagas que existiam quando rodaram; as que o
-- "Gerar", a atualização dos postos e o ES_54 criaram depois continuaram abertas além
-- da data da mudança (ex.: M801365 nos postos 671, 685 e 690 depois de 09/11). O motor
-- já não usa essas vagas (só aloca quem é da unidade e do turno), mas elas contam
-- como equipe: a vaga não é completada e as telas mostram a pessoa no posto antigo.
--
-- Regra, para qualquer pessoa (sem lista fixa; pode rodar de novo sempre que precisar):
--   1. vaga que começa antes de uma mudança de unidade ou turno e continua aberta
--      depois dela: fecha na véspera da mudança (31/10: 39 vagas de 15 SRs, mudanças
--      de 09/11, 16/11 e 23/11);
--   2. vaga que já começa num posto de outra unidade ou turno que o cadastro da pessoa
--      na data: nunca valeu, vai para a data neutra 29/10 (31/10: 4 vagas, uma delas do
--      J108431 na Cynthia até 22/11).
-- E completa o ES_54: o Marcos (M137535) entra na equipe do D da Aline e da Andressa a
-- partir de 09/11, quando vai para a U2 D (Folga juntos). A versão do ES_54 que rodou
-- não tinha essa parte; a vaga dele no C fecha em 08/11 pela regra 1.
--
-- Depois: Gerar as semanas de 09/11 em diante; as vagas que ficaram sem ninguém são
-- completadas com quem é da unidade e do turno (lib/equipes.php).
-- Atenção, Privacy no turno C a partir de 09/11: o G045315 vai para a U1 A (ES_48) e o
-- Marcos para o D; fica só o L052002 na equipe da Aline e da Andressa no C.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es55;
CREATE TEMPORARY TABLE tmp_es55 (
  vaga_id INT NOT NULL PRIMARY KEY,
  regra TINYINT NOT NULL,
  fecha DATE NULL COMMENT 'regra 1: véspera da mudança'
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Regra 2 primeiro (vale mais): a vaga já começa fora da unidade ou do turno da pessoa.
INSERT INTO tmp_es55 (vaga_id, regra, fecha)
SELECT DISTINCT v.id, 2, NULL
  FROM esc_vaga_equipe v
  JOIN esc_posto p ON p.id = v.posto_id
  JOIN esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
   AND c.vigente_desde <= v.vigente_desde AND (c.vigente_ate IS NULL OR c.vigente_ate >= v.vigente_desde)
 WHERE v.vigente_desde > '2026-10-29' AND (v.vigente_ate IS NULL OR v.vigente_ate >= v.vigente_desde)
   AND NOT (IFNULL(c.unidade = p.unidade, 0) AND IFNULL(c.turno = p.turno COLLATE utf8mb4_unicode_ci, 0));

-- Regra 1: a primeira mudança de unidade ou turno depois do início da vaga, com a vaga aberta.
INSERT IGNORE INTO tmp_es55 (vaga_id, regra, fecha)
SELECT v.id, 1, DATE_SUB(MIN(DATE(c.vigente_desde)), INTERVAL 1 DAY)
  FROM esc_vaga_equipe v
  JOIN esc_posto p ON p.id = v.posto_id
  JOIN esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
   AND c.vigente_desde > v.vigente_desde AND (v.vigente_ate IS NULL OR v.vigente_ate >= c.vigente_desde)
   AND (p.vigente_ate IS NULL OR p.vigente_ate >= c.vigente_desde)
 WHERE v.vigente_desde > '2026-10-29'
   AND NOT (IFNULL(c.unidade = p.unidade, 0) AND IFNULL(c.turno = p.turno COLLATE utf8mb4_unicode_ci, 0))
 GROUP BY v.id;

-- Antes: o que vai mudar
SELECT t.regra, UPPER(v.rep_code) AS rep, v.posto_id, p.pagina_principal, p.unidade, p.turno, v.vaga,
       DATE(v.vigente_desde) AS desde, DATE(v.vigente_ate) AS ate_antes, IFNULL(t.fecha, '2026-10-29 (neutra)') AS ate_depois
  FROM tmp_es55 t JOIN esc_vaga_equipe v ON v.id = t.vaga_id JOIN esc_posto p ON p.id = v.posto_id
 ORDER BY t.regra, ate_depois, rep, v.posto_id;

START TRANSACTION;

UPDATE esc_vaga_equipe v
  JOIN tmp_es55 t ON t.vaga_id = v.id AND t.regra = 1
   SET v.vigente_ate = t.fecha,
       v.motivo = LEFT(CONCAT('ES_55: mudou de unidade ou turno em ', DATE_FORMAT(DATE_ADD(t.fecha, INTERVAL 1 DAY), '%d/%m'), '; ', COALESCE(v.motivo, '')), 255);

-- Regra 2 na data neutra; se a mesma pessoa já tem a linha neutra nessa vaga, a repetida sai.
DELETE v FROM esc_vaga_equipe v
  JOIN tmp_es55 t ON t.vaga_id = v.id AND t.regra = 2
 WHERE EXISTS (SELECT 1 FROM (SELECT posto_id, vaga, rep_code FROM esc_vaga_equipe WHERE vigente_desde = '2026-10-29') z
                WHERE z.posto_id = v.posto_id AND z.vaga = v.vaga AND z.rep_code = v.rep_code);
UPDATE esc_vaga_equipe v
  JOIN tmp_es55 t ON t.vaga_id = v.id AND t.regra = 2
   SET v.vigente_desde = '2026-10-29', v.vigente_ate = '2026-10-29',
       v.motivo = LEFT(CONCAT('ES_55: fora da unidade ou do turno da pessoa; ', COALESCE(v.motivo, '')), 255);

-- Marcos (M137535) na equipe do D da Aline e da Andressa a partir de 09/11 (parte do ES_54).
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT q.id, g.vaga, 'M137535', 0.25, 1, 'CONFIRMADA',
       'Privacy (ES_54/ES_55): equipe da Aline e da Andressa no D a partir de 09/11 (Folga juntos)', '2026-11-09', NULL, 'TELA'
  FROM (SELECT DISTINCT p.id FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id
         WHERE pp.pagina IN ('Aline Novak', 'Andressa Urach') AND p.unidade = 2 AND p.turno = 'D'
           AND p.vigente_desde <= '2026-11-09' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-09')) q
  JOIN (SELECT 'DONO' AS vaga UNION ALL SELECT 'COBERTURA') g
 WHERE EXISTS (SELECT 1 FROM esc_colaborador_config c WHERE UPPER(c.rep_code) = 'M137535' AND c.turno = 'D' AND c.unidade = 2
                 AND c.vigente_desde <= '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09'))
   AND NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = q.id AND e.vaga = g.vaga COLLATE utf8mb4_unicode_ci
                     AND UPPER(e.rep_code) = 'M137535' AND e.vigente_desde <= '2026-11-09' AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-09'));

COMMIT;

-- Conferência 1: as duas regras de novo (esperado 0 e 0)
SELECT 'regra 1: aberta depois da mudança' AS item, COUNT(DISTINCT v.id) AS vagas
  FROM esc_vaga_equipe v
  JOIN esc_posto p ON p.id = v.posto_id
  JOIN esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
   AND c.vigente_desde > v.vigente_desde AND (v.vigente_ate IS NULL OR v.vigente_ate >= c.vigente_desde)
   AND (p.vigente_ate IS NULL OR p.vigente_ate >= c.vigente_desde)
 WHERE v.vigente_desde > '2026-10-29'
   AND NOT (IFNULL(c.unidade = p.unidade, 0) AND IFNULL(c.turno = p.turno COLLATE utf8mb4_unicode_ci, 0))
UNION ALL
SELECT 'regra 2: começa fora da unidade ou turno', COUNT(DISTINCT v.id)
  FROM esc_vaga_equipe v
  JOIN esc_posto p ON p.id = v.posto_id
  JOIN esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
   AND c.vigente_desde <= v.vigente_desde AND (c.vigente_ate IS NULL OR c.vigente_ate >= v.vigente_desde)
 WHERE v.vigente_desde > '2026-10-29' AND (v.vigente_ate IS NULL OR v.vigente_ate >= v.vigente_desde)
   AND NOT (IFNULL(c.unidade = p.unidade, 0) AND IFNULL(c.turno = p.turno COLLATE utf8mb4_unicode_ci, 0));

-- Conferência 2: equipe da Privacy em 09/11 e 16/11 (C: L052002; D: J857800, H126549, M137535 e,
-- até 15/11, T321412; A: K169904, M078957, N176385, L114083)
SELECT d.dia, p.turno, e.vaga, GROUP_CONCAT(DISTINCT UPPER(e.rep_code) ORDER BY e.rep_code SEPARATOR ', ') AS equipe
  FROM (SELECT DATE('2026-11-09') AS dia UNION ALL SELECT DATE('2026-11-16')) d
  JOIN esc_posto p ON p.unidade = 2 AND p.vigente_desde <= d.dia AND (p.vigente_ate IS NULL OR p.vigente_ate >= d.dia)
  JOIN esc_posto_pagina pp ON pp.posto_id = p.id AND pp.pagina IN ('Aline Novak', 'Andressa Urach')
  JOIN esc_vaga_equipe e ON e.posto_id = p.id AND e.vigente_desde <= d.dia AND (e.vigente_ate IS NULL OR e.vigente_ate >= d.dia)
 GROUP BY d.dia, p.turno, e.vaga ORDER BY d.dia, FIELD(p.turno, 'A', 'C', 'D'), e.vaga;

DROP TEMPORARY TABLE IF EXISTS tmp_es55;
