SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_38_postos_teto30_max2.sql  (11/11/2026, decisão do usuário: "novos postos a partir de 16/11")
--
-- Medição da semana de 02 a 08/11 (cap_rep_hora × tab_intraday_base):
--   - SR com 2 páginas rende quase igual a 1 (meta 83% × 85%); com 3+ a mesma página perde ~14 pontos;
--   - fãs/h acima do teto (31,5) não derrubaram meta nem SLA (SLA 5 min 79% → 76%).
-- Cenário escolhido: teto +30%, no máximo 2 páginas por posto e sem desconto de capacidade na 2ª página.
--   limite_padrao 35 → 45,5 · limite_compartilhada 28 → 36,4 · teto_apoio 40 → 52 · teto_pico_fans_rep 40 → 52
--   max_paginas_rep 3 → 2 · desconto_mesma_modelo 0,10 → 0 · desconto_modelo_diferente 0,20 → 0
--   receita_triple 16000 → 0 (sem grupos de 3 pela regra de faturamento; abaixo de 32k segue em pares)
--
-- Postos fixos (fc_posto_fixo): distribuição nova, simulada com a carga de 11/11
-- (resultados/capacity_teto_1111/sim_fixo.py e postos_fixos_novos_1111.csv):
--   - exclusivas (esc_pagina_config.alocacao = EXCLUSIVA) ficam como estavam, com o assistente fixo;
--   - Bianca + Alexis Clark (ES_139) e Ivy Vale + Savannah (Fanvue) mantidas;
--   - OFTV desconsiderada vai de apoio com a página principal da mesma modelo;
--   - páginas que já saíram (Midwest Emma, Janet, Aria, Livinia, Lexis Star, Liv, Becky Recme) não entram.
--   Resultado: 57 postos por turno (U1 22, U2 17, U3 18), contra 61 hoje.
-- Vigência: a partir de hoje no Capacity (a escala só muda no próximo Gerar; a semana de 16/11, plano 140,
-- precisa ser gerada de novo, e a de 23/11 também). As linhas antigas ficam com vigente_ate = 2026-11-11.
-- Para voltar: apagar as linhas com vigente_desde = 2026-11-11 e obs 'Script 38%', voltar vigente_ate = NULL
-- nas antigas, voltar os parâmetros e recalcular.
--
-- Rodar pelo mysql.exe com o 5c.cnf (CALL).
-- =====================================================================

USE `5c_planning`;

-- 0. Antes
SELECT 'antes' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.precisa, v.tem, v.saldo, v.buffer
  FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'antes' AS momento, COUNT(*) AS linhas, COUNT(DISTINCT carteira, grupo) AS grupos FROM fc_posto_fixo WHERE vigente_ate IS NULL;
SELECT 'antes' AS momento, chave, valor FROM fc_parametros
 WHERE chave IN ('limite_padrao','limite_compartilhada','teto_apoio','teto_pico_fans_rep','max_paginas_rep','desconto_mesma_modelo','desconto_modelo_diferente','receita_triple');

START TRANSACTION;

