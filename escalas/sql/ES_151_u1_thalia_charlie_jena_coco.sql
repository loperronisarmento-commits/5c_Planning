SET NAMES utf8mb4;
-- =====================================================================
-- ES_151 · Postos da U1 (13/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
--   Thalia + Charlie na U1C1: Thalia 147m (com o apoio da Thalia 147t) sai da U1C2 e divide o posto com a
--     Charlie 306m (no lugar da Coco).
--   Jena Wolfy + Coco na U1C2: Coco Bunnie 260m sai da U1C1 e divide o posto com a Jena Wolfy VIP e a Jena Wolfy
--     Free (com o apoio da Jena Wolfy OFTV); a Maya, que dividia com a Jena Free, fica sozinha.
-- Vigência: 13/11 (carteiras e postos fixos). O número de postos da U1 não muda. A escala muda no próximo Gerar.
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, f.carteira, f.grupo, GROUP_CONCAT(f.pagina ORDER BY f.seq) AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' AND f.grupo IN (3, 4, 5) GROUP BY f.carteira, f.grupo;

START TRANSACTION;

-- Carteiras
INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-13', NULL, IF(pagina LIKE 'Thalia%', 'U1C1', 'U1C2'), modelo, regra, ordem, fas_h_estimado,
       CONCAT('Pedido do usuário em 13/11: ', IF(pagina LIKE 'Thalia%', 'U1C2 → U1C1, posto com a Charlie', 'U1C1 → U1C2, posto com a Jena Wolfy'), ' (ES_151)')
  FROM fc_pagina_carteira
 WHERE vigente_ate IS NULL AND ((pagina IN ('Thalia 147m', 'Thalia 147t') AND carteira = 'U1C2') OR (pagina = 'Coco Bunnie 260m' AND carteira = 'U1C1'));
UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-13'
 WHERE vigente_ate IS NULL AND vigente_desde < '2026-11-13'
   AND ((pagina IN ('Thalia 147m', 'Thalia 147t') AND carteira = 'U1C2') OR (pagina = 'Coco Bunnie 260m' AND carteira = 'U1C1'));

-- Postos fixos: fecha as linhas que mudam e grava as novas
UPDATE fc_posto_fixo SET vigente_ate = '2026-11-13', obs = 'ES_151: posto refeito'
 WHERE id IN (317, 332, 334, 336) AND vigente_ate IS NULL;
INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs) VALUES
 ('U1C1', 5, 1, 'Thalia 147m',          1, 0, 'Fatbear', '2026-11-13', NULL, 'ES_151: Thalia + Charlie'),
 ('U1C1', 5, 3, 'Thalia 147t',          1, 1, 'Fatbear', '2026-11-13', NULL, 'ES_151: apoio da Thalia'),
 ('U1C2', 3, 2, 'Jena Wolfy Free 001s', 1, 0, 'Fatbear', '2026-11-13', NULL, 'ES_151: Jena Wolfy + Coco'),
 ('U1C2', 3, 4, 'Coco Bunnie 260m',     1, 0, 'Fatbear', '2026-11-13', NULL, 'ES_151: Jena Wolfy + Coco');

COMMIT;

CALL sp_fc_recalcular_postos(CURDATE(), 'U1: Thalia + Charlie na U1C1, Jena Wolfy + Coco na U1C2 (ES_151)');

SELECT 'depois' AS momento, f.carteira, f.grupo, GROUP_CONCAT(CONCAT(f.pagina, IF(f.apoio = 1, ' [apoio]', '')) ORDER BY f.seq SEPARATOR ' + ') AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' GROUP BY f.carteira, f.grupo ORDER BY f.carteira, f.grupo;
SELECT 'depois' AS momento, pp.carteira, pp.turno, pp.posto, GROUP_CONCAT(pp.pagina) AS paginas FROM fc_postos_pagina pp
 WHERE pp.data_calculo = CURDATE() AND pp.turno = 'C' AND pp.carteira LIKE 'U1%'
   AND pp.posto IN (SELECT x.posto FROM fc_postos_pagina x WHERE x.data_calculo = CURDATE() AND x.turno = 'C' AND x.carteira = pp.carteira
                     AND x.pagina IN ('Thalia 147m', 'Coco Bunnie 260m', 'Maya'))
 GROUP BY pp.carteira, pp.turno, pp.posto;
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
