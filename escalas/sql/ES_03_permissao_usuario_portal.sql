-- =====================================================================
-- ES_03_permissao_usuario_portal.sql
-- Permissão para o usuário do banco que o MIS Portal usa (DB_USER do .env).
--
-- O módulo precisa:
--   - LER e GRAVAR só no 5c_escalas;
--   - continuar só LENDO os outros schemas (já tem SELECT neles: o
--     Diagnóstico do módulo mostrou OK em todas as tabelas).
--
-- Troque <usuario_do_portal> e <host> pelos valores do DB_USER do .env
-- do portal (o host normalmente é '%' ou 'localhost'). Para conferir:
--   SELECT user, host FROM mysql.user WHERE user = '<usuario_do_portal>';
--
-- Rodar com um usuário administrador, depois do ES_01.
-- =====================================================================

GRANT SELECT, INSERT, UPDATE, DELETE ON `5c_escalas`.* TO '<usuario_do_portal>'@'<host>';

-- Conferência:
-- SHOW GRANTS FOR '<usuario_do_portal>'@'<host>';