-- 1. Parâmetros
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('limite_padrao', 45.5, 'Fãs/h por rep no posto (script 38: 35 × 1,3; acima disso entra assistente)'),
 ('limite_compartilhada', 36.4, 'Página com até 36,4 fãs/h (28 × 1,3) pode dividir rep com outra; acima disso tem rep próprio (script 38)'),
 ('teto_apoio', 52, 'Acima disto (fãs/h no turno) o titular fica com o limite_padrao e o excedente vira apoio (script 38: 40 × 1,3)'),
 ('teto_pico_fans_rep', 52, 'Só conta como pico o que passa de 52 fãs/h por rep (script 38: 40 × 1,3)'),
 ('max_paginas_rep', 2, 'Máximo de páginas por rep (script 38: 3 → 2; com 3+ a meta da página cai ~14 pontos)'),
 ('desconto_mesma_modelo', 0, 'Perda de capacidade por página a mais da mesma modelo (script 38: 0,10 → 0)'),
 ('desconto_modelo_diferente', 0, 'Perda de capacidade por página a mais de outra modelo (script 38: 0,20 → 0)'),
 ('receita_triple', 0, 'Faturamento do mês anterior (USD) abaixo do qual a página trabalha 3 por rep; 0 = desligado (script 38)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- 2. Postos fixos: fecha a distribuição de 01/11 e grava a nova
UPDATE fc_posto_fixo SET vigente_ate = '2026-11-11' WHERE vigente_ate IS NULL;
INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs) VALUES
 ('U1C1', 1, 1, 'Mikayla Free', 2, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 2, 1, 'Mikayla Paid', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 3, 1, 'Rocket Reyna VIP', 2, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 3, 2, 'Rocket Reyna OFTV', 2, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 4, 1, 'Isla', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 4, 2, 'Mia Melons', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 5, 1, 'Coco Bunnie 260m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 5, 2, 'Charlie 306m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 6, 1, 'Taylor Ballard', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 6, 2, 'Tiny', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 7, 1, 'Diana Rider', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 7, 2, 'Silvia Saige VIP', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 7, 3, 'zOFTV Diana Rider', 1, 1, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 8, 1, 'Courtney', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 8, 2, 'Levi Free', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 9, 1, 'Sumner', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 9, 2, 'Silvia Saige Free', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C1', 10, 1, 'Levi Paid', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 1, 1, 'Sisi Rose', 2, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 2, 1, 'Holly Jane', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 3, 1, 'Jena Wolfy VIP 001m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 3, 2, 'Thalia 147m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 3, 3, 'Jena Wolfy OFTV 001t', 1, 1, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 3, 4, 'Thalia 147t', 1, 1, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 4, 1, 'Maya', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 4, 2, 'Jena Wolfy Free 001s', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 5, 1, 'Mrs Robinson 303m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 5, 2, 'Blaire', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 6, 1, 'Dakota', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 6, 2, 'Charly', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 7, 1, 'Rebecca', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 7, 2, 'Rebecca OFTV', 1, 1, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 8, 1, 'Natasha Nice VIP', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 8, 2, 'Krystal 141m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 8, 3, 'Krystal 141t', 1, 1, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 9, 1, 'Angel', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U1C2', 9, 2, 'Tiff 176m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 1, 1, 'Nicole Doshi', 2, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 1, 2, 'Nicole OFTV', 2, 1, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 2, 1, 'Molly Stewart VIP', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 2, 2, 'zOFTV Molly Stewart', 1, 1, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 3, 1, 'Ariana Marie', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 4, 1, 'Marie Dee VIP', 1, 0, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 5, 1, 'CJ Miles', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 6, 1, 'Alix Lynx', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 6, 2, 'CJ VIP', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 7, 1, 'Blair VIP 155m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C1', 7, 2, 'Blair OFTV 155t', 1, 1, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 1, 1, 'Brandi Love', 1, 0, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 2, 1, 'Draya', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 2, 2, 'Keera', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 3, 1, 'Sarah H 329m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 3, 2, 'Victorya 188m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 4, 1, 'Matty Caron', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 4, 2, 'Sebi Barraza', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 5, 1, 'Apex VIP', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 5, 2, 'Apex Free', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 6, 1, 'Mo Saffari', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 6, 2, 'Brandi Love FREE', 1, 0, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 7, 1, 'Ellie D', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 7, 2, 'Aline Novak', 1, 0, '5C Company · Privacy', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 8, 1, 'Sophie Sierra', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 8, 2, 'Sean Adams', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U2C2', 9, 1, 'Jay', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 1, 1, 'Cynthia', 2, 0, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 2, 1, 'Elly Clutch 100m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 3, 1, 'Britt VIP', 1, 0, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 3, 2, 'Britt OFTV', 1, 1, 'Elysium', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 4, 1, 'Kaylin', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 4, 2, 'Kas', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 5, 1, 'Ms Red VIP', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 5, 2, 'Ms Red Free', 1, 0, 'Timeless', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 6, 1, 'Bianca', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 6, 2, 'Alexis Clark', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C1', 7, 1, 'Isabel', 1, 0, NULL, '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 1, 1, 'Madison Moore VIP 115m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 1, 2, 'Madison Moore Free 115s', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 2, 1, 'Mario Adrion', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 2, 2, 'Eden The Doll', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 3, 1, 'Abg', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 3, 2, 'Melody', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 4, 1, 'Blake', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 4, 2, 'Liam', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 5, 1, 'Aja', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 5, 2, 'Mandi CAT', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 5, 3, 'MandiCat OFTV', 1, 1, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 6, 1, 'Katie Flood', 1, 0, 'Verge', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 6, 2, 'Aja Free', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 7, 1, 'Jessie', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 7, 2, 'Lily Lou OFTV', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 8, 1, 'Goddess Sylvanas 150m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 8, 2, 'Lily Cherry', 1, 0, NULL, '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 9, 1, 'Ivy Vale', 1, 0, 'Fanvue', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 9, 2, 'Savannah', 1, 0, 'Fanvue', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 10, 1, 'Haven  Free', 1, 0, '5C Company', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª'),
 ('U3C2', 10, 2, 'Daniswings 157m', 1, 0, 'Fatbear', '2026-11-11', NULL, 'Script 38: teto +30%, máx. 2 páginas, sem desconto na 2ª');

COMMIT;

-- 3. Recalcula hoje
CALL sp_fc_recalcular_postos(CURDATE(), 'Postos: teto +30%, máx. 2 páginas, sem desconto na 2ª (script 38)');

-- 4. Depois
SELECT 'depois' AS momento, COUNT(*) AS linhas, COUNT(DISTINCT carteira, grupo) AS grupos FROM fc_posto_fixo WHERE vigente_ate IS NULL;
SELECT 'depois' AS momento, turno, COUNT(*) AS postos FROM fc_postos WHERE data_calculo = CURDATE() GROUP BY turno;
SELECT 'depois' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.obs, v.precisa, v.tem, v.saldo, v.buffer
  FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM fc_capacity_versao_unidade u
 WHERE u.versao = (SELECT MAX(versao) FROM fc_capacity_versao) ORDER BY u.unidade, u.turno;
-- páginas com carga hoje que ficaram fora dos postos fixos (seguem a regra geral)
SELECT 'fora do fixo' AS momento, c.carteira, c.pagina, ROUND(MAX(c.fans_h), 1) AS fas_h FROM fc_pagina_carga c
 WHERE c.data_calculo = CURDATE() AND c.ocupa_posto = 1
   AND NOT EXISTS (SELECT 1 FROM fc_posto_fixo x WHERE x.pagina = c.pagina AND x.carteira = c.carteira AND x.vigente_ate IS NULL)
 GROUP BY c.carteira, c.pagina;
