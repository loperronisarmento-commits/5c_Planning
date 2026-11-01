-- =====================================================================
-- ES_57_configuracao_paginas.sql  (01/11)
-- Faturamento e páginas desconsideradas (decisões de 01/11):
--   1. esc_pagina_config (também no 5c_escalas_treino, se existir): a chave de cada
--      página na tela Páginas › Configuração das páginas.
--        AUTO          = desconsiderada só se tem cara de OFTV (OFTV no nome ou código
--                        terminado em t) e faturou menos que v4_trivial_receita_min
--                        nos 30 dias;
--        NORMAL        = nunca desconsiderada;
--        DESCONSIDERAR = sempre desconsiderada.
--      Página desconsiderada: sem SLA a cumprir, carga zero no posto e último lugar na
--      fila do "Gerar". O Capacity (script 27) lê a mesma tabela.
--   2. v4_receita_dias passa de 28 para 30 (faturamento dos últimos 30 dias, com as
--      vendas fechadas e as pendentes).
--   3. v4_trivial_receita_min = 8000 (US$ em 30 dias): a OFTV que fatura isso ou mais
--      vira posto normal.
-- Rodar antes do script 27 do Capacity. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

SET @ddl := 'CREATE TABLE IF NOT EXISTS `{schema}`.`esc_pagina_config` (
  pagina         VARCHAR(150) NOT NULL,
  sla_modo       ENUM(''AUTO'',''NORMAL'',''DESCONSIDERAR'') NOT NULL DEFAULT ''AUTO'',
  atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por INT NULL,
  PRIMARY KEY (pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Configuração das páginas: desconsiderar no SLA e na carga (ES_57)''';

SET @sql := REPLACE(@ddl, '{schema}', '5c_escalas'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @treino := (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '5c_escalas_treino');
SET @sql := IF(@treino = 1, REPLACE(@ddl, '{schema}', '5c_escalas_treino'), 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('v4_receita_dias', '30', 'INT', 'v4', 'Dias de faturamento (statement, fechado + pendente) que ordenam a escala; página com menos dias é emulada, sem venda usa o tier'),
  ('v4_trivial_receita_min', '8000', 'DECIMAL', 'v4', 'US$ em 30 dias: a página com cara de OFTV abaixo disto é desconsiderada (sem SLA, carga zero); a partir disto é posto normal')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- O site lê as faixas de tier em 5c_crm.tiers (página sem venda nenhuma). Se o usuário do
-- portal ainda não lê esse schema, o admin dá a permissão (troque usuário e host):
-- GRANT SELECT ON `5c_crm`.`tiers` TO '<usuario_do_portal>'@'<host>';

-- Conferência
SELECT chave, valor, tipo, grupo, descricao FROM esc_parametro WHERE chave IN ('v4_receita_dias', 'v4_trivial_receita_min') ORDER BY chave;
SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME = 'esc_pagina_config';
