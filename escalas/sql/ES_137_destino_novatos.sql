SET NAMES utf8mb4;
-- =====================================================================
-- ES_137 · Destino dos novatos (09/11/2026)
--
-- Cada trainee de uma turma de treinamento ganha o destino depois do treinamento:
-- unidade e núcleo (no turno da turma). O sistema sugere onde ele preenche mais
-- lacunas (escala gerada) e mais falta (Capacity); o WFM confirma ou troca.
-- O cadastro em Colaboradores continua sendo o passo que põe a pessoa na escala.
--   situacao          TREINANDO ou DESISTIU (desistente não conta no destino nem na requisição)
--   unidade_destino   1, 2 ou 3
--   nucleo_destino_id esc_nucleo.id (unidade e turno da turma)
--   destino_motivo    por que essa unidade e esse núcleo (texto da sugestão ou de quem trocou)
--   destino_por/_em   quem confirmou e quando (NULL = ainda é só sugestão)
-- Roda no 5c_escalas e no 5c_escalas_treino. Só acrescenta colunas. Pode rodar de novo.
-- =====================================================================

USE `5c_escalas`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_treinamento_trainee' AND COLUMN_NAME = 'unidade_destino');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_treinamento_trainee
  ADD COLUMN situacao ENUM('TREINANDO','DESISTIU') NOT NULL DEFAULT 'TREINANDO' AFTER nome_social,
  ADD COLUMN unidade_destino TINYINT UNSIGNED NULL COMMENT 'unidade depois do treinamento' AFTER situacao,
  ADD COLUMN nucleo_destino_id INT UNSIGNED NULL COMMENT 'esc_nucleo.id depois do treinamento' AFTER unidade_destino,
  ADD COLUMN destino_motivo VARCHAR(255) NULL AFTER nucleo_destino_id,
  ADD COLUMN destino_por INT NULL AFTER destino_motivo,
  ADD COLUMN destino_em DATETIME NULL AFTER destino_por", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

USE `5c_escalas_treino`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_treinamento_trainee' AND COLUMN_NAME = 'unidade_destino');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_treinamento_trainee
  ADD COLUMN situacao ENUM('TREINANDO','DESISTIU') NOT NULL DEFAULT 'TREINANDO' AFTER nome_social,
  ADD COLUMN unidade_destino TINYINT UNSIGNED NULL AFTER situacao,
  ADD COLUMN nucleo_destino_id INT UNSIGNED NULL AFTER unidade_destino,
  ADD COLUMN destino_motivo VARCHAR(255) NULL AFTER nucleo_destino_id,
  ADD COLUMN destino_por INT NULL AFTER destino_motivo,
  ADD COLUMN destino_em DATETIME NULL AFTER destino_por", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

USE `5c_escalas`;
SELECT 'colunas no 5c_escalas (6)' AS item, COUNT(*) AS tem FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_treinamento_trainee' AND COLUMN_NAME IN ('situacao','unidade_destino','nucleo_destino_id','destino_motivo','destino_por','destino_em')
UNION ALL SELECT 'colunas no Modo Treino (6)', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_treinamento_trainee' AND COLUMN_NAME IN ('situacao','unidade_destino','nucleo_destino_id','destino_motivo','destino_por','destino_em')
UNION ALL SELECT 'trainees sem mudança (14)', COUNT(*) FROM esc_treinamento_trainee WHERE situacao = 'TREINANDO' AND unidade_destino IS NULL;
