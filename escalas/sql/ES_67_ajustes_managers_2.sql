-- =====================================================================
-- ES_67_ajustes_managers_2.sql  (01/11)
-- Segunda leva de pedidos dos managers para a semana de 02/11:
--   - Rafaela Gaspar (R107827, U1 C) de férias de 03 a 11/11: indisponibilidade aprovada. O Nicolas
--     Kulik (N087021, U1 C) entra no lugar dela na Rocket Reyna (equipe fixa da página de 03 a 11/11).
--   - Rocket Reyna, turno D: a Riza Joy (R271485) entra no lugar da Angeline Butal (A283671); a
--     Angeline fica fora da Rocket Reyna (papel FORA, coluna nova) para ir a uma página maior.
--   - Sisi Rose sem assistente: o posto fixo (U1C2) passa de 2 para 1 pessoa. Depois, recalcular os
--     postos do Capacity (CALL abaixo, ou o "Gerar").
--   - Natasha Nice, T2/T3 (turno A): o Santiago Pereira (S094121) no lugar do Dennyson (D386725); o
--     Dennyson vai de assistente na Mikayla Free (equipe fixa e só assistente).
--   - Saídas: Rodrigo Felice (R119391) não está na empresa; Willian Rocha (W016277) e Julia Broggio
--     (J108431) pediram demissão em 01/11. Saem a configuração e as vagas fixas (como no ES_60).
-- Krystal (141m e 141t) e Jena Wolfy (VIP, Free e OFTV) com os mesmos SRs: é regra do motor (1.18.1),
-- a sobrecarga cobre as páginas da mesma modelo juntas.
-- Já aplicado em 01/11 pelo MCP (menos o CALL).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- Papel na página: ASSISTENTE (só assistente) ou FORA (não entra nela).
ALTER TABLE esc_pagina_assistente ADD COLUMN papel ENUM('ASSISTENTE', 'FORA') NOT NULL DEFAULT 'ASSISTENTE' AFTER rep_code;

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde) VALUES
  ('Rocket Reyna VIP', 'A283671', 'FORA', 'Manager: Angeline sai da Rocket Reyna (a Riza Joy entra), para usar numa página maior', '2026-11-02'),
  ('Rocket Reyna OFTV', 'A283671', 'FORA', 'Manager: Angeline sai da Rocket Reyna (a Riza Joy entra), para usar numa página maior', '2026-11-02'),
  ('Mikayla Free', 'D386725', 'ASSISTENTE', 'Manager: Dennyson de assistente na Mikayla Free', '2026-11-02');

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate) VALUES
  ('Rocket Reyna VIP', 'R271485', 'Manager: Riza Joy no lugar da Angeline Butal (turno D)', '2026-11-02', NULL),
  ('Rocket Reyna VIP', 'N087021', 'Manager: Nicolas Kulik no lugar da Rafaela Gaspar (férias de 03 a 11/11)', '2026-11-03', '2026-11-11'),
  ('Natasha Nice VIP', 'S094121', 'Manager: Santiago Pereira no lugar do Dennyson (T2/T3), muito melhor na Natasha', '2026-11-02', NULL),
  ('Mikayla Free', 'D386725', 'Manager: Dennyson de assistente na Mikayla Free', '2026-11-02', NULL);

-- Férias da Rafaela: 03 a 11/11, turno C, aprovadas.
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, urgente, status, solicitado_em, solicitado_por, decidido_em, comentario_decisao)
VALUES ('R107827', 'VARIOS_DIAS', 'WFM', 'PESSOAL', 'Férias pedidas à empresa (03 a 11/11)', 0, 'APROVADO', NOW(), 'PLANEJAMENTO', NOW(), 'ES_67: pedido dos managers');
SET @ind = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em)
SELECT @ind, DATE_ADD('2026-11-03', INTERVAL n DAY), 'C', NULL, 'APROVADO', NOW()
  FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8) d;

UPDATE `5c_planning`.`fc_posto_fixo` SET pessoas = 1, obs = LEFT(CONCAT('ES_67: sem assistente; ', IFNULL(obs, '')), 255)
 WHERE pagina = 'Sisi Rose' AND carteira = 'U1C2' AND vigente_ate IS NULL;

-- Saídas da empresa
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '565', '{"rep":"R119391","nucleo_id":26,"vagas":[1698,2145,2268]}', NULL, NULL),
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '532', '{"rep":"W016277","nucleo_id":26,"vagas":[939,1221,2105]}', NULL, NULL),
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '407', '{"rep":"J108431","config":[407,580],"nucleo_id":[18,15],"vagas":[1407,2067,2668]}', NULL, NULL);
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('R119391', 'W016277', 'J108431') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
DELETE FROM esc_colaborador_config WHERE id IN (565, 532, 407, 580) AND UPPER(rep_code) IN ('R119391', 'W016277', 'J108431');

-- Rodar no Navicat (o MCP não faz CALL):
-- CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'ES_67 Sisi sem assist');
