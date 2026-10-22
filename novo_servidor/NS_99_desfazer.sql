-- =====================================================================
-- NS_99 · Desfazer o espelho do servidor novo (rodar no .11)
-- ---------------------------------------------------------------------
-- Apaga events, procedures, federadas e servidores. Não mexe nos dados
-- de `5c_headcount`.`headcount` e `5c_pages`.`pages` (ficam como estão).
-- Depois disso, para voltar à API antiga, recoloque HeadCount e Pages no
-- etl_auto/run_auto.php.
-- =====================================================================

DROP EVENT IF EXISTS `5c_headcount`.`ev_sync_headcount_novo_servidor`;
DROP EVENT IF EXISTS `5c_pages`.`ev_sync_pages_novo_servidor`;

DROP PROCEDURE IF EXISTS `5c_headcount`.`sp_sync_headcount_novo_servidor`;
DROP PROCEDURE IF EXISTS `5c_pages`.`sp_sync_pages_novo_servidor`;

DROP TABLE IF EXISTS `5c_headcount`.`fed_headcount`;
DROP TABLE IF EXISTS `5c_pages`.`fed_paginas`;

DROP SERVER IF EXISTS srv_novo_headcount;
DROP SERVER IF EXISTS srv_novo_pages;
