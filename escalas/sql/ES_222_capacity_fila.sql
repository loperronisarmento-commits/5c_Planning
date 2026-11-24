-- ES_222 (24/11/2026): Capacity automático por fila (item D da revisão de 24/11).
-- As telas do 5C Escalas gravam um pedido de recálculo em esc_capacity_fila na mesma transação da
-- mudança (pessoas, páginas, postos). Se a mudança volta atrás, o pedido também volta. O evento
-- ev_esc_capacity_fila roda a cada 2 minutos e, se houver pedido, chama sp_fc_recalcular_postos de hoje
-- uma vez só, com os motivos juntos, usando a mesma trava do Gerar (5c_escalas.gerar): se um Gerar
-- estiver rodando, tenta de novo na próxima volta. O usuário não espera o recálculo.
-- Rodar pelo mysql.exe (tem DELIMITER).
SET NAMES utf8mb4;
USE `5c_escalas`;

-- Antes
SELECT COUNT(*) AS existe FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_capacity_fila';

CREATE TABLE IF NOT EXISTS `esc_capacity_fila` (
  `id` int NOT NULL AUTO_INCREMENT,
  `motivo` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ação do esc_log que pediu o recálculo',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `criado_por` int DEFAULT NULL,
  `processado_em` datetime DEFAULT NULL,
  `versao` int DEFAULT NULL COMMENT 'versão do Capacity gravada pelo recálculo',
  `erro` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fila_pendente` (`processado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pedidos de recálculo do Capacity (1.38.0)';
CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_capacity_fila` LIKE `5c_escalas`.`esc_capacity_fila`;

DROP PROCEDURE IF EXISTS esc_sp_capacity_fila;
DELIMITER $$
CREATE PROCEDURE esc_sp_capacity_fila()
BEGIN
  DECLARE v_ate INT;
  DECLARE v_motivos VARCHAR(200);
  DECLARE v_versao INT;
  DECLARE v_erro VARCHAR(255);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
  END;
  SELECT MAX(id) INTO v_ate FROM esc_capacity_fila WHERE processado_em IS NULL;
  IF v_ate IS NOT NULL AND GET_LOCK('5c_escalas.gerar', 0) = 1 THEN
    SELECT LEFT(GROUP_CONCAT(DISTINCT motivo ORDER BY motivo SEPARATOR ', '), 200) INTO v_motivos
      FROM esc_capacity_fila WHERE processado_em IS NULL AND id <= v_ate;
    CALL `5c_planning`.sp_fc_recalcular_postos(CURDATE(), LEFT(CONCAT('Automático (5C Escalas): ', v_motivos), 255));
    SELECT MAX(versao) INTO v_versao FROM `5c_planning`.fc_capacity_versao;
    UPDATE esc_capacity_fila SET processado_em = NOW(), versao = IF(v_erro IS NULL, v_versao, NULL), erro = v_erro
     WHERE processado_em IS NULL AND id <= v_ate;
    DO RELEASE_LOCK('5c_escalas.gerar');
  END IF;
  DELETE FROM esc_capacity_fila WHERE processado_em < NOW() - INTERVAL 60 DAY;
END$$
DELIMITER ;

DROP EVENT IF EXISTS ev_esc_capacity_fila;
CREATE EVENT ev_esc_capacity_fila ON SCHEDULE EVERY 2 MINUTE STARTS NOW()
  COMMENT 'Recálculo automático do Capacity pedido pelo 5C Escalas (ES_222)'
  DO CALL esc_sp_capacity_fila();

-- Depois
SELECT COUNT(*) AS existe FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_capacity_fila';
SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '5c_escalas';
