-- ES_218 (24/11/2026): as moedas saíram do sistema (item A da revisão de 24/11, 1.38.0).
-- Apaga a coluna esc_sr_kpi.moedas (cache do KPI) no 5c_escalas e no 5c_escalas_treino.
-- O código da 1.38.0 (no ar desde 24/11 10:43) já não lê nem grava a coluna.
-- Os valores de antes ficaram em resultados/backup_2411/kpi_moedas/esc_sr_kpi_moedas.csv e no
-- backup completo resultados/backup_2411/5c_escalas_2411_antes_auditoria.sql.
SET NAMES utf8mb4;

-- Antes
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE COLUMN_NAME = 'moedas' AND TABLE_NAME = 'esc_sr_kpi' AND TABLE_SCHEMA IN ('5c_escalas', '5c_escalas_treino');

ALTER TABLE `5c_escalas`.`esc_sr_kpi` DROP COLUMN `moedas`;
ALTER TABLE `5c_escalas_treino`.`esc_sr_kpi` DROP COLUMN `moedas`;

-- Depois
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE COLUMN_NAME = 'moedas' AND TABLE_NAME = 'esc_sr_kpi' AND TABLE_SCHEMA IN ('5c_escalas', '5c_escalas_treino');
SELECT COUNT(*) AS linhas, MAX(calculado_em) AS ultimo FROM `5c_escalas`.`esc_sr_kpi`;
