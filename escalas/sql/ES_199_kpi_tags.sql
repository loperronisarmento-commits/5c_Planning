SET NAMES utf8mb4;
-- =====================================================================
-- ES_199 · Dados do KPI para as tags da Grade (23/11, junto com o ES_198; contexto/regras_escalacao_kpi.md, seção 5)
--   esc_sr_kpi ganha faltas, SLA 5, quebras de P&G e semanas seguidas batendo a meta (das 4 semanas fechadas), para as
--   tags ABS, SLA, P&G, KPI ↑/↓ e Seq. meta saírem do KPI, e não mais do cache do mérito. Nos dois schemas. Via mysql.exe.
-- =====================================================================

ALTER TABLE `5c_escalas`.`esc_sr_kpi`
  ADD COLUMN `faltas` smallint DEFAULT NULL COMMENT 'dias com falta nas 4 semanas' AFTER `moedas`,
  ADD COLUMN `sla5` decimal(6,4) DEFAULT NULL COMMENT 'respostas em até 5 min ÷ respostas, nas 4 semanas' AFTER `faltas`,
  ADD COLUMN `pg` smallint DEFAULT NULL COMMENT 'quebras de P&G (avaliações Não Conforme) nas 4 semanas' AFTER `sla5`,
  ADD COLUMN `semanas_meta` smallint DEFAULT NULL COMMENT 'semanas fechadas seguidas com 100% ou mais da meta' AFTER `pg`;

ALTER TABLE `5c_escalas_treino`.`esc_sr_kpi`
  ADD COLUMN `faltas` smallint DEFAULT NULL AFTER `moedas`,
  ADD COLUMN `sla5` decimal(6,4) DEFAULT NULL AFTER `faltas`,
  ADD COLUMN `pg` smallint DEFAULT NULL AFTER `sla5`,
  ADD COLUMN `semanas_meta` smallint DEFAULT NULL AFTER `pg`;

SELECT TABLE_SCHEMA, COUNT(*) AS colunas FROM information_schema.COLUMNS WHERE TABLE_NAME = 'esc_sr_kpi' GROUP BY TABLE_SCHEMA;
