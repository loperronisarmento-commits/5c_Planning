SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_35_quadro_diario.sql  (10/11/2026, pedido do usuário)
--
-- O sistema não guardava o headcount dia a dia:
--   - 5c_headcount.headcount é a foto do RH, sobrescrita a cada importação (as saídas vão para
--     sync_removed_rows);
--   - fc_hc_semanal conta quem teve escala no site na semana (5c_schedule), não o quadro;
--   - o "tem" do Capacity foi a foto de 27/10 até 08/11 (script 34).
-- Por isso a linha "Tem" da tela Capacity ficou reta em 220 e depois em 221, mesmo com os
-- desligamentos (o cadastro do 5C Escalas foi de 229 em 01/11 para 220 em 05/11).
--
-- Este script cria o registro diário do quadro:
--   1. fc_quadro_dia: SRs (funcao EQUITE) e OS (DECURIO) com cadastro vigente no 5C Escalas, por
--      unidade e turno; unidade 0 / turno '-' = cadastro vigente sem unidade.
--   2. fc_quadro_dia_rh: o resumo do RH no dia (SR no headcount, com unidade, liderança,
--      trainee, fora das unidades) e quem tem cadastro de SR e já saiu do RH. Só existe a partir
--      de hoje: o RH não guarda a foto dos dias anteriores.
--   3. sp_fc_quadro_dia(data): grava o dia (apaga e regrava). O RH só entra quando a data é hoje.
--   4. sp_fc_rodar_dia ganha a etapa 'quadro' (logo depois de 'estrutura_rep', script 34).
--   5. Reconstrói 01/11 a 09/11 pelas vigências do cadastro (origem RECONSTRUIDO) e grava hoje.
-- A tela Capacity passa a desenhar a linha "Tem" com fc_quadro_dia (1.26.0 do 5C Escalas).
--
-- Rodar pelo mysql.exe com o 5c.cnf (DELIMITER e CALL).
-- =====================================================================

USE `5c_planning`;

-- 0. Antes
SELECT 'antes' AS momento, COUNT(*) AS tabelas FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME IN ('fc_quadro_dia', 'fc_quadro_dia_rh');

