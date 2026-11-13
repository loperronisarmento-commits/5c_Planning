SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_40_unidade_vencel.sql  (12/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
--
-- Unidade "Vencel": agência externa para a qual a 5C terceiriza parte da mão de obra.
--   1. fc_carteira: carteira VENC (unidade 4, "Vencel (terceirizada)") com ativo = 0. A página fica registrada
--      como da Vencel, mas não entra na carga, nos postos nem no "precisa" da 5C (o sp_fc_postos só lê
--      carteiras ativas), nem no Funil e na estrutura das escalas.
--   2. Courtney, Tiny, Mia Melons e Isla saem da U1C1 e vão para a VENC a partir de 12/11 (a Vencel assume hoje
--      às 21h, no turno D).
--   3. Postos fixos da U1C1: os postos dessas páginas fecham; a Levi Free (com a Courtney) passa para o posto da
--      Levi Paid (mesma modelo) e a Taylor Ballard (com a Tiny) fica sozinha. A U1C1 perde 2 postos por turno.
--   4. Recalcula o Capacity de hoje.
-- A escala só muda no próximo Gerar (semanas de 16/11 e 23/11). Para voltar uma página: fechar a linha da VENC
-- e abrir de novo na carteira de origem.
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);

START TRANSACTION;

INSERT INTO fc_carteira (carteira, unidade, codigo, nome, maior_pagina, ativo) VALUES ('VENC', 4, 'VE', 'Vencel (terceirizada)', 'Courtney', 0)
ON DUPLICATE KEY UPDATE unidade = VALUES(unidade), nome = VALUES(nome), ativo = 0;

INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-12', NULL, 'VENC', modelo, regra, ordem, fas_h_estimado,
       'Terceirizada para a Vencel em 12/11/2026 às 21h (antes U1C1) (script 40)'
  FROM fc_pagina_carteira
 WHERE pagina IN ('Courtney', 'Tiny', 'Mia Melons', 'Isla') AND carteira = 'U1C1' AND vigente_ate IS NULL;
UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-12'
 WHERE pagina IN ('Courtney', 'Tiny', 'Mia Melons', 'Isla') AND carteira = 'U1C1' AND vigente_ate IS NULL;

UPDATE fc_posto_fixo SET vigente_ate = '2026-11-12', obs = 'Script 40: página terceirizada para a Vencel'
 WHERE carteira = 'U1C1' AND pagina IN ('Courtney', 'Tiny', 'Mia Melons', 'Isla') AND vigente_ate IS NULL;
UPDATE fc_posto_fixo SET grupo = 10, seq = 2, obs = 'Script 40: com a Levi Paid (a Courtney foi para a Vencel)'
 WHERE carteira = 'U1C1' AND pagina = 'Levi Free' AND vigente_ate IS NULL;

COMMIT;

CALL sp_fc_recalcular_postos(CURDATE(), 'Unidade Vencel: Courtney, Tiny, Mia Melons e Isla terceirizadas (script 40)');

SELECT 'depois' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM fc_pagina_carteira
 WHERE pagina IN ('Courtney', 'Tiny', 'Mia Melons', 'Isla') ORDER BY pagina, vigente_desde;
SELECT 'depois' AS momento, f.grupo, GROUP_CONCAT(f.pagina ORDER BY f.seq) AS paginas FROM fc_posto_fixo f
 WHERE f.carteira = 'U1C1' AND f.vigente_ate IS NULL GROUP BY f.grupo;
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM fc_capacity_versao_unidade u
 WHERE u.versao = (SELECT MAX(versao) FROM fc_capacity_versao) ORDER BY u.unidade, u.turno;
