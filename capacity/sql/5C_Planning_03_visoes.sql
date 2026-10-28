-- =====================================================================
-- 5C | Forecast de WFM — 03. Visões no formato pedido (para Power BI / Navicat)
-- As visões "atual" mostram o cálculo mais recente; as tabelas guardam todos os cálculos.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- (1) HC semanal
CREATE OR REPLACE VIEW vw_fc_1_hc_semanal AS
SELECT semana_iso AS `Semana`, mes AS `Mês`, ano AS `Ano`,
       hc_a AS `HC - A`, hc_c AS `HC - C`, hc_d AS `HC - D`, hc_total AS `HC - Total`,
       inicio_semana AS `Início da semana`, semana_fechada AS `Semana fechada`
FROM fc_hc_semanal;

-- (2) Turnover semanal
CREATE OR REPLACE VIEW vw_fc_2_to_semanal AS
SELECT semana_iso AS `Semana`, mes AS `Mês`, ano AS `Ano`,
       to_a AS `TO - A`, to_c AS `TO - C`, to_d AS `TO - D`, to_sem_turno AS `TO - Sem turno`,
       to_total AS `TO - Total`, to_ate_90_dias AS `TO - Até 90 dias`,
       to_voluntario AS `TO - Voluntário`, to_involuntario AS `TO - Involuntário`,
       inicio_semana AS `Início da semana`
FROM fc_to_semanal;

-- (3) Previsão de turnover (cálculo mais recente)
CREATE OR REPLACE VIEW vw_fc_3_prev_to_atual AS
SELECT semana_iso AS `Semana`, mes AS `Mês`, ano AS `Ano`,
       prev_to_a AS `PREV.TO - A`, prev_to_c AS `PREV.TO - C`, prev_to_d AS `PREV.TO - D`,
       prev_to_total AS `PREV.TO - Total`,
       hc_proj_a AS `HC projetado - A`, hc_proj_c AS `HC projetado - C`, hc_proj_d AS `HC projetado - D`,
       inicio_semana AS `Início da semana`, data_calculo AS `Data do cálculo`
FROM fc_prev_to_semanal
WHERE data_calculo = (SELECT MAX(data_calculo) FROM fc_prev_to_semanal);

-- (4) Pessoas a escalar por dia, sem buffer (cálculo mais recente)
CREATE OR REPLACE VIEW vw_fc_4_escala_atual AS
SELECT DATE_FORMAT(dia, '%d-%m-%Y') AS `DIA-MÊS-ANO`, a AS `A`, c AS `C`, d AS `D`, total AS `Total`,
       dia AS `Dia`, data_calculo AS `Data do cálculo`
FROM fc_escala_diaria
WHERE data_calculo = (SELECT MAX(data_calculo) FROM fc_escala_diaria);

-- (5) Buffers por dia (cálculo mais recente)
CREATE OR REPLACE VIEW vw_fc_5_buffer_atual AS
SELECT DATE_FORMAT(dia, '%d-%m-%Y') AS `DIA-MÊS-ANO`, a AS `A`, c AS `C`, d AS `D`, total AS `Total`,
       abs_a AS `Absenteísmo - A`, abs_c AS `Absenteísmo - C`, abs_d AS `Absenteísmo - D`,
       to_a AS `Desligamento - A`, to_c AS `Desligamento - C`, to_d AS `Desligamento - D`,
       dia AS `Dia`, data_calculo AS `Data do cálculo`
FROM fc_buffer_diario
WHERE data_calculo = (SELECT MAX(data_calculo) FROM fc_buffer_diario);

-- (6) Impacto de entradas e saídas de páginas no HC (cálculo mais recente)
CREATE OR REPLACE VIEW vw_fc_6_impacto_paginas_atual AS
SELECT tipo AS `Tipo`, pagina AS `Página`, data_evento AS `Data`, status AS `Status`,
       probabilidade AS `Probabilidade`, fans_h_media AS `Fãs/h`,
       reps_h_a AS `Reps/h - A`, reps_h_c AS `Reps/h - C`, reps_h_d AS `Reps/h - D`,
       hc_a AS `HC - A`, hc_c AS `HC - C`, hc_d AS `HC - D`, hc_total AS `HC - Total`,
       data_calculo AS `Data do cálculo`
