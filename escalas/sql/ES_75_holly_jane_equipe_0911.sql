SET NAMES utf8mb4;
-- =====================================================================
-- ES_75_holly_jane_equipe_0911.sql  (02/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: a Holly Jane (U1C2) fica com uma equipe fixa: Angeline Butal, Krizel,
-- Alan Ramsay, João Beber e Stephen. Outras pessoas só entram se ninguém dela estiver disponível.
-- O motor faz isso pela esc_pagina_prioridade, mas só com quem está na unidade e no turno da
-- página.
-- Dias na Holly Jane em 8 semanas: Alan 44, João 34, Stephen 29, Krizel 20, Angeline 0.
-- "ngel" foi lido como Angeline Butal porque ela é da U1, turno D, como o resto do grupo.
--
-- A partir de 09/11, no mesmo turno e nível:
--   K290130 Krizel      U3 → U1  D  núcleo 2 (dono da Holly Jane no D)
--   S799456 Stephen     U2 → U1  D  núcleo 2
--   J145594 João Beber  U2 → U1  D  núcleo 2 (ia para a U3 pelo ES_73; o destino muda)
--   A051183 Alan Ramsay U2 → U1  A  núcleo 1 (dono da Holly Jane no A)
-- Compensação, para a cobertura das unidades não mudar (quem conhece mais o destino):
--   K616148  U1 → U3  D  nível 3 (no lugar da Krizel)
--   O153520  U1 → U3  D  nível 1 (no lugar do João, que era a compensação do ES_73 na U3)
--   P155379  U1 → U2  D  nível 1 (no lugar do Stephen)
--   C144167  U1 → U2  A  nível 3 (no lugar do Alan; tinha ido para a U1 pela Nicole no ES_52)
-- Owners e backups da Holly Jane a partir de 09/11:
--   D: donos João, Stephen e Krizel; backup Angeline.
--   A: dono Alan, no lugar do M022236.
-- Pode rodar de novo (não duplica).
-- =====================================================================

USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es75;
CREATE TEMPORARY TABLE tmp_es75 (
  rep_code VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  de TINYINT UNSIGNED NOT NULL,
  unidade TINYINT UNSIGNED NOT NULL,
  turno CHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  numero TINYINT UNSIGNED NOT NULL,
  motivo VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO tmp_es75 (rep_code, de, unidade, turno, numero, motivo) VALUES
  ('K290130', 3, 1, 'D', 2, 'equipe fixa da Holly Jane'),
  ('S799456', 2, 1, 'D', 2, 'equipe fixa da Holly Jane'),
  ('A051183', 2, 1, 'A', 1, 'equipe fixa da Holly Jane'),
  ('K616148', 1, 3, 'D', 2, 'compensação da equipe da Holly Jane'),
  ('O153520', 1, 3, 'D', 2, 'compensação da equipe da Holly Jane'),
  ('P155379', 1, 2, 'D', 2, 'compensação da equipe da Holly Jane'),
  ('C144167', 1, 2, 'A', 2, 'compensação da equipe da Holly Jane');

START TRANSACTION;

-- João Beber: o cadastro de 09/11 do ES_73 (U3) passa para a U1.
UPDATE esc_colaborador_config c
  JOIN esc_nucleo n ON n.unidade = 1 AND n.turno = 'D' AND n.numero = 2
                   AND n.vigente_desde <= '2026-11-09' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-09')
   SET c.unidade = 1, c.nucleo_id = n.id,
       c.observacao = 'Equipe fixa da Holly Jane (ES_75): U2 → U1 núcleo 2 a partir de 09/11/2026 (antes ES_73: U3)'
 WHERE UPPER(c.rep_code) = 'J145594' AND c.vigente_desde = '2026-11-09' AND c.funcao = 'EQUITE';

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-09', NULL,
       LEFT(CONCAT('Equipe da Holly Jane (ES_75): U', x.de, ' → U', x.unidade, ' núcleo ', x.numero, ' a partir de 09/11/2026; ', x.motivo), 255), 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c
  JOIN tmp_es75 x ON x.rep_code = UPPER(c.rep_code)
  JOIN esc_nucleo n ON n.unidade = x.unidade AND n.turno = x.turno AND n.numero = x.numero
                   AND n.vigente_desde <= '2026-11-09' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-09')
 WHERE c.funcao = 'EQUITE' AND c.unidade = x.de AND c.turno = x.turno
   AND c.vigente_desde < '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09')
   AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE UPPER(f.rep_code) = x.rep_code AND f.vigente_desde >= '2026-11-09');

UPDATE esc_colaborador_config c
  JOIN tmp_es75 x ON x.rep_code = UPPER(c.rep_code)
   SET c.vigente_ate = '2026-11-08'
 WHERE c.vigente_desde < '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09')
   AND EXISTS (SELECT 1 FROM (SELECT DISTINCT UPPER(rep_code) AS rep FROM esc_colaborador_config WHERE vigente_desde = '2026-11-09' AND observacao LIKE 'Equipe da Holly Jane (ES_75)%') f
                WHERE f.rep = x.rep_code);

