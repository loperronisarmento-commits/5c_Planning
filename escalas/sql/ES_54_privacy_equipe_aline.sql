-- =====================================================================
-- ES_54_privacy_equipe_aline.sql  (31/10)
-- Privacy: a equipe que fez a Aline Novak nas últimas 4 semanas (5C Schedule, 04/10 a
-- 31/10) passa a ser a equipe fixa da Aline Novak e da Andressa Urach, a partir de 02/11.
-- As duas páginas estão na U2; quem está em outra unidade vai para a U2, no mesmo turno
-- da estrutura nova, num núcleo escolhido para que todo dia trabalhem pelo menos 2 da
-- equipe no turno (a cada dia trabalham 2 dos 3 núcleos):
--
--   Turno       Fica na U2                         Vai para a U2
--   T2/T3 (A)   K169904 (núcleo 1)                 M078957 (U1 → núcleo 2), N176385 (U1 → núcleo 2),
--                                                  L114083 (U3 → núcleo 3)
--   T4/T5 (C)   M137535 (núcleo 2), G045315 (n. 1) L052002 (U1 → núcleo 3)
--   T6/T1 (D)   T321412 (núcleo 2)                 J857800 (U1 → núcleo 1), H126549 (U3 → núcleo 3)
--
-- Fora da lista: I351554 virou OS na U3 e E009869 não está na estrutura nova. M137535
-- (Marcos) fez C e D na Aline: fica na equipe do C até 08/11 e passa para a do D a partir
-- de 09/11, quando vai para a U2 D núcleo 2 (Folga juntos, ES_46 e ES_50). N176385 fez D
-- na Aline e está no A na estrutura nova, então entra pelo A.
--
-- Nos postos das duas páginas em cada turno (Aline: 672 A, 677 C, 684 D; Andressa: 434 A,
-- 435 C, 436 D), a equipe entra como dono e como cobertura, com a fatia dividida por igual;
-- quem estava lá e não é da equipe sai a partir de 02/11. As vagas que os 6 deixam nas
-- unidades de origem o "Gerar" completa (lib/equipes.php).
--
-- Os postos são lidos pelo nome da página, vigentes em 02/11. Independe do ES_52 (nenhum
-- dos 6 está nele); com os dois, a U2 recebe 6 dos 18 SRs que o ES_52 tira.
-- Mesmo padrão do ES_52: o cadastro novo abre em 02/11 e o atual fecha em 01/11; vaga que
-- começava em 02/11 ou depois vai para a data neutra (29/10). Pode rodar de novo.
-- Depois: Gerar escala › semana de 02/11 › Gerar; conferir, publicar e postar.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es54_time;
CREATE TEMPORARY TABLE tmp_es54_time (
  rep_code VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  turno CHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  numero TINYINT UNSIGNED NULL COMMENT 'núcleo de destino na U2; NULL = já está na U2'
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO tmp_es54_time (rep_code, turno, numero) VALUES
  ('K169904', 'A', NULL), ('M078957', 'A', 2), ('N176385', 'A', 2), ('L114083', 'A', 3),
  ('M137535', 'C', NULL), ('G045315', 'C', NULL), ('L052002', 'C', 3),
  ('T321412', 'D', NULL), ('J857800', 'D', 1), ('H126549', 'D', 3);

DROP TEMPORARY TABLE IF EXISTS tmp_es54_postos;
CREATE TEMPORARY TABLE tmp_es54_postos (
  posto_id INT NOT NULL PRIMARY KEY,
  turno CHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  desde DATE NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO tmp_es54_postos (posto_id, turno, desde)
SELECT DISTINCT p.id, p.turno, GREATEST('2026-11-02', DATE(p.vigente_desde))
  FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id
 WHERE pp.pagina IN ('Aline Novak', 'Andressa Urach') AND p.unidade = 2
   AND p.vigente_desde <= '2026-11-02' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-02');

-- Cópia para os subselects (o MySQL não reabre a mesma tabela temporária na mesma consulta).
DROP TEMPORARY TABLE IF EXISTS tmp_es54_time2;
CREATE TEMPORARY TABLE tmp_es54_time2 LIKE tmp_es54_time;
INSERT INTO tmp_es54_time2 SELECT * FROM tmp_es54_time;

START TRANSACTION;

-- 1. Quem vai para a U2: cadastro novo a partir de 02/11 (copia o que vale hoje).
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-02', NULL,
       CONCAT('Privacy (ES_54): U', c.unidade, ' → U2 núcleo ', n.numero, ' a partir de 02/11/2026, equipe da Aline Novak e da Andressa Urach'), 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c
  JOIN tmp_es54_time x ON x.rep_code = UPPER(c.rep_code) AND x.numero IS NOT NULL
  JOIN esc_nucleo n ON n.unidade = 2 AND n.turno = x.turno AND n.numero = x.numero
                   AND n.vigente_desde <= '2026-11-02' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-02')
 WHERE c.funcao = 'EQUITE' AND c.turno = x.turno AND c.unidade <> 2
   AND c.vigente_desde < '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
   AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE UPPER(f.rep_code) = x.rep_code AND f.vigente_desde >= '2026-11-02');

-- 2. O cadastro de antes fecha em 01/11 (só de quem já tem o novo).
UPDATE esc_colaborador_config c
  JOIN tmp_es54_time x ON x.rep_code = UPPER(c.rep_code) AND x.numero IS NOT NULL
   SET c.vigente_ate = '2026-11-01'
 WHERE c.vigente_desde < '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
   AND EXISTS (SELECT 1 FROM (SELECT DISTINCT UPPER(rep_code) AS rep FROM esc_colaborador_config WHERE vigente_desde = '2026-11-02' AND observacao LIKE 'Privacy (ES_54)%') f
                WHERE f.rep = x.rep_code);

-- 3. Vagas que fecham a partir de 02/11:
--    a) as dos 6 que mudam de unidade, fora dos postos da Privacy;
--    b) nos postos da Privacy, as de quem não é da equipe.
UPDATE esc_vaga_equipe v
  LEFT JOIN tmp_es54_postos q ON q.posto_id = v.posto_id
  LEFT JOIN tmp_es54_time x ON x.rep_code = UPPER(v.rep_code)
   SET v.vigente_ate = '2026-11-01',
       v.motivo = LEFT(CONCAT('Privacy (ES_54): ', IF(q.posto_id IS NULL, 'foi para a U2; ', 'equipe da Aline nas páginas da Privacy; '), COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde < '2026-11-02' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-02')
   AND ((q.posto_id IS NULL AND x.numero IS NOT NULL) OR (q.posto_id IS NOT NULL AND x.rep_code IS NULL));

UPDATE esc_vaga_equipe v
  LEFT JOIN tmp_es54_postos q ON q.posto_id = v.posto_id
  LEFT JOIN tmp_es54_time x ON x.rep_code = UPPER(v.rep_code)
   SET v.vigente_desde = '2026-10-29', v.vigente_ate = '2026-10-29',
       v.motivo = LEFT(CONCAT('Privacy (ES_54): ', IF(q.posto_id IS NULL, 'foi para a U2; ', 'equipe da Aline nas páginas da Privacy; '), COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde >= '2026-11-02'
   AND ((q.posto_id IS NULL AND x.numero IS NOT NULL) OR (q.posto_id IS NOT NULL AND x.rep_code IS NULL))
   AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT posto_id, vaga, rep_code FROM esc_vaga_equipe WHERE vigente_desde = '2026-10-29') z
                    WHERE z.posto_id = v.posto_id AND z.vaga = v.vaga AND z.rep_code = v.rep_code);

-- Repetida na data neutra (a mesma pessoa já tinha a linha de 29/10): sai.
DELETE v FROM esc_vaga_equipe v
  LEFT JOIN tmp_es54_postos q ON q.posto_id = v.posto_id
  LEFT JOIN tmp_es54_time x ON x.rep_code = UPPER(v.rep_code)
 WHERE v.vigente_desde >= '2026-11-02'
   AND ((q.posto_id IS NULL AND x.numero IS NOT NULL) OR (q.posto_id IS NOT NULL AND x.rep_code IS NULL));

-- 4. A equipe entra como dono e como cobertura nos postos das duas páginas, em cada turno.
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT q.posto_id, g.vaga, x.rep_code,
       ROUND(1 / (SELECT COUNT(*) FROM tmp_es54_time2 y WHERE y.turno = x.turno), 3), 1, 'CONFIRMADA',
       'Privacy (ES_54): equipe que fez a Aline Novak nas últimas 4 semanas, na Aline e na Andressa', q.desde, NULL, 'TELA'
  FROM tmp_es54_postos q
  JOIN tmp_es54_time x ON x.turno = q.turno
  JOIN (SELECT 'DONO' AS vaga UNION ALL SELECT 'COBERTURA') g
 WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e
                    WHERE e.posto_id = q.posto_id AND e.vaga = g.vaga COLLATE utf8mb4_unicode_ci AND UPPER(e.rep_code) = x.rep_code
                      AND e.vigente_desde <= q.desde AND (e.vigente_ate IS NULL OR e.vigente_ate >= q.desde));

-- Quem já estava na vaga fica, com a mesma fatia dos outros da equipe.
UPDATE esc_vaga_equipe e
  JOIN tmp_es54_postos q ON q.posto_id = e.posto_id
  JOIN tmp_es54_time x ON x.rep_code = UPPER(e.rep_code) AND x.turno = q.turno
   SET e.fatia = ROUND(1 / (SELECT COUNT(*) FROM tmp_es54_time2 y WHERE y.turno = x.turno), 3)
 WHERE e.vigente_desde <= q.desde AND (e.vigente_ate IS NULL OR e.vigente_ate >= q.desde);

-- 5. Marcos (M137535): no C até 08/11; no D a partir de 09/11 (cadastro do ES_46/ES_50).
UPDATE esc_vaga_equipe e
  JOIN tmp_es54_postos q ON q.posto_id = e.posto_id AND q.turno = 'C'
   SET e.vigente_ate = '2026-11-08'
 WHERE UPPER(e.rep_code) = 'M137535' AND e.vigente_desde <= '2026-11-08' AND (e.vigente_ate IS NULL OR e.vigente_ate > '2026-11-08')
   AND EXISTS (SELECT 1 FROM esc_colaborador_config c WHERE UPPER(c.rep_code) = 'M137535' AND c.turno = 'D' AND c.unidade = 2 AND c.vigente_desde = '2026-11-09');

INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT q.posto_id, g.vaga, 'M137535', 0.25, 1, 'CONFIRMADA',
       'Privacy (ES_54): equipe da Aline e da Andressa no D a partir de 09/11 (Folga juntos)', '2026-11-09', NULL, 'TELA'
  FROM tmp_es54_postos q
  JOIN (SELECT 'DONO' AS vaga UNION ALL SELECT 'COBERTURA') g
 WHERE q.turno = 'D'
   AND EXISTS (SELECT 1 FROM esc_colaborador_config c WHERE UPPER(c.rep_code) = 'M137535' AND c.turno = 'D' AND c.unidade = 2 AND c.vigente_desde = '2026-11-09')
   AND NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = q.posto_id AND e.vaga = g.vaga COLLATE utf8mb4_unicode_ci
                     AND UPPER(e.rep_code) = 'M137535' AND e.vigente_desde <= '2026-11-09' AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-09'));

COMMIT;

-- Conferência 1: os 10, com o cadastro de 02/11 (todos na U2, no turno da lista)
SELECT x.rep_code, x.turno, c.unidade, n.numero AS nucleo, c.nivel, c.vigente_desde
  FROM tmp_es54_time x
  LEFT JOIN esc_colaborador_config c ON UPPER(c.rep_code) = x.rep_code AND c.funcao = 'EQUITE'
       AND c.vigente_desde <= '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
  LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 ORDER BY FIELD(x.turno, 'A', 'C', 'D'), x.rep_code;

-- Conferência 2: equipe dos postos da Privacy em 03/11 (esperado: A 4, C 3, D 3 em cada vaga;
-- em 09/11, troque a data: C 2 e D 4, com o Marcos no D)
SELECT q.posto_id, q.turno, (SELECT GROUP_CONCAT(pp.pagina SEPARATOR ' + ') FROM esc_posto_pagina pp WHERE pp.posto_id = q.posto_id) AS paginas, e.vaga,
       GROUP_CONCAT(CONCAT(UPPER(e.rep_code), ' ', e.fatia) ORDER BY e.rep_code SEPARATOR ', ') AS equipe
  FROM tmp_es54_postos q
  JOIN esc_vaga_equipe e ON e.posto_id = q.posto_id AND e.vigente_desde <= '2026-11-03' AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-03')
 GROUP BY q.posto_id, q.turno, e.vaga ORDER BY FIELD(q.turno, 'A', 'C', 'D'), q.posto_id, e.vaga;

DROP TEMPORARY TABLE IF EXISTS tmp_es54_time;
DROP TEMPORARY TABLE IF EXISTS tmp_es54_time2;
DROP TEMPORARY TABLE IF EXISTS tmp_es54_postos;
