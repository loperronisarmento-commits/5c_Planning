-- =====================================================================
-- ES_99_desfazer.sql
-- Remove TUDO do 5C Escalas: o schema 5c_escalas inteiro, com todas as
-- decisões, configurações e rascunhos gravados.
-- Não toca em nenhum outro schema.
--
-- ATENÇÃO: apaga os dados do 5c_escalas sem volta. Se quiser guardar,
-- faça antes um backup (Navicat > Dump SQL File do schema 5c_escalas).
-- =====================================================================

-- REVOKE SELECT, INSERT, UPDATE, DELETE ON `5c_escalas`.* FROM '<usuario_do_portal>'@'<host>';

DROP DATABASE IF EXISTS `5c_escalas`;
