SET NAMES utf8mb4;
-- =====================================================================
-- ES_225 · Divisão de páginas da U1 igual à lista do usuário de 24/11 (pedido do usuário; via mysql.exe)
-- Lista:
--   U1C1 (7 postos): Mikayla Paid · Jadeteen · Natasha · Silvia Saige · Thalia + Charlie · Diana Rider · Levi + Sumner
--   U1C2 (8 postos): Isla Moon · Holly Jane · Tiff + Ava Louise · Jena Wolfy + Coco · Krystal + Mrs Robinson ·
--                    Angel + Blaire · Maya + Victoria · Dakota + Charly
-- Mudanças contra o que estava (ES_165, ES_187, combos de 21/11):
--   - Isla Moon: U1C1 → U1C2 (carteira e posto próprio; agência BKA);
--   - Mrs Robinson 303m sai do posto da Jena Wolfy + Coco e vai para o da Krystal 141m.
-- Vale de 24/11 (o Gerar sincroniza os postos pelo Capacity de hoje). A equipe fixa da Isla Moon (ES_197) não muda.
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, f.carteira, f.grupo, GROUP_CONCAT(CONCAT(f.pagina, IF(f.apoio = 1, ' [apoio]', '')) ORDER BY f.seq SEPARATOR ' + ') AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' GROUP BY f.carteira, f.grupo ORDER BY f.carteira, f.grupo;

START TRANSACTION;

-- Carteira: Isla Moon vai para a U1C2
INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-24', NULL, 'U1C2', modelo, regra, ordem, fas_h_estimado, 'Pedido do usuário em 24/11: U1C1 → U1C2 (ES_225)'
  FROM fc_pagina_carteira
 WHERE vigente_ate IS NULL AND carteira = 'U1C1' AND pagina = 'Isla Moon' AND vigente_desde < '2026-11-24';
UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-24'
 WHERE vigente_ate IS NULL AND carteira = 'U1C1' AND pagina = 'Isla Moon' AND vigente_desde < '2026-11-24';

-- Postos que mudam: U1C1 grupo 9 (Isla Moon) fecha; U1C2 grupos 2 e 5 refeitos; U1C2 grupo 8 novo (Isla Moon)
UPDATE fc_posto_fixo SET vigente_ate = '2026-11-24', obs = LEFT(CONCAT('ES_225: divisão da U1 de 24/11; ', IFNULL(obs, '')), 255)
 WHERE vigente_ate IS NULL AND vigente_desde < '2026-11-24'
   AND ((carteira = 'U1C1' AND grupo = 9) OR (carteira = 'U1C2' AND grupo IN (2, 5)));

INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs) VALUES
 ('U1C2', 2, 1, 'Jena Wolfy VIP 001m',  1, 0, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 2, 2, 'Jena Wolfy Free 001s', 1, 0, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 2, 3, 'Coco Bunnie 260m',     1, 0, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 2, 4, 'Jena Wolfy OFTV 001t', 1, 1, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 5, 1, 'Krystal 141m',         1, 0, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 5, 2, 'Mrs Robinson 303m',    1, 0, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 5, 3, 'Krystal 141t',         1, 1, 'Fatbear', '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11'),
 ('U1C2', 8, 1, 'Isla Moon',            1, 0, 'BKA',     '2026-11-24', NULL, 'ES_225: divisão da U1 de 24/11 (U1C1 → U1C2)');

COMMIT;

CALL sp_fc_recalcular_postos(CURDATE(), 'U1: divisão de páginas da lista do usuário de 24/11 (ES_225)');

SELECT 'depois' AS momento, f.carteira, f.grupo, MAX(f.pessoas) AS pessoas, GROUP_CONCAT(CONCAT(f.pagina, IF(f.apoio = 1, ' [apoio]', '')) ORDER BY f.seq SEPARATOR ' + ') AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' GROUP BY f.carteira, f.grupo ORDER BY f.carteira, f.grupo;
SELECT 'carteira' AS momento, pagina, carteira, vigente_desde FROM fc_pagina_carteira WHERE vigente_ate IS NULL AND pagina = 'Isla Moon';
SELECT 'fora do fixo (U1)' AS momento, c.carteira, c.pagina FROM fc_pagina_carga c
 WHERE c.data_calculo = CURDATE() AND c.carteira LIKE 'U1%' AND c.ocupa_posto = 1
   AND NOT EXISTS (SELECT 1 FROM fc_posto_fixo x WHERE x.pagina = c.pagina AND x.carteira = c.carteira AND x.vigente_ate IS NULL)
 GROUP BY c.carteira, c.pagina;
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM fc_capacity_versao_unidade u
 WHERE u.versao = (SELECT MAX(versao) FROM fc_capacity_versao) ORDER BY u.unidade, u.turno;
