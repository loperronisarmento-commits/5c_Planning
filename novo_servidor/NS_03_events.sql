-- =====================================================================
-- NS_03 · Events de 10 em 10 min (rodar no .11, depois de conferir a
--         primeira carga com o NS_04)
-- ---------------------------------------------------------------------
-- Headcount às :02, :12, :22...  Páginas às :07, :17, :27...
-- (desencontrados entre si e do etl_auto, que roda a cada 15 min).
-- O event_scheduler do .11 já está ON (os events ev_sync_teste_privacy e
-- ev_carga_privacy_statements usam).
--
-- Junto com isto, tire HeadCount e Pages do etl_auto (pasta etl_auto/
-- deste diretório), senão a API antiga volta a escrever nas mesmas tabelas.
-- =====================================================================

DROP EVENT IF EXISTS `5c_headcount`.`ev_sync_headcount_novo_servidor`;
CREATE EVENT `5c_headcount`.`ev_sync_headcount_novo_servidor`
  ON SCHEDULE EVERY 10 MINUTE
  STARTS (CURRENT_DATE + INTERVAL 2 MINUTE)
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Copia o headcount do servidor novo 209.159.144.7 (NS_02)'
  DO CALL `5c_headcount`.`sp_sync_headcount_novo_servidor`();

DROP EVENT IF EXISTS `5c_pages`.`ev_sync_pages_novo_servidor`;
CREATE EVENT `5c_pages`.`ev_sync_pages_novo_servidor`
  ON SCHEDULE EVERY 10 MINUTE
  STARTS (CURRENT_DATE + INTERVAL 7 MINUTE)
  ON COMPLETION PRESERVE
  ENABLE
  COMMENT 'Copia as páginas do servidor novo 209.159.144.7 (NS_02)'
  DO CALL `5c_pages`.`sp_sync_pages_novo_servidor`();

-- Conferência
SELECT EVENT_SCHEMA, EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, LAST_EXECUTED
  FROM information_schema.EVENTS
 WHERE EVENT_NAME IN ('ev_sync_headcount_novo_servidor', 'ev_sync_pages_novo_servidor');

-- Para pausar sem apagar:
-- ALTER EVENT `5c_headcount`.`ev_sync_headcount_novo_servidor` DISABLE;
-- ALTER EVENT `5c_pages`.`ev_sync_pages_novo_servidor` DISABLE;
