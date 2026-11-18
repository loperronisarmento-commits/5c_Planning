SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_41_sla_15min.sql  (18/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
--
-- SLA 15 por SR e por página, para o KPI (Kinetic, Process & Integrity), que usa SLA 5, 10 e 15.
--   1. cap_rep_hora e cap_pagina_hora ganham resp_15min: respostas em até 15 minutos (replay_seconds <= 900),
--      ao lado de resp_5min e resp_10min. Nada muda nas colunas que já existiam.
--   2. sp_cap_carga_dia passa a gravar resp_15min; o resto da carga fica igual.
--   3. Recarrega os dias de 01/11 a 17/11 (o corte de dados do sistema é 01/11). Os dias anteriores ficam com
--      resp_15min nulo. O evento ev_cap_carga_diaria continua igual e preenche os dias seguintes.
-- Para desfazer: voltar o procedimento sem resp_15min e ALTER TABLE ... DROP COLUMN resp_15min nas duas tabelas.
-- =====================================================================

USE `5c_capacity`;

SELECT 'antes' AS momento, COUNT(*) AS linhas_rep, SUM(respostas) AS respostas, SUM(resp_5min) AS r5, SUM(resp_10min) AS r10
  FROM cap_rep_hora WHERE dia >= '2026-11-01' AND dia <= '2026-11-17';

ALTER TABLE cap_rep_hora    ADD COLUMN resp_15min INT NULL AFTER resp_10min;
ALTER TABLE cap_pagina_hora ADD COLUMN resp_15min INT NULL AFTER resp_10min;

DROP PROCEDURE IF EXISTS sp_cap_carga_dia;
DELIMITER $$
CREATE PROCEDURE sp_cap_carga_dia(IN p_dia DATE)
BEGIN
  DECLARE v_ini   DATETIME DEFAULT CAST(p_dia AS DATETIME);
  DECLARE v_fim   DATETIME DEFAULT CAST(p_dia AS DATETIME) + INTERVAL 1 DAY;
  DECLARE v_t0    DATETIME DEFAULT NOW();
  DECLARE v_pag   INT DEFAULT 0;
  DECLARE v_rep   INT DEFAULT 0;
  DECLARE v_lock  INT DEFAULT 0;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 @cap_err = MESSAGE_TEXT;
    ROLLBACK;
    IF v_lock = 1 THEN DO RELEASE_LOCK('5c_capacity_carga'); END IF;
    INSERT INTO `5c_capacity`.cap_carga_log (dia, inicio, fim, status, mensagem) VALUES (p_dia, v_t0, NOW(), 'ERRO', LEFT(@cap_err, 500));
  END;

  -- Serializa as cargas: se outra carga (manual ou o evento) estiver rodando, espera até 15 min.
  SET v_lock = GET_LOCK('5c_capacity_carga', 900);
  IF v_lock IS NULL OR v_lock = 0 THEN
    INSERT INTO `5c_capacity`.cap_carga_log (dia, inicio, fim, status, mensagem)
    VALUES (p_dia, v_t0, NOW(), 'ERRO', 'Outra carga em andamento: lock nao obtido em 15 min');
  ELSE

  SET SESSION group_concat_max_len = 4096;
  START TRANSACTION;

  DELETE FROM `5c_capacity`.cap_pagina_hora WHERE dia = p_dia;
  DELETE FROM `5c_capacity`.cap_rep_hora    WHERE dia = p_dia;

  INSERT INTO `5c_capacity`.cap_pagina_hora (dia, hora, pagina, fas_unicos, msgs_rep, respostas, resp_5min, resp_10min, resp_15min,
                               reps_distintos, valor_ofertado, valor_comprado)
  SELECT p_dia, HOUR(m.DATE_TIME), m.CREATOR,
         COUNT(DISTINCT m.SENT_TO), COUNT(*),
         SUM(m.replay_seconds IS NOT NULL), SUM(CASE WHEN m.replay_seconds <= 300 THEN 1 ELSE 0 END), SUM(CASE WHEN m.replay_seconds <= 600 THEN 1 ELSE 0 END),
         SUM(CASE WHEN m.replay_seconds <= 900 THEN 1 ELSE 0 END),
         COUNT(DISTINCT m.SENDER),
         SUM(CASE WHEN m.PRICE > 0 THEN m.PRICE ELSE 0 END),
         SUM(CASE WHEN m.PRICE > 0 AND m.PURCHASED = 'yes' THEN m.PRICE ELSE 0 END)
  FROM `5c_wfm_db`.msgdashboard m
  WHERE m.DATE_TIME >= v_ini AND m.DATE_TIME < v_fim
    AND (m.SOURCE IS NULL OR m.SOURCE = 'Employee')
    AND m.CREATOR IS NOT NULL
  GROUP BY HOUR(m.DATE_TIME), m.CREATOR;
  SET v_pag = ROW_COUNT();

  INSERT INTO `5c_capacity`.cap_rep_hora (dia, hora, rep, n_paginas, paginas, fas_unicos, msgs_rep, respostas, resp_5min, resp_10min, resp_15min,
                            valor_ofertado, valor_comprado, minuto_ini, minuto_fim)
  SELECT p_dia, HOUR(m.DATE_TIME), m.SENDER,
         LEAST(COUNT(DISTINCT m.CREATOR), 127),
         LEFT(GROUP_CONCAT(DISTINCT m.CREATOR ORDER BY m.CREATOR SEPARATOR ' + '), 500),
         COUNT(DISTINCT CONCAT(m.CREATOR, '|', m.SENT_TO)), COUNT(*),
         SUM(m.replay_seconds IS NOT NULL), SUM(CASE WHEN m.replay_seconds <= 300 THEN 1 ELSE 0 END), SUM(CASE WHEN m.replay_seconds <= 600 THEN 1 ELSE 0 END),
         SUM(CASE WHEN m.replay_seconds <= 900 THEN 1 ELSE 0 END),
         SUM(CASE WHEN m.PRICE > 0 THEN m.PRICE ELSE 0 END),
         SUM(CASE WHEN m.PRICE > 0 AND m.PURCHASED = 'yes' THEN m.PRICE ELSE 0 END),
         MIN(MINUTE(m.DATE_TIME)), MAX(MINUTE(m.DATE_TIME))
  FROM `5c_wfm_db`.msgdashboard m
  WHERE m.DATE_TIME >= v_ini AND m.DATE_TIME < v_fim
    AND (m.SOURCE IS NULL OR m.SOURCE = 'Employee')
    AND m.SENDER IS NOT NULL AND m.CREATOR IS NOT NULL
  GROUP BY HOUR(m.DATE_TIME), m.SENDER;
  SET v_rep = ROW_COUNT();

  COMMIT;
  INSERT INTO `5c_capacity`.cap_carga_log (dia, inicio, fim, status, linhas_pagina, linhas_rep)
  VALUES (p_dia, v_t0, NOW(), 'OK', v_pag, v_rep);
  DO RELEASE_LOCK('5c_capacity_carga');

  END IF;
END$$
DELIMITER ;

-- Recarga de 01/11 a 17/11 (cada dia apaga e grava de novo a partir das mensagens).
CALL sp_cap_carga_dia('2026-11-01'); CALL sp_cap_carga_dia('2026-11-02'); CALL sp_cap_carga_dia('2026-11-03');
CALL sp_cap_carga_dia('2026-11-04'); CALL sp_cap_carga_dia('2026-11-05'); CALL sp_cap_carga_dia('2026-11-06');
CALL sp_cap_carga_dia('2026-11-07'); CALL sp_cap_carga_dia('2026-11-08'); CALL sp_cap_carga_dia('2026-11-09');
CALL sp_cap_carga_dia('2026-11-10'); CALL sp_cap_carga_dia('2026-11-11'); CALL sp_cap_carga_dia('2026-11-12');
CALL sp_cap_carga_dia('2026-11-13'); CALL sp_cap_carga_dia('2026-11-14'); CALL sp_cap_carga_dia('2026-11-15');
CALL sp_cap_carga_dia('2026-11-16'); CALL sp_cap_carga_dia('2026-11-17');

SELECT dia, status, linhas_rep, mensagem FROM cap_carga_log WHERE dia >= '2026-11-01' AND inicio >= CURDATE() ORDER BY dia, inicio;

SELECT 'depois' AS momento, COUNT(*) AS linhas_rep, SUM(respostas) AS respostas, SUM(resp_5min) AS r5, SUM(resp_10min) AS r10, SUM(resp_15min) AS r15,
       SUM(resp_15min IS NULL) AS sem_r15
  FROM cap_rep_hora WHERE dia >= '2026-11-01' AND dia <= '2026-11-17';
