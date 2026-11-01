-- =====================================================================
-- ES_61_redistribuicao_legacy_0211.sql  (01/11)
-- Redistribuição das páginas por unidade a partir de 02/11 (pedido do usuário em 01/11):
--   1. Privacy (Aline Novak, Andressa Urach) e Fanvue (Ivy Vale) com as mesmas pessoas: Aline e
--      Andressa ficam na U2, onde está quase todo o time delas (Ivy Vale segue o Legacy: U3).
--   2. Midwest Emma e Mikayla (Free e Paid) para a U2 (unidade do João Paulo, OM).
--   3. Cada página vai para a unidade de quem liderava a party dela no Legacy, se hoje é OM, OC ou
--      OS; senão, a de quem liderava o time. Mesma modelo junta. Eduardo Ehlke fora da regra.
--   4. Equilíbrio: saem da U2 Cynthia, CJ Miles, Diana Rider e Krystal (→ U1) e Nicole Doshi, Jay,
--      Blake, Liam, Kas e Kaylin (→ U3).
-- Faturamento de 30 dias: U1 1,71 → 1,68 mi; U2 1,66 → 1,71 mi; U3 1,71 → 1,69 mi (até 1,4%).
-- 62 páginas mudam. Mesmo padrão do ES_51. Depois: Gerar a semana de 02/11 no site.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_planning`;

-- 1a. Páginas com linha que já começa em 02/11: troca a carteira na própria linha
UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Mikayla Free' AS pagina, 'U3C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Mikayla Paid' AS pagina, 'U3C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Nicole Doshi' AS pagina, 'U1C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Nicole OFTV' AS pagina, 'U1C2' AS de, 'U3C1' AS para) x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.carteira = x.para, c.obs = 'Redistribuição pelo Legacy e equilíbrio (ES_61)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde = '2026-11-02';

-- 1b. As outras: a linha atual fecha em 02/11 e a nova começa em 02/11
INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT x.pagina, '2026-11-02', NULL, x.para, c.modelo, c.regra, c.ordem, c.fas_h_estimado, 'Redistribuição pelo Legacy e equilíbrio (ES_61)'
  FROM (SELECT 'Abg' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Aja' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Aja Free' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Alexis Clark' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Ariana Marie' AS pagina, 'U1C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Becky Recme' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Blair OFTV 155t' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Blair VIP 155m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'CJ Miles' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'CJ VIP' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Charlie 306m' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Coco Bunnie 260m' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Cynthia' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Draya' AS pagina, 'U3C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Eden The Doll' AS pagina, 'U1C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Elly Clutch 100m' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Holly Jane' AS pagina, 'U2C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Isla' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Ivy Vale' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet Free' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet VIP' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Jay' AS pagina, 'U2C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Jena Wolfy Free 001s' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy OFTV 001t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy VIP 001m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Katie Flood' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Kaylin' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Krystal 141m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Krystal 141t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Levi Free' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Levi Paid' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Lexis Star' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Liv' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Madison Moore Free 115s' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Madison Moore VIP 115m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Mandi CAT' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'MandiCat OFTV' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Marie Dee VIP' AS pagina, 'U3C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Mario Adrion' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Matty Caron' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Maya' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Melody' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Mia Melons' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Midwest Emma Free 221s' AS pagina, 'U1C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mo Saffari' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Molly Stewart VIP' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mrs Robinson 303m' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Silvia Saige Free' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Silvia Saige VIP' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Sumner' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Thalia 147m' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Thalia 147t' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Tiny' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Victorya 188m' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Yuni Yung' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'zOFTV Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'zOFTV Molly Stewart' AS pagina, 'U1C2' AS de, 'U2C2' AS para) x
  JOIN fc_pagina_carteira c ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci AND c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Abg' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Aja' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Aja Free' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Alexis Clark' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Ariana Marie' AS pagina, 'U1C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Becky Recme' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Blair OFTV 155t' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Blair VIP 155m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'CJ Miles' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'CJ VIP' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Charlie 306m' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Coco Bunnie 260m' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Cynthia' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Draya' AS pagina, 'U3C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Eden The Doll' AS pagina, 'U1C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Elly Clutch 100m' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Holly Jane' AS pagina, 'U2C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Isla' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Ivy Vale' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet Free' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet VIP' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Jay' AS pagina, 'U2C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Jena Wolfy Free 001s' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy OFTV 001t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy VIP 001m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Katie Flood' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Kaylin' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Krystal 141m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Krystal 141t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Levi Free' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Levi Paid' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Lexis Star' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Liv' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Madison Moore Free 115s' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Madison Moore VIP 115m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Mandi CAT' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'MandiCat OFTV' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Marie Dee VIP' AS pagina, 'U3C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Mario Adrion' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Matty Caron' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Maya' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Melody' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Mia Melons' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Midwest Emma Free 221s' AS pagina, 'U1C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mo Saffari' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Molly Stewart VIP' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mrs Robinson 303m' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Silvia Saige Free' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Silvia Saige VIP' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Sumner' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Thalia 147m' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Thalia 147t' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Tiny' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Victorya 188m' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Yuni Yung' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'zOFTV Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'zOFTV Molly Stewart' AS pagina, 'U1C2' AS de, 'U2C2' AS para) x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.vigente_ate = '2026-11-02', c.obs = 'Redistribuição pelo Legacy e equilíbrio: saiu da carteira (ES_61)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

-- 2. Registro das trocas de unidade
USE `5c_escalas`;
INSERT INTO esc_politica_troca (tipo, pagina, turno, de_ref, para_ref, status, vale_desde, motivo)
SELECT 'UNIDADE', x.pagina, NULL, SUBSTRING(x.de, 2, 1), SUBSTRING(x.para, 2, 1), 'APLICADA', '2026-11-02', 'Redistribuição pelo Legacy e equilíbrio (ES_61)'
  FROM (SELECT 'Abg' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Aja' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Aja Free' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Alexis Clark' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Ariana Marie' AS pagina, 'U1C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Becky Recme' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Blair OFTV 155t' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Blair VIP 155m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'CJ Miles' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'CJ VIP' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Charlie 306m' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Coco Bunnie 260m' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Cynthia' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Draya' AS pagina, 'U3C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Eden The Doll' AS pagina, 'U1C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Elly Clutch 100m' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Holly Jane' AS pagina, 'U2C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Isla' AS pagina, 'U3C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Ivy Vale' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet Free' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet VIP' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Jay' AS pagina, 'U2C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Jena Wolfy Free 001s' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy OFTV 001t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Jena Wolfy VIP 001m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Katie Flood' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Kaylin' AS pagina, 'U1C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Krystal 141m' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Krystal 141t' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Levi Free' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Levi Paid' AS pagina, 'U2C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Lexis Star' AS pagina, 'U2C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Liv' AS pagina, 'U2C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Madison Moore Free 115s' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Madison Moore VIP 115m' AS pagina, 'U2C1' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Mandi CAT' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'MandiCat OFTV' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Marie Dee VIP' AS pagina, 'U3C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Mario Adrion' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Matty Caron' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Maya' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Melody' AS pagina, 'U1C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Mia Melons' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Midwest Emma Free 221s' AS pagina, 'U1C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mo Saffari' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Molly Stewart VIP' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mrs Robinson 303m' AS pagina, 'U3C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Silvia Saige Free' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Silvia Saige VIP' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Sumner' AS pagina, 'U2C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Thalia 147m' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Thalia 147t' AS pagina, 'U2C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Tiny' AS pagina, 'U3C1' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Victorya 188m' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Yuni Yung' AS pagina, 'U1C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'zOFTV Diana Rider' AS pagina, 'U3C2' AS de, 'U1C2' AS para
        UNION ALL SELECT 'zOFTV Molly Stewart' AS pagina, 'U1C2' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Mikayla Free' AS pagina, 'U3C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Mikayla Paid' AS pagina, 'U3C1' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Nicole Doshi' AS pagina, 'U1C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Nicole OFTV' AS pagina, 'U1C2' AS de, 'U3C1' AS para) x
 WHERE SUBSTRING(x.de, 2, 1) <> SUBSTRING(x.para, 2, 1)
   AND NOT EXISTS (SELECT 1 FROM esc_politica_troca t WHERE t.tipo = 'UNIDADE' AND t.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND t.vale_desde = '2026-11-02' AND t.motivo LIKE '%ES_61%');

-- 3. Capacity recalculado com as carteiras novas. Em 01/11 não rodou por aqui: o "Gerar" do site
--    recalcula o Capacity antes de montar a escala (lib/estrutura.php). Rodar à mão só se precisar antes.
-- CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'ES_61: redistribuição pelo Legacy e equilíbrio');

-- Conferência: faturamento de 30 dias por unidade a partir de 02/11
SELECT LEFT(c.carteira, 2) AS unidade, COUNT(*) AS paginas, ROUND(SUM(IFNULL(f.fat, 0))) AS fat_30d
  FROM `5c_planning`.fc_pagina_carteira c
  LEFT JOIN (SELECT pagina, MAX(fat_30d) AS fat FROM `5c_planning`.fc_pagina_carga
              WHERE data_calculo = (SELECT MAX(data_calculo) FROM `5c_planning`.fc_pagina_carga) GROUP BY pagina) f ON f.pagina = c.pagina
 WHERE c.vigente_desde <= '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate > '2026-11-02')
 GROUP BY LEFT(c.carteira, 2);
