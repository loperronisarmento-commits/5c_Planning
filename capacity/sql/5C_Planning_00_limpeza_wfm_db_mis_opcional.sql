-- =====================================================================
-- 5C | Forecast de WFM — 00. Limpeza (OPCIONAL)
-- Só use se você já tinha rodado a versão anterior dos scripts no schema 5c_wfm_db_mis.
-- Remove dali apenas os objetos do forecast (prefixo fc_ / vw_fc_ / sp_fc_ / ev_fc_). Nada mais é tocado.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_wfm_db_mis`;
DROP EVENT IF EXISTS ev_fc_forecast_diario;
DROP PROCEDURE IF EXISTS sp_fc_carga_fans;
DROP PROCEDURE IF EXISTS sp_fc_hc_to;
DROP PROCEDURE IF EXISTS sp_fc_prev_to;
DROP PROCEDURE IF EXISTS sp_fc_escala;
DROP PROCEDURE IF EXISTS sp_fc_buffer;
DROP PROCEDURE IF EXISTS sp_fc_paginas_eventos;
DROP PROCEDURE IF EXISTS sp_fc_impacto_paginas;
DROP PROCEDURE IF EXISTS sp_fc_rodar_dia;
DROP VIEW IF EXISTS vw_fc_1_hc_semanal;
DROP VIEW IF EXISTS vw_fc_2_to_semanal;
DROP VIEW IF EXISTS vw_fc_3_prev_to_atual;
DROP VIEW IF EXISTS vw_fc_4_escala_atual;
DROP VIEW IF EXISTS vw_fc_5_buffer_atual;
DROP VIEW IF EXISTS vw_fc_6_impacto_paginas_atual;
DROP VIEW IF EXISTS vw_fc_variacao_escala;
DROP VIEW IF EXISTS vw_fc_acerto_prev_to;
DROP VIEW IF EXISTS vw_fc_plano_semanal;
DROP TABLE IF EXISTS fc_calendario;
DROP TABLE IF EXISTS fc_parametros;
DROP TABLE IF EXISTS fc_pagina_modelo;
DROP TABLE IF EXISTS fc_contratacoes;
DROP TABLE IF EXISTS fc_paginas_previstas;
DROP TABLE IF EXISTS fc_desligamento_motivo;
DROP TABLE IF EXISTS fc_fans_pagina_hora;
DROP TABLE IF EXISTS fc_pagina_status_diario;
DROP TABLE IF EXISTS fc_pagina_eventos;
DROP TABLE IF EXISTS fc_desligamentos;
DROP TABLE IF EXISTS fc_log_execucao;
DROP TABLE IF EXISTS fc_hc_semanal;
DROP TABLE IF EXISTS fc_to_semanal;
DROP TABLE IF EXISTS fc_prev_to_semanal;
DROP TABLE IF EXISTS fc_escala_diaria;
DROP TABLE IF EXISTS fc_escala_pagina;
DROP TABLE IF EXISTS fc_buffer_diario;
DROP TABLE IF EXISTS fc_impacto_paginas;
DROP TABLE IF EXISTS fc_wrk_turnos;
DROP TABLE IF EXISTS fc_wrk_rep;
DROP TABLE IF EXISTS fc_wrk_perfil;
DROP TABLE IF EXISTS fc_wrk_cobertura;
DROP TABLE IF EXISTS fc_wrk_fator;
DROP TABLE IF EXISTS fc_wrk_carga;
DROP TABLE IF EXISTS fc_wrk_bloco;
DROP TABLE IF EXISTS fc_wrk_abs_taxa;
DROP TABLE IF EXISTS fc_wrk_nova;