-- ---------------------------------------------------------------------
-- 1 e 2. Tabelas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_quadro_dia (
  data       DATE NOT NULL,
  unidade    TINYINT NOT NULL COMMENT '0 = cadastro vigente sem unidade',
  turno      CHAR(1) NOT NULL COMMENT 'A, C, D; - = sem turno',
  srs        SMALLINT NOT NULL DEFAULT 0 COMMENT 'SRs (funcao EQUITE; na unidade 0, também sem função)',
  os         SMALLINT NOT NULL DEFAULT 0 COMMENT 'OS (funcao DECURIO)',
  origem     ENUM('DIARIO','RECONSTRUIDO') NOT NULL DEFAULT 'DIARIO' COMMENT 'RECONSTRUIDO = refeito depois pelas vigências do cadastro',
  gravado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (data, unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Quadro do 5C Escalas em cada dia, por unidade e turno (script 35)';

CREATE TABLE IF NOT EXISTS fc_quadro_dia_rh (
  data             DATE NOT NULL PRIMARY KEY,
  rh_sr            SMALLINT NOT NULL COMMENT 'Sales Representative no headcount do RH (sem os códigos de teste)',
  rh_com_unidade   SMALLINT NOT NULL COMMENT 'destes, com cadastro vigente com unidade no 5C Escalas (SR ou OS)',
  rh_lideranca     SMALLINT NOT NULL COMMENT 'destes, liderança (OM, OC, OA) sem cadastro com unidade',
  rh_trainee       SMALLINT NOT NULL COMMENT 'destes, trainee de turma em andamento',
  rh_fora          SMALLINT NOT NULL COMMENT 'destes, fora das unidades (tela Fora das unidades)',
  esc_fora_rh      SMALLINT NOT NULL COMMENT 'cadastro de SR vigente com unidade no 5C Escalas e fora do RH',
  gravado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Resumo do headcount do RH em cada dia, contra o 5C Escalas (script 35)';

DELIMITER $$

-- ---------------------------------------------------------------------
-- 3. Grava um dia
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_quadro_dia $$
CREATE PROCEDURE sp_fc_quadro_dia(IN p_data DATE)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  DELETE FROM fc_quadro_dia WHERE data = p_data;
  INSERT INTO fc_quadro_dia (data, unidade, turno, srs, os, origem)
  SELECT p_data, COALESCE(c.unidade, 0), IF(c.unidade IS NULL, '-', c.turno),
         COUNT(DISTINCT CASE WHEN c.funcao = 'EQUITE' OR (c.unidade IS NULL AND c.funcao IS NULL) THEN c.rep_code END),
         COUNT(DISTINCT CASE WHEN c.funcao = 'DECURIO' THEN c.rep_code END),
         IF(p_data < CURDATE(), 'RECONSTRUIDO', 'DIARIO')
    FROM `5c_escalas`.esc_colaborador_config c
   WHERE c.vigente_desde <= p_data AND (c.vigente_ate IS NULL OR c.vigente_ate >= p_data)
     AND (c.funcao IS NOT NULL OR c.unidade IS NULL)
   GROUP BY COALESCE(c.unidade, 0), IF(c.unidade IS NULL, '-', c.turno);

  -- O RH só tem a foto de agora: o resumo entra só no dia de hoje.
  IF p_data = CURDATE() THEN
    DELETE FROM fc_quadro_dia_rh WHERE data = p_data;
    INSERT INTO fc_quadro_dia_rh (data, rh_sr, rh_com_unidade, rh_lideranca, rh_trainee, rh_fora, esc_fora_rh)
    WITH rh AS (
      SELECT DISTINCT UPPER(code) COLLATE utf8mb4_unicode_ci AS rep FROM `5c_headcount`.headcount
       WHERE position = 'Sales Representative' AND code IS NOT NULL AND code <> '' AND code NOT IN ('L000000', 'T000001')),
    unid AS (
      SELECT DISTINCT UPPER(rep_code) COLLATE utf8mb4_unicode_ci AS rep FROM `5c_escalas`.esc_colaborador_config
       WHERE unidade IS NOT NULL AND vigente_desde <= p_data AND (vigente_ate IS NULL OR vigente_ate >= p_data)),
    lid AS (
      SELECT UPPER(rep_code) COLLATE utf8mb4_unicode_ci AS rep FROM `5c_escalas`.esc_lideranca
       WHERE rep_code IS NOT NULL AND vigente_desde <= p_data AND (vigente_ate IS NULL OR vigente_ate >= p_data)
      UNION
      SELECT UPPER(decurio_code) COLLATE utf8mb4_unicode_ci FROM `5c_escalas`.esc_nucleo
       WHERE decurio_code IS NOT NULL AND vigente_desde <= p_data AND (vigente_ate IS NULL OR vigente_ate >= p_data)),
    trn AS (
      SELECT DISTINCT UPPER(r.rep_code) COLLATE utf8mb4_unicode_ci AS rep FROM `5c_escalas`.esc_treinamento_trainee r
        JOIN `5c_escalas`.esc_treinamento_turma t ON t.id = r.turma_id
       WHERE t.status IN ('RASCUNHO', 'ATIVA') AND r.rep_code IS NOT NULL),
    sr AS (
      SELECT DISTINCT UPPER(rep_code) COLLATE utf8mb4_unicode_ci AS rep FROM `5c_escalas`.esc_colaborador_config
       WHERE funcao = 'EQUITE' AND unidade IS NOT NULL AND vigente_desde <= p_data AND (vigente_ate IS NULL OR vigente_ate >= p_data)),
    cls AS (
      SELECT rh.rep,
             CASE WHEN rh.rep IN (SELECT rep FROM unid) THEN 'UNIDADE'
                  WHEN rh.rep IN (SELECT rep FROM lid) THEN 'LIDER'
                  WHEN rh.rep IN (SELECT rep FROM trn) THEN 'TRAINEE'
                  ELSE 'FORA' END AS c
        FROM rh)
    SELECT p_data, COUNT(*), SUM(c = 'UNIDADE'), SUM(c = 'LIDER'), SUM(c = 'TRAINEE'), SUM(c = 'FORA'),
           (SELECT COUNT(*) FROM sr WHERE sr.rep NOT IN (SELECT rep FROM rh))
      FROM cls;
  END IF;

  COMMIT;
END $$

-- ---------------------------------------------------------------------
-- 4. Orquestração diária com a etapa 'quadro'
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_rodar_dia $$
CREATE PROCEDURE sp_fc_rodar_dia()
BEGIN
  DECLARE v_d DATE DEFAULT CURDATE();
  DECLARE v_log INT;
  DECLARE v_msg VARCHAR(500);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    UPDATE fc_log_execucao SET fim = NOW(), status = 'ERRO', mensagem = LEFT(v_msg, 500) WHERE id = v_log;
  END;

  INSERT INTO fc_log_execucao (data_calculo, etapa, inicio) VALUES (v_d, 'carga_fans', NOW());
  SET v_log = LAST_INSERT_ID();

  CALL sp_fc_carga_fans(v_d - INTERVAL 2 DAY, v_d - INTERVAL 1 DAY);
  UPDATE fc_log_execucao SET etapa = 'hc_to' WHERE id = v_log;
  CALL sp_fc_hc_to();
  UPDATE fc_log_execucao SET etapa = 'paginas_eventos' WHERE id = v_log;
  CALL sp_fc_paginas_eventos(v_d);
  UPDATE fc_log_execucao SET etapa = 'prev_to' WHERE id = v_log;
  CALL sp_fc_prev_to(v_d);
  UPDATE fc_log_execucao SET etapa = 'escala' WHERE id = v_log;
  CALL sp_fc_escala(v_d);
  UPDATE fc_log_execucao SET etapa = 'buffer' WHERE id = v_log;
  CALL sp_fc_buffer(v_d);
  UPDATE fc_log_execucao SET etapa = 'impacto_paginas' WHERE id = v_log;
  CALL sp_fc_impacto_paginas(v_d);
  -- etapa do script 34: o "tem" vem do cadastro do 5C Escalas
  UPDATE fc_log_execucao SET etapa = 'estrutura_rep' WHERE id = v_log;
  CALL sp_fc_estrutura_rep_escalas();
  -- etapa do script 35: registro diário do quadro (5C Escalas e RH)
  UPDATE fc_log_execucao SET etapa = 'quadro' WHERE id = v_log;
  CALL sp_fc_quadro_dia(v_d);
  UPDATE fc_log_execucao SET etapa = 'postos' WHERE id = v_log;
  CALL sp_fc_postos(v_d);
  -- etapa do script 19 (versões do capacity e plano sugerido); numa instalação nova, rodar o 19 logo depois
  UPDATE fc_log_execucao SET etapa = 'versao' WHERE id = v_log;
  CALL sp_fc_capacity_versao(v_d, 'DIARIO', NULL);
  -- etapa do script 23: horas fora do SLA nos postos da regra de faturamento (fãs de ontem e anteontem)
  UPDATE fc_log_execucao SET etapa = 'sla' WHERE id = v_log;
  CALL sp_fc_sla_horas(v_d - INTERVAL 2 DAY, NULL);
  CALL sp_fc_sla_horas(v_d - INTERVAL 1 DAY, NULL);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

-- ---------------------------------------------------------------------
-- 5. Reconstrução de 01/11 até ontem e o dia de hoje
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS tmp_fc_quadro_reconstruir $$
CREATE PROCEDURE tmp_fc_quadro_reconstruir()
BEGIN
  DECLARE v_d DATE DEFAULT '2026-11-01';
  WHILE v_d <= CURDATE() DO
    CALL sp_fc_quadro_dia(v_d);
    SET v_d = v_d + INTERVAL 1 DAY;
  END WHILE;
END $$

DELIMITER ;

CALL tmp_fc_quadro_reconstruir();
DROP PROCEDURE IF EXISTS tmp_fc_quadro_reconstruir;

-- ---------------------------------------------------------------------
-- 6. Depois: SRs por dia (tem de variar com os desligamentos) e o resumo do RH de hoje
-- ---------------------------------------------------------------------
SELECT 'depois' AS momento, data, origem, SUM(IF(unidade > 0, srs, 0)) AS srs_com_unidade,
       SUM(IF(unidade = 0, srs, 0)) AS sem_unidade, SUM(os) AS os
  FROM fc_quadro_dia GROUP BY data, origem ORDER BY data;

SELECT 'depois' AS momento, data, rh_sr, rh_com_unidade, rh_lideranca, rh_trainee, rh_fora, esc_fora_rh FROM fc_quadro_dia_rh;

SELECT 'depois' AS momento, ROUTINE_NAME, ROUTINE_DEFINITION LIKE '%sp_fc_quadro_dia%' AS chama_quadro
  FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '5c_planning' AND ROUTINE_NAME IN ('sp_fc_rodar_dia', 'sp_fc_quadro_dia');
