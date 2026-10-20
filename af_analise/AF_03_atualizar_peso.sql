-- =====================================================================
-- 5C | Falhas Administrativas (AF) — atualizar `peso_falha_adm`
-- Rodar no Navicat (servidor .11), schema 5c_planning.
-- *** RASCUNHO: os valores abaixo são PLACEHOLDERS até o AF_01 rodar. ***
-- Depois do parecer (AF_02), este arquivo recebe os números finais e
-- o nome exato das colunas de `fc_parametros` (ver Q0 em resultados/).
-- Tudo em transação; bloco "DESFAZER" no fim.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. Fotografia antes (guardar junto com o histórico da decisão)
-- ---------------------------------------------------------------------
SELECT * FROM `5c_planning`.`fc_parametros`;

-- ---------------------------------------------------------------------
-- 1. Backup da linha/tabela de parâmetros (nada se perde)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `5c_planning`.`fc_parametros_bkp_af`
  LIKE `5c_planning`.`fc_parametros`;
-- Se fc_parametros não tiver coluna de carimbo, o lote é identificado
-- pela data abaixo na tabela de histórico (passo 3).
INSERT INTO `5c_planning`.`fc_parametros_bkp_af`
SELECT * FROM `5c_planning`.`fc_parametros`;

-- ---------------------------------------------------------------------
-- 2. Novo peso (preencher com o valor do AF_02)
--    Ajustar a forma do UPDATE à estrutura real de fc_parametros:
--    (a) tabela "chave/valor"  -> UPDATE ... WHERE parametro = 'peso_falha_adm'
--    (b) tabela de uma linha   -> UPDATE ... SET peso_falha_adm = ...
-- ---------------------------------------------------------------------
START TRANSACTION;

-- (a) chave/valor
-- UPDATE `5c_planning`.`fc_parametros`
--    SET valor = <PESO_GLOBAL>
--  WHERE parametro = 'peso_falha_adm';

-- (b) uma linha
-- UPDATE `5c_planning`.`fc_parametros`
--    SET peso_falha_adm = <PESO_GLOBAL>;

-- Peso por turno (só se o AF_02 recomendar e o sp_fc_rodar_dia for adaptado):
-- INSERT INTO `5c_planning`.`fc_parametros` (parametro, valor, descricao) VALUES
--   ('peso_falha_adm_A', <PESO_A>, 'AF no absenteísmo, turno A (T2/T3)'),
--   ('peso_falha_adm_C', <PESO_C>, 'AF no absenteísmo, turno C (T4/T5)'),
--   ('peso_falha_adm_D', <PESO_D>, 'AF no absenteísmo, turno D (T6/T1)')
-- ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- Conferir antes de confirmar
SELECT * FROM `5c_planning`.`fc_parametros`;

COMMIT;
-- ROLLBACK;   -- em vez de COMMIT, se algo estiver errado

-- ---------------------------------------------------------------------
-- 3. Recalcular o forecast do dia com o novo peso e conferir o plano
-- ---------------------------------------------------------------------
-- CALL `5c_planning`.`sp_fc_rodar_dia`();
-- SELECT * FROM `5c_planning`.`fc_log_execucao` ORDER BY 1 DESC LIMIT 3;
-- SELECT * FROM `5c_planning`.`vw_fc_plano_semanal`;

-- ---------------------------------------------------------------------
-- DESFAZER (restaura a fotografia do passo 1)
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- DELETE FROM `5c_planning`.`fc_parametros`;
-- INSERT INTO `5c_planning`.`fc_parametros`
-- SELECT * FROM `5c_planning`.`fc_parametros_bkp_af`;
-- COMMIT;
-- CALL `5c_planning`.`sp_fc_rodar_dia`();
