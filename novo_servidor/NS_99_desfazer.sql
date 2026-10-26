-- =====================================================================
-- NS_99 · Desfazer o espelho do servidor novo (rodar no .11)
-- ---------------------------------------------------------------------
-- Apaga events, procedures, federadas e servidores. Não mexe nos dados
-- de `5c_headcount`.`headcount`, `5c_pages`.`pages`, `statements_fanvue`
-- e `statements` (ficam como estão).
-- Depois disso, para voltar à API antiga, recoloque HeadCount e Pages no
-- etl_auto/run_auto.php.
-- =====================================================================

-- ---------- NS_05 / NS_06: Fanvue e históricos ----------
DROP EVENT IF EXISTS `5c_wfm_db`.`ev_sync_historicos_novo_servidor`;
DROP EVENT IF EXISTS `5c_wfm_db`.`ev_sync_fanvue_novo_servidor`;

DROP PROCEDURE IF EXISTS `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`;
DROP PROCEDURE IF EXISTS `5c_headcount`.`sp_sync_headcount_history_novo_servidor`;
DROP PROCEDURE IF EXISTS `5c_pages`.`sp_sync_paginas_history_novo_servidor`;

DROP TABLE IF EXISTS `5c_wfm_db`.`fed_fanvue_statements`;
DROP TABLE IF EXISTS `5c_headcount`.`fed_headcount_history`;
DROP TABLE IF EXISTS `5c_pages`.`fed_paginas_history`;

DROP SERVER IF EXISTS srv_novo_fanvue;

-- Tabelas com dados copiados (só apague se não forem mais usadas):
-- DROP TABLE IF EXISTS `5c_headcount`.`headcount_history`;
-- DROP TABLE IF EXISTS `5c_pages`.`paginas_history`;
-- DROP TABLE IF EXISTS `5c_wfm_db`.`fanvue_criadora_de_para`;
-- DROP TABLE IF EXISTS `5c_wfm_db`.`sync_novo_servidor_log`;

-- ---------- NS_01 a NS_03: headcount e páginas ----------
DROP EVENT IF EXISTS `5c_headcount`.`ev_sync_headcount_novo_servidor`;
DROP EVENT IF EXISTS `5c_pages`.`ev_sync_pages_novo_servidor`;

DROP PROCEDURE IF EXISTS `5c_headcount`.`sp_sync_headcount_novo_servidor`;
DROP PROCEDURE IF EXISTS `5c_pages`.`sp_sync_pages_novo_servidor`;

DROP TABLE IF EXISTS `5c_headcount`.`fed_headcount`;
DROP TABLE IF EXISTS `5c_pages`.`fed_paginas`;

DROP SERVER IF EXISTS srv_novo_headcount;
DROP SERVER IF EXISTS srv_novo_pages;
