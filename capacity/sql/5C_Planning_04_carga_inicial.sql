-- =====================================================================
-- 5C | Forecast de WFM — 04. Carga inicial (rodar uma vez, depois dos arquivos 01, 02, 03 e 04a)
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Modelo e categoria de cada página (gerado a partir da análise de 19/10/2026)
--    Já carregado pelo arquivo 5C_Planning_04a_paginas_modelo.sql (rode-o antes deste).

-- 2. Histórico de fãs por página e hora (4 semanas; ~30 s por bloco de 7 dias)
CALL sp_fc_carga_fans('2026-09-21', '2026-09-27');
CALL sp_fc_carga_fans('2026-09-28', '2026-10-04');
CALL sp_fc_carga_fans('2026-10-05', '2026-10-11');
CALL sp_fc_carga_fans('2026-10-12', '2026-10-18');

-- 3. Primeiro cálculo completo (a partir daqui, o evento roda todo dia às 06:30)
CALL sp_fc_rodar_dia();

-- 4. Conferência
SELECT * FROM fc_log_execucao ORDER BY id DESC LIMIT 5;
SELECT * FROM vw_fc_1_hc_semanal ORDER BY `Ano` DESC, `Semana` DESC LIMIT 8;
SELECT * FROM vw_fc_2_to_semanal ORDER BY `Ano` DESC, `Semana` DESC LIMIT 8;
SELECT * FROM vw_fc_3_prev_to_atual LIMIT 8;
SELECT * FROM vw_fc_4_escala_atual LIMIT 14;
SELECT * FROM vw_fc_5_buffer_atual LIMIT 14;
SELECT * FROM vw_fc_6_impacto_paginas_atual;
SELECT * FROM vw_fc_plano_semanal;

-- ---------------------------------------------------------------------
-- Exemplos de manutenção
-- ---------------------------------------------------------------------
-- Página nova vinda do CRM (upsert pelo crm_id):
-- INSERT INTO fc_paginas_previstas (pagina, modelo, tier, receita_mensal_prev, data_prevista, probabilidade, status, origem, crm_id)
-- VALUES ('Nome da Página', 'nome da modelo', 'C', 90000, '2026-11-10', 0.60, 'PREVISTA', 'CRM', 'CRM-12345')
-- ON DUPLICATE KEY UPDATE tier = VALUES(tier), receita_mensal_prev = VALUES(receita_mensal_prev),
--   data_prevista = VALUES(data_prevista), probabilidade = VALUES(probabilidade), status = VALUES(status);

-- Saída de página já combinada (entra na escala prevista a partir da data):
-- INSERT INTO fc_pagina_eventos (pagina, tipo, data_evento, origem, status) VALUES ('Nome da Página', 'SAIDA', '2026-11-30', 'MANUAL', 'CONFIRMADA');

-- Nova turma de contratação:
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status) VALUES ('2026-12-03','2026-12-03','2026-12-17','C',8,'PLANEJADA');

-- Motivo de desligamento (voluntário × involuntário):
-- INSERT INTO fc_desligamento_motivo (code, tipo, motivo) VALUES ('A123456', 'VOLUNTARIO', 'Pediu desligamento');

-- Mudar a regra da página (ex.: manter SLA 80% na Skylar seria categoria SKYLAR com limite_skylar = 20):
-- UPDATE fc_pagina_modelo SET categoria = 'SLA80' WHERE pagina = 'Nome da Página';

-- Recalcular um dia manualmente:
-- CALL sp_fc_rodar_dia();
