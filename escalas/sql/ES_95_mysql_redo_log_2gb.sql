SET NAMES utf8mb4;
-- =====================================================================
-- ES_95_mysql_redo_log_2gb.sql  (04/11, aplicado pelo Claude via mysql.exe, a pedido do usuário)
--
-- O MySQL do .11 (9.5.0) reiniciou 8 vezes de 29/10 a 04/11. Antes da parada de 04/11 (06:49 de
-- Brasília) o log de erros mostra "Threads are unable to reserve space in redo log ... 'log_checkpointer'
-- consumer still lagging behind": o redo log de 128 MB enchia com as cargas (events de sync e ETL) e
-- travava as gravações. Sobe para 2 GB, com SET PERSIST: vale na hora (o InnoDB redimensiona
-- sozinho, sem reiniciar) e fica em mysqld-auto.cnf para as próximas partidas.
-- Disco C: com 107,8 GB livres em 04/11. Para voltar: SET PERSIST innodb_redo_log_capacity = 134217728;
-- Precisa de CLI (o MCP desfaz SET).
-- =====================================================================

SELECT @@GLOBAL.innodb_redo_log_capacity AS antes;

SET PERSIST innodb_redo_log_capacity = 2147483648;

SELECT @@GLOBAL.innodb_redo_log_capacity AS depois;
SELECT VARIABLE_NAME, VARIABLE_VALUE FROM performance_schema.persisted_variables WHERE VARIABLE_NAME = 'innodb_redo_log_capacity';
