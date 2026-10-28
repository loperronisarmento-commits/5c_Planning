-- =====================================================================
-- ES_98_desfazer_v4.sql
-- Desfaz o ES_05 e o ES_04 sem apagar o schema (o ES_99 apaga tudo).
--   - apaga a carga da estrutura de 01/11 e reabre a configuração antiga
--     das pessoas da carga (a que tinha sido fechada em 31/10);
--   - apaga as tabelas e a view novas;
--   - as colunas novas ficam (vazias): não atrapalham a v3.
-- Rodar no Navicat, servidor .11.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

START TRANSACTION;

-- Reabre a configuração anterior de quem entrou na carga
UPDATE esc_colaborador_config c
  JOIN esc_colaborador_config n ON n.rep_code = c.rep_code AND n.origem = 'CARGA_ESTRUTURA' AND n.vigente_desde = '2026-11-01'
   SET c.vigente_ate = NULL
 WHERE c.vigente_ate = '2026-10-31';

DELETE FROM esc_colaborador_config WHERE origem = 'CARGA_ESTRUTURA';
DELETE FROM esc_parametro WHERE grupo = 'v4';
DELETE FROM esc_modelo_escala
 WHERE nome = '5x2 rotativo 21 dias'
   AND id NOT IN (SELECT modelo_id FROM (SELECT modelo_id FROM esc_colaborador_config) x);

COMMIT;

ALTER TABLE esc_colaborador_config DROP FOREIGN KEY fk_colab_nucleo;
DROP VIEW  IF EXISTS vw_esc_estrutura_rep;
DROP TABLE IF EXISTS esc_vaga_equipe;
DROP TABLE IF EXISTS esc_posto_pagina;
DROP TABLE IF EXISTS esc_posto;
DROP TABLE IF EXISTS esc_nucleo;
DROP TABLE IF EXISTS esc_lideranca;
DROP TABLE IF EXISTS esc_unidade;
