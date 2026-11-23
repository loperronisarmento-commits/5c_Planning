SET NAMES utf8mb4;
-- =====================================================================
-- ES_198 · KPI no lugar do mérito na escalação (23/11, pedido do usuário; decisão D4 de contexto/regras_escalacao_kpi.md)
--   1. esc_sr_kpi: cache do KPI de cada SR (score, pilares, nível usado pelo Gerar), gravado a cada cálculo, nos dois
--      schemas (real e treino), como o esc_sr_merito.
--   2. Parâmetros (grupo v4):
--      - v4_fonte_desempenho = KPI: o Gerar, o Equilíbrio, o Funil, os Owners e as tags usam o KPI; MERITO volta ao mérito.
--      - v4_kpi_mov_max = 0.20: no máximo 20% dos SRs mudam de posto por causa do KPI na semana (D1, 80:20).
--      - v4_kpi_so_cobertura = 0.25: com score abaixo disto (e entre os 20% piores), o SR só faz cobertura (D2).
-- Via mysql.exe. Para desfazer: DROP TABLE esc_sr_kpi (nos dois schemas) e DELETE dos 3 parâmetros.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `5c_escalas`.`esc_sr_kpi` (
  `rep_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `semana_ref` date NOT NULL COMMENT 'segunda da última semana fechada usada',
  `kinetic_meta` decimal(10,4) DEFAULT NULL COMMENT 'meta ponderada (soma peso × realizado ÷ soma peso × meta)',
  `kinetic` decimal(6,4) DEFAULT NULL,
  `process` decimal(6,4) DEFAULT NULL,
  `integrity` decimal(6,4) DEFAULT NULL,
  `score` decimal(6,4) DEFAULT NULL COMMENT 'o que vale (75% provisório para o novato)',
  `score_calculado` decimal(6,4) DEFAULT NULL,
  `provisorio` tinyint NOT NULL DEFAULT 0,
  `pareto` tinyint NOT NULL DEFAULT 0 COMMENT 'entre os 20% de menor score',
  `nivel` tinyint NOT NULL DEFAULT 2 COMMENT '0 a 4 (contexto/regras_escalacao_kpi.md, K-01)',
  `moedas` decimal(10,2) DEFAULT NULL,
  `anos` smallint DEFAULT NULL COMMENT 'anos de casa (desempate)',
  `calculado_em` datetime NOT NULL,
  PRIMARY KEY (`rep_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='KPI dos SRs (cache, recalculado a cada 3 h e no Gerar)';

CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_sr_kpi` LIKE `5c_escalas`.`esc_sr_kpi`;

USE `5c_escalas`;
SELECT 'antes' AS momento, chave, valor FROM esc_parametro WHERE chave IN ('v4_fonte_desempenho', 'v4_kpi_mov_max', 'v4_kpi_so_cobertura');
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao, atualizado_em) VALUES
  ('v4_fonte_desempenho', 'KPI', 'TEXTO', 'v4', 'Desempenho usado pelo Gerar, Equilíbrio, Funil, Owners e tags: KPI (desde 1.34.0) ou MERITO (nota em pontos)', NOW()),
  ('v4_kpi_mov_max', '0.20', 'DECIMAL', 'v4', 'KPI (D1, 80:20): no máximo esta fração dos SRs muda de posto por causa do KPI na semana', NOW()),
  ('v4_kpi_so_cobertura', '0.25', 'DECIMAL', 'v4', 'KPI (D2): score abaixo disto, entre os 20% piores, só faz cobertura (nunca regular em posto)', NOW())
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);
SELECT 'depois' AS momento, chave, valor FROM esc_parametro WHERE chave IN ('v4_fonte_desempenho', 'v4_kpi_mov_max', 'v4_kpi_so_cobertura');
SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME = 'esc_sr_kpi';
