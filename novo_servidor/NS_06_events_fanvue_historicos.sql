-- =====================================================================
-- NS_06 · Events do Fanvue e dos históricos (rodar no .11, depois de
--         conferir a primeira carga do NS_05 com o NS_07)
-- ---------------------------------------------------------------------
-- Históricos às :04, :14, :24...  Fanvue às :05, :15, :25...
-- (o headcount e as páginas do NS_03 rodam às :02 e :07).
-- =====================================================================

DROP EVENT IF EXISTS `5c_wfm_db`.`ev_sync_historicos_novo_servidor`;

DELIMITER $$
CREATE EVENT `5c_wfm_db`.`ev_sync_historicos_novo_servidor`
  ON SCHEDULE EVERY 10 MINUTE
  STARTS (CURRENT_DATE + INTERVAL 4 MINUTE)
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Copia headcount_history e paginas_history do servidor novo 209.159.144.7 (NS_05)'
  DO BEGIN
    CALL `5c_headcount`.`sp_sync_headcount_history_novo_servidor`();
    CALL `5c_pages`.`sp_sync_paginas_history_novo_servidor`();
  END$$
DELIMITER ;

DROP EVENT IF EXISTS `5c_wfm_db`.`ev_sync_fanvue_novo_servidor`;
CREATE EVENT `5c_wfm_db`.`ev_sync_fanvue_novo_servidor`
  ON SCHEDULE EVERY 10 MINUTE
  STARTS (CURRENT_DATE + INTERVAL 5 MINUTE)
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Copia vendas Fanvue novas do servidor novo 209.159.144.7 (NS_05)'
  DO CALL `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`(NULL);

SELECT EVENT_SCHEMA, EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, LAST_EXECUTED
  FROM information_schema.EVENTS
 WHERE EVENT_NAME IN ('ev_sync_historicos_novo_servidor', 'ev_sync_fanvue_novo_servidor');

-- Para pausar sem apagar:
-- ALTER EVENT `5c_wfm_db`.`ev_sync_historicos_novo_servidor` DISABLE;
-- ALTER EVENT `5c_wfm_db`.`ev_sync_fanvue_novo_servidor` DISABLE;
