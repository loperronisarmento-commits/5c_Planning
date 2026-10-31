-- =====================================================================
-- ES_34_solicitacao_pessoa_alvo.sql  (30/10)
-- Solicitações do SR: em "trabalhar como assistente" e "trabalhar em dupla" passa a
-- ficar registrado com quem ou onde a pessoa vai ficar fixa: o núcleo (unidade, turno
-- e OS, na coluna destino_nucleo_id que já existe) e a pessoa (titular que vai
-- assistir ou par da dupla), na coluna nova destino_rep.
-- Cria a coluna também no 5c_escalas_treino, se existir. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_solicitacao' AND COLUMN_NAME = 'destino_rep');
SET @sql := IF(@tem = 0, 'ALTER TABLE `5c_escalas`.`esc_solicitacao` ADD COLUMN destino_rep VARCHAR(30) NULL COMMENT ''pessoa: titular que vai assistir ou par da dupla'' AFTER destino_nucleo_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_solicitacao' AND COLUMN_NAME = 'destino_rep');
SET @tab := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_solicitacao');
SET @sql := IF(@tab = 1 AND @tem = 0, 'ALTER TABLE `5c_escalas_treino`.`esc_solicitacao` ADD COLUMN destino_rep VARCHAR(30) NULL COMMENT ''pessoa: titular que vai assistir ou par da dupla'' AFTER destino_nucleo_id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência
SELECT TABLE_SCHEMA, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'esc_solicitacao' AND COLUMN_NAME IN ('destino_nucleo_id', 'destino_rep') ORDER BY TABLE_SCHEMA, ORDINAL_POSITION;
