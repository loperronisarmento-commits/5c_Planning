SET NAMES utf8mb4;
-- =====================================================================
-- ES_122_documento_conteudo.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11: o site guarda todas as versões das Restrições dos OM (e dos outros
-- documentos versionados), para saber que até tal data valia tal regra. Cada versão passa a guardar
-- o documento inteiro (HTML compactado com gzip) além da assinatura. A versão começa a valer em
-- criado_em e vale até a versão seguinte do mesmo documento.
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_documento_versao
  ADD COLUMN conteudo MEDIUMBLOB NULL COMMENT 'documento da versão (HTML, gzcompress)' AFTER assinatura,
  ADD COLUMN bytes INT UNSIGNED NULL COMMENT 'tamanho do HTML sem compactar' AFTER conteudo;

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_documento_versao' ORDER BY ORDINAL_POSITION;
