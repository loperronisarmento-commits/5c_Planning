-- ES_219 (24/11/2026): remove as tabelas e a procedure do Bot de cobrança (item B da revisão de 24/11).
-- Rodou em 24/11 com a confirmação do usuário (antes: 36 e 3.162 linhas, iguais às exportações). O código saiu na 1.38.0.
-- Dados exportados antes em resultados/backup_2411/bot_cobranca/:
--   1_esc_bot_inativ_semana.csv (36 linhas), 2_esc_bot_inativ_rep.csv (3.162 linhas, com o nome),
--   3_sla_por_semana_turno.csv, 4_alertas_por_semana_tipo.csv, 5_procedure_esc_sp_bot_inatividade.sql
--   e 6_relatorio_tela_bot_cobranca.html. Backup completo do schema em resultados/backup_2411/.
-- 5c_bot.alerta_historico é do bot (outro sistema) e não é tocada.
SET NAMES utf8mb4;

-- Antes
SELECT 'esc_bot_inativ_semana' AS tabela, COUNT(*) AS linhas FROM `5c_escalas`.esc_bot_inativ_semana
UNION ALL SELECT 'esc_bot_inativ_rep', COUNT(*) FROM `5c_escalas`.esc_bot_inativ_rep;

DROP PROCEDURE IF EXISTS `5c_escalas`.esc_sp_bot_inatividade;
DROP TABLE IF EXISTS `5c_escalas`.esc_bot_inativ_rep;
DROP TABLE IF EXISTS `5c_escalas`.esc_bot_inativ_semana;

-- Depois: nada sobra
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME LIKE 'esc_bot%';
SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '5c_escalas' AND ROUTINE_NAME LIKE '%bot%';
