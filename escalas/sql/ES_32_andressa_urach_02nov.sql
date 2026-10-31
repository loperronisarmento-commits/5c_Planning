-- =====================================================================
-- ES_32_andressa_urach_02nov.sql  (30/10)
-- Página nova da Privacy: Andressa Urach, entra em 02/11 às 13:00 (turno T4/T5),
-- com previsão de 25 fãs/h. Já está no site da 5C (5c_pages.pages id 492, Privacy, tier C, 30/10).
--   1. Capacity (5c_planning):
--      - fc_paginas_previstas: previsão manual confirmada (25 fãs/h, 02/11);
--      - fc_pagina_carteira: entra na carteira U2C2 (a mesma da Aline Novak, também
--        da Privacy) a partir de 02/11, com 25 fãs/h estimados. O recálculo do
--        Capacity de 02/11 em diante já conta a página.
--   2. Escala (5c_escalas): 1 posto titular por turno na U2 (25 fãs/h = 1 posto):
--      - T4/T5 (C), a partir de 02/11: dono o núcleo 2 da U2 · T4/T5, do Marcos
--        Vinicius Cid Mélo (prioridade da Privacy: time legado Felix Legion III), com o
--        Marcos como owner (vaga DONO); cobertura o núcleo 1;
--      - T6/T1 (D), a partir de 02/11 (o turno D de 02/11 começa às 21:00): dono o
--        núcleo 1, cobertura o núcleo 3;
--      - T2/T3 (A), a partir de 03/11 (o turno A de 02/11 é antes da entrada): dono o
--        núcleo 2, cobertura o núcleo 1.
--      Os núcleos de D e A são os mais indicados pela nota do Funil (mérito e carga).
--   O page_id vem do site da 5C (5c_pages.pages, pelo nome), para a postagem achar a página.
-- Depois: recalcular a semana de 02/11. Pode rodar de novo (não duplica).
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Capacity
INSERT INTO `5c_planning`.`fc_paginas_previstas` (pagina, modelo, categoria, tier, fas_h_prev, data_prevista, probabilidade, status, origem, obs)
SELECT 'Andressa Urach', 'andressaurach', 'PADRAO', 'C', 25, '2026-11-02', 1.00, 'CONFIRMADA', 'MANUAL', 'Privacy; entra 02/11 às 13:00 (T4/T5); site da 5C id 492 (ES_32)'
 WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_paginas_previstas` WHERE pagina = 'Andressa Urach');

INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT 'Andressa Urach', '2026-11-02', NULL, 'U2C2', 'andressaurach', 'PADRAO', 999, 25, 'Privacy; entra 02/11 às 13:00 (ES_32)'
 WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Andressa Urach');

-- 2. Escala
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es32;
CREATE TEMPORARY TABLE tmp_es32 (
  turno CHAR(1) NOT NULL PRIMARY KEY, desde DATE NOT NULL, dono_num TINYINT UNSIGNED NOT NULL, cob_num TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tmp_es32 VALUES ('C', '2026-11-02', 2, 1), ('D', '2026-11-02', 1, 3), ('A', '2026-11-03', 2, 1);

INSERT INTO esc_posto (versao, unidade, carteira, turno, seq, tipo, pagina_principal, n_paginas, assinatura, carga, capacidade, nucleo_dono_id, nucleo_cobertura_id, vigente_desde)
SELECT 'ES32_ANDRESSA', 2, 'U2C2', t.turno,
       (SELECT COALESCE(MAX(p.seq), 0) + 1 FROM esc_posto p WHERE p.carteira = 'U2C2' AND p.turno = t.turno COLLATE utf8mb4_unicode_ci),
       'titular', 'Andressa Urach', 1, 'Andressa Urach [titular]', 25.0, 35.0, nd.id, nc.id, t.desde
  FROM tmp_es32 t
  JOIN esc_nucleo nd ON nd.unidade = 2 AND nd.turno = t.turno COLLATE utf8mb4_unicode_ci AND nd.numero = t.dono_num AND nd.vigente_ate IS NULL
  JOIN esc_nucleo nc ON nc.unidade = 2 AND nc.turno = t.turno COLLATE utf8mb4_unicode_ci AND nc.numero = t.cob_num AND nc.vigente_ate IS NULL
 WHERE NOT EXISTS (SELECT 1 FROM esc_posto x WHERE x.versao = 'ES32_ANDRESSA' AND x.turno = t.turno COLLATE utf8mb4_unicode_ci);

INSERT INTO esc_posto_pagina (posto_id, pagina, page_id, fracao, fans_h, apoio)
SELECT p.id, 'Andressa Urach', (SELECT MAX(g.id) FROM `5c_pages`.`pages` g WHERE g.name = 'Andressa Urach' AND g.active = 1), 1, 25.0, 0
  FROM esc_posto p
 WHERE p.versao = 'ES32_ANDRESSA'
   AND NOT EXISTS (SELECT 1 FROM esc_posto_pagina pp WHERE pp.posto_id = p.id);
-- Se já tinha rodado sem o page_id, preenche agora.
UPDATE esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id
   SET pp.page_id = (SELECT MAX(g.id) FROM `5c_pages`.`pages` g WHERE g.name = 'Andressa Urach' AND g.active = 1)
 WHERE p.versao = 'ES32_ANDRESSA' AND pp.page_id IS NULL;

-- Owner do T4/T5: Marcos (prioridade da Privacy).
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem)
SELECT p.id, 'DONO', 'M137535', 1.000, 1, 'CONFIRMADA', 'Privacy: owner do time legado Felix Legion III (ES_32)', p.vigente_desde, 'TELA'
  FROM esc_posto p
 WHERE p.versao = 'ES32_ANDRESSA' AND p.turno = 'C'
   AND NOT EXISTS (SELECT 1 FROM esc_vaga_equipe v WHERE v.posto_id = p.id AND v.rep_code = 'M137535');

-- Conferência
SELECT p.id, p.turno, p.vigente_desde, CONCAT('U2 ', nd.turno, nd.numero, ' (OS ', nd.decurio_nome, ')') AS dono, CONCAT('U2 ', nc.turno, nc.numero) AS cobertura,
       (SELECT GROUP_CONCAT(CONCAT(v.vaga, ':', v.rep_code)) FROM esc_vaga_equipe v WHERE v.posto_id = p.id) AS equipe
  FROM esc_posto p JOIN esc_nucleo nd ON nd.id = p.nucleo_dono_id JOIN esc_nucleo nc ON nc.id = p.nucleo_cobertura_id
 WHERE p.versao = 'ES32_ANDRESSA' ORDER BY FIELD(p.turno, 'A', 'C', 'D');
SELECT 'Capacity: prevista' AS item, COUNT(*) AS tem, '1' AS esperado FROM `5c_planning`.`fc_paginas_previstas` WHERE pagina = 'Andressa Urach'
UNION ALL SELECT 'Capacity: carteira U2C2 desde 02/11', COUNT(*), '1' FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Andressa Urach' AND carteira = 'U2C2'
UNION ALL SELECT 'postos na escala (A, C, D)', COUNT(*), '3' FROM esc_posto WHERE versao = 'ES32_ANDRESSA'
UNION ALL SELECT 'postos com o page_id do site (492)', COUNT(*), '3' FROM esc_posto_pagina pp JOIN esc_posto p ON p.id = pp.posto_id WHERE p.versao = 'ES32_ANDRESSA' AND pp.page_id = 492
UNION ALL SELECT 'Marcos owner no T4/T5', COUNT(*), '1' FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id WHERE p.versao = 'ES32_ANDRESSA' AND v.rep_code = 'M137535';
DROP TEMPORARY TABLE IF EXISTS tmp_es32;