FROM fc_impacto_paginas
WHERE data_calculo = (SELECT MAX(data_calculo) FROM fc_impacto_paginas);

-- Acompanhamento: como a previsão de cada dia mudou a cada recálculo
CREATE OR REPLACE VIEW vw_fc_variacao_escala AS
SELECT e.dia AS `Dia`, e.data_calculo AS `Data do cálculo`, DATEDIFF(e.dia, e.data_calculo) AS `Dias de antecedência`,
       e.a AS `A`, e.c AS `C`, e.d AS `D`,
       e.a - LAG(e.a) OVER w AS `Variação A`, e.c - LAG(e.c) OVER w AS `Variação C`, e.d - LAG(e.d) OVER w AS `Variação D`,
       b.a AS `Buffer A`, b.c AS `Buffer C`, b.d AS `Buffer D`
FROM fc_escala_diaria e
LEFT JOIN fc_buffer_diario b ON b.data_calculo = e.data_calculo AND b.dia = e.dia
WINDOW w AS (PARTITION BY e.dia ORDER BY e.data_calculo);

-- Acerto da previsão de turnover: previsto (cálculo feito no início da semana) × realizado
CREATE OR REPLACE VIEW vw_fc_acerto_prev_to AS
SELECT p.semana_iso AS `Semana`, p.mes AS `Mês`, p.ano AS `Ano`,
       p.prev_to_a AS `Previsto A`, t.to_a AS `Real A`,
       p.prev_to_c AS `Previsto C`, t.to_c AS `Real C`,
       p.prev_to_d AS `Previsto D`, t.to_d AS `Real D`,
       p.data_calculo AS `Data do cálculo`
FROM fc_prev_to_semanal p
JOIN fc_to_semanal t ON t.ano_iso = p.ano_iso AND t.semana_iso = p.semana_iso
WHERE p.data_calculo = p.inicio_semana;

-- Plano semanal: HC necessário × HC projetado → quanto contratar
--   HC necessário = (escala média + buffers médios) ÷ presença no 3x1
CREATE OR REPLACE VIEW vw_fc_plano_semanal AS
SELECT c.semana_iso AS `Semana`, c.mes_semana AS `Mês`, c.ano_iso AS `Ano`, c.inicio_semana AS `Início da semana`,
       ROUND(AVG(e.a),1) AS `Escala A`, ROUND(AVG(e.c),1) AS `Escala C`, ROUND(AVG(e.d),1) AS `Escala D`,
       ROUND(AVG(b.a),1) AS `Buffer A`, ROUND(AVG(b.c),1) AS `Buffer C`, ROUND(AVG(b.d),1) AS `Buffer D`,
       CEIL(AVG(e.a + b.a) / pr.valor) AS `HC necessário A`,
       CEIL(AVG(e.c + b.c) / pr.valor) AS `HC necessário C`,
       CEIL(AVG(e.d + b.d) / pr.valor) AS `HC necessário D`,
       MAX(p.hc_proj_a) AS `HC projetado A`, MAX(p.hc_proj_c) AS `HC projetado C`, MAX(p.hc_proj_d) AS `HC projetado D`,
       CEIL(AVG(e.a + b.a) / pr.valor) - ROUND(MAX(p.hc_proj_a)) AS `Falta A`,
       CEIL(AVG(e.c + b.c) / pr.valor) - ROUND(MAX(p.hc_proj_c)) AS `Falta C`,
       CEIL(AVG(e.d + b.d) / pr.valor) - ROUND(MAX(p.hc_proj_d)) AS `Falta D`,
       MAX(e.data_calculo) AS `Data do cálculo`
FROM fc_escala_diaria e
JOIN fc_buffer_diario b ON b.data_calculo = e.data_calculo AND b.dia = e.dia
JOIN fc_calendario c ON c.dia = e.dia
JOIN fc_parametros pr ON pr.chave = 'presenca_3x1'
LEFT JOIN fc_prev_to_semanal p ON p.data_calculo = e.data_calculo AND p.ano_iso = c.ano_iso AND p.semana_iso = c.semana_iso
WHERE e.data_calculo = (SELECT MAX(data_calculo) FROM fc_escala_diaria)
GROUP BY c.ano_iso, c.semana_iso, c.mes_semana, c.inicio_semana, pr.valor;