-- Vagas fixas na unidade de origem fecham em 08/11.
UPDATE esc_vaga_equipe v
  JOIN tmp_es75 x ON x.rep_code = UPPER(v.rep_code)
  JOIN esc_posto p ON p.id = v.posto_id AND p.unidade = x.de
   SET v.vigente_ate = '2026-11-08', v.motivo = LEFT(CONCAT('Equipe da Holly Jane (ES_75): foi para a U', x.unidade, '; ', COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde < '2026-11-09' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09');

-- Owners e backups da Holly Jane: as vagas de hoje no D, e a do M022236 no A, fecham em 08/11.
UPDATE esc_vaga_equipe v
  JOIN esc_posto p ON p.id = v.posto_id AND p.vigente_ate IS NULL
  JOIN esc_posto_pagina pp ON pp.posto_id = p.id AND pp.pagina = 'Holly Jane'
   SET v.vigente_ate = '2026-11-08', v.motivo = LEFT(CONCAT('Equipe fixa da Holly Jane (ES_75); ', COALESCE(v.motivo, '')), 255)
 WHERE (p.turno = 'D' OR (p.turno = 'A' AND UPPER(v.rep_code) = 'M022236'))
   AND UPPER(v.rep_code) NOT IN ('J145594', 'S799456', 'K290130', 'A283671', 'A051183')
   AND v.vigente_desde < '2026-11-09' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09');

INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT p.id, x.vaga, x.rep, x.fatia, x.ordem, 'CONFIRMADA', 'Equipe fixa da Holly Jane (ES_75)', '2026-11-09', NULL, 'TELA'
  FROM esc_posto p
  JOIN esc_posto_pagina pp ON pp.posto_id = p.id AND pp.pagina = 'Holly Jane'
  JOIN (SELECT 'D' AS turno, 'DONO' AS vaga, 'J145594' AS rep, 0.400 AS fatia, 1 AS ordem
        UNION ALL SELECT 'D', 'DONO', 'S799456', 0.400, 2
        UNION ALL SELECT 'D', 'DONO', 'K290130', 0.200, 3
        UNION ALL SELECT 'D', 'COBERTURA', 'A283671', 1.000, 1
        UNION ALL SELECT 'A', 'DONO', 'A051183', 0.600, 1) x ON x.turno = p.turno
 WHERE p.vigente_ate IS NULL
   AND NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = p.id AND UPPER(e.rep_code) = x.rep AND e.vaga = x.vaga AND e.vigente_desde = '2026-11-09');

-- Equipe fixa: o motor dá a página a eles antes de qualquer outro SR da unidade e do turno.
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Holly Jane', 'A051183,J145594,S799456,K290130,A283671', 'Manager: equipe fixa da Holly Jane (Angeline, Krizel, Alan Ramsay, João Beber e Stephen); outras pessoas só se ninguém dela estiver disponível', '2026-11-09', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Holly Jane' AND vigente_desde = '2026-11-09');

COMMIT;

-- Conferência
SELECT x.rep_code, b.unidade AS para_u, bn.numero AS nucleo, b.turno, b.nivel
  FROM (SELECT rep_code FROM tmp_es75 UNION ALL SELECT 'J145594') x
  LEFT JOIN esc_colaborador_config b ON UPPER(b.rep_code) = x.rep_code AND b.vigente_desde <= '2026-11-09' AND (b.vigente_ate IS NULL OR b.vigente_ate >= '2026-11-09')
  LEFT JOIN esc_nucleo bn ON bn.id = b.nucleo_id
 ORDER BY b.turno, x.rep_code;

SELECT p.turno, v.vaga, v.rep_code, v.fatia, v.ordem
  FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id AND p.vigente_ate IS NULL
  JOIN esc_posto_pagina pp ON pp.posto_id = p.id AND pp.pagina = 'Holly Jane'
 WHERE v.vigente_desde <= '2026-11-09' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09')
 ORDER BY p.turno, v.vaga DESC, v.ordem;

DROP TEMPORARY TABLE IF EXISTS tmp_es75;

-- Ajuste aplicado depois, pelo MCP: o Stephen (S799456) já tinha mudança marcada para 23/11 (ES_48,
-- U2 D → U1 C), e por isso o INSERT acima não criou o cadastro de 09/11. Ele fica na U1 D de 09/11 a
-- 22/11; a partir de 23/11 segue o ES_48 na U1 C e é dono da Holly Jane no T4/T5.
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, 1, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora, c.folgas_fixas, c.pode_assistente, '2026-11-09', '2026-11-22',
       'Equipe da Holly Jane (ES_75): U2 → U1 núcleo 2 de 09/11 a 22/11/2026; a partir de 23/11 segue o ES_48 (U1 C)', 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c
  JOIN esc_nucleo n ON n.unidade = 1 AND n.turno = 'D' AND n.numero = 2 AND n.vigente_desde <= '2026-11-09' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-09')
 WHERE c.id = 515 AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = 'S799456' AND f.vigente_desde = '2026-11-09');
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 515;
UPDATE esc_vaga_equipe v JOIN esc_posto_pagina pp ON pp.posto_id = v.posto_id AND pp.pagina = 'Holly Jane' JOIN esc_posto p ON p.id = v.posto_id AND p.turno = 'D'
   SET v.vigente_ate = '2026-11-22' WHERE v.rep_code = 'S799456' AND v.vigente_desde = '2026-11-09';
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT p.id, 'DONO', 'S799456', 0.400, 2, 'CONFIRMADA', 'Equipe fixa da Holly Jane (ES_75): no T4/T5 a partir de 23/11 (ES_48)', '2026-11-23', NULL, 'TELA'
  FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id AND pp.pagina = 'Holly Jane'
 WHERE p.turno = 'C' AND p.vigente_ate IS NULL
   AND NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = p.id AND e.rep_code = 'S799456' AND e.vigente_desde = '2026-11-23');
