-- 5C_Planning_43 (24/11/2026): nova tentativa do diário do Capacity (A-03 da auditoria de 24/11).
-- O diário (ev_fc_forecast_diario, 06:30) às vezes cai por erro do banco ("Got an error writing
-- communication packets", 20 e 22/11) e ninguém refazia. Este evento roda às 07:00 e às 08:00 e só chama
-- sp_fc_rodar_dia() de novo se a última execução de hoje terminou em ERRO. Se a de 06:30 deu certo, não faz nada.
-- O Plano de Ação do 5C Escalas mostra um alerta enquanto o diário de hoje estiver com erro.
-- Rodar pelo mysql.exe (tem DELIMITER).
SET NAMES utf8mb4;
USE `5c_planning`;

-- Antes
SELECT EVENT_NAME, STATUS, STARTS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '5c_planning';

DROP EVENT IF EXISTS ev_fc_forecast_diario_retry_07;
DROP EVENT IF EXISTS ev_fc_forecast_diario_retry_08;
DROP PROCEDURE IF EXISTS sp_fc_rodar_dia_se_falhou;
DELIMITER $$
CREATE PROCEDURE sp_fc_rodar_dia_se_falhou()
BEGIN
  DECLARE v_status VARCHAR(20);
  SELECT status INTO v_status FROM fc_log_execucao WHERE DATE(inicio) = CURDATE() ORDER BY id DESC LIMIT 1;
  IF v_status = 'ERRO' THEN
    CALL sp_fc_rodar_dia();
  END IF;
END$$
DELIMITER ;

CREATE EVENT ev_fc_forecast_diario_retry_07 ON SCHEDULE EVERY 1 DAY STARTS (CURDATE() + INTERVAL 1 DAY + INTERVAL 7 HOUR)
  COMMENT 'Nova tentativa do diário às 07:00, só se o de hoje falhou (5C_Planning_43)'
  DO CALL sp_fc_rodar_dia_se_falhou();
CREATE EVENT ev_fc_forecast_diario_retry_08 ON SCHEDULE EVERY 1 DAY STARTS (CURDATE() + INTERVAL 1 DAY + INTERVAL 8 HOUR)
  COMMENT 'Nova tentativa do diário às 08:00, só se o de hoje falhou (5C_Planning_43)'
  DO CALL sp_fc_rodar_dia_se_falhou();

-- Depois
SELECT EVENT_NAME, STATUS, STARTS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = '5c_planning';
