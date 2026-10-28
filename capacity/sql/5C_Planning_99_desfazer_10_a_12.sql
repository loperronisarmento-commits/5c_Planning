-- =====================================================================
-- 5C | Forecast de WFM — 99. Desfazer os scripts 10 a 14 (estrutura em unidades e cenários de turmas)
-- Volta o sp_fc_rodar_dia à versão de 19/10 (sem a etapa 'postos') e apaga só os
-- objetos novos. Os parâmetros novos ficam em fc_parametros (não atrapalham).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

DELIMITER $$
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

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$
DELIMITER ;

-- Script 14 (quadro e turmas em cenários)
DROP VIEW IF EXISTS vw_fc_11_quadro_vagas;
DROP VIEW IF EXISTS vw_fc_12_turmas_cenarios;
DROP TABLE IF EXISTS fc_projecao_turmas_cenario;
DROP TABLE IF EXISTS fc_contratacoes_cenario;
DROP TABLE IF EXISTS fc_quadro_cenario;

DROP PROCEDURE IF EXISTS sp_fc_postos;
DROP PROCEDURE IF EXISTS sp_fc_postos_carteira;

DROP VIEW IF EXISTS vw_fc_7_necessidade_estrutura;
DROP VIEW IF EXISTS vw_fc_8_postos_pagina;
DROP VIEW IF EXISTS vw_fc_9_carteiras;
DROP VIEW IF EXISTS vw_fc_10_paginas_fora_da_estrutura;

DROP TABLE IF EXISTS fc_wrk_buffer_aloc;
DROP TABLE IF EXISTS fc_buffer_turno;
DROP TABLE IF EXISTS fc_wrk_pico;
DROP TABLE IF EXISTS fc_wrk_pico_dia;
DROP TABLE IF EXISTS fc_wrk_buf_taxa;
DROP TABLE IF EXISTS fc_wrk_pc_bin_item;
DROP TABLE IF EXISTS fc_wrk_pc_bin;
DROP TABLE IF EXISTS fc_wrk_pc_item;
DROP TABLE IF EXISTS fc_necessidade_carteira;
DROP TABLE IF EXISTS fc_necessidade_estrutura;
DROP TABLE IF EXISTS fc_postos_pagina;
DROP TABLE IF EXISTS fc_postos;
DROP TABLE IF EXISTS fc_pagina_carga;
DROP TABLE IF EXISTS fc_estrutura_rep;
DROP TABLE IF EXISTS fc_estrutura_lider;
DROP TABLE IF EXISTS fc_pagina_carteira;
DROP TABLE IF EXISTS fc_carteira;
