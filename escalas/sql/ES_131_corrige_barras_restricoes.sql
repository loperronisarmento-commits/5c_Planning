SET NAMES utf8mb4;
-- =====================================================================
-- ES_131_corrige_barras_restricoes.sql  (08/11, aplicado pelo Claude via mysql.exe)
-- O teste de ida e volta das restrições da U1 (desativar e ativar de novo, lib/restricoes_om_gestao.php) rodou
-- por uma bancada local que leu os textos pelo mysql.exe --batch, que escreve "/" como "\/" no JSON guardado.
-- Ao ativar de novo, 7 motivos da equipe fixa e 18 observações de cadastro voltaram com "\/" no lugar de "/".
-- Backup de antes: resultados/restricoes_backup_0811 (nenhum desses campos tinha "\").
-- =====================================================================
USE `5c_escalas`;
START TRANSACTION;
UPDATE esc_pagina_prioridade SET motivo = REPLACE(motivo, CONCAT(CHAR(92), '/'), '/') WHERE INSTR(motivo, CHAR(92)) > 0;
UPDATE esc_colaborador_config SET observacao = REPLACE(observacao, CONCAT(CHAR(92), '/'), '/') WHERE INSTR(observacao, CHAR(92)) > 0;
COMMIT;
SELECT (SELECT COUNT(*) FROM esc_pagina_prioridade WHERE INSTR(motivo, CHAR(92)) > 0) AS prio_com_barra,
       (SELECT COUNT(*) FROM esc_colaborador_config WHERE INSTR(observacao, CHAR(92)) > 0) AS cfg_com_barra;
