SET NAMES utf8mb4;
-- =====================================================================
-- ES_109_melody_fora_rocket.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário em 04/11: a Melody Vale (M203717) foi para a U3 (ES_102) e sai da equipe fixa da
-- Rocket Reyna VIP e da Rocket Reyna OFTV. As linhas dela (de 02/11) fecham em 03/11.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('ES_109: foi para a U3; ', IFNULL(motivo, '')), 255)
 WHERE pagina IN ('Rocket Reyna VIP', 'Rocket Reyna OFTV') AND rep_codes = 'M203717' AND vigente_ate IS NULL;

SELECT pagina, rep_codes, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_prioridade WHERE pagina IN ('Rocket Reyna VIP', 'Rocket Reyna OFTV') ORDER BY pagina, vigente_desde;
