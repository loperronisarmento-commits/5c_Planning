-- =====================================================================
-- ES_64_postos_agencia_60_40.sql  (01/11)
-- Distribuição final de 01/11 (já aplicada pelo MCP), a partir de 02/11, sobre o ES_61/62/63:
--   - páginas < US$ 16 mil: no mínimo 3 por posto; < US$ 32 mil: no mínimo 2; mesma agência junta
--     (Independent e Lolly Agency contam como 5C Company); OFTV < US$ 8 mil fica de apoio;
--   - Savannah (Fanvue) vai com a Ivy Vale para a U3C2;
--   - em cada unidade a C1 fica com ~60% do faturamento e a C2 com ~40%, e a C1 tem o mesmo
--     número de postos da C2 ou 1 a mais (posto = 1 pessoa; página acima de 35 fãs/h no turno de
--     maior volume ocupa 2 postos).
-- 33 páginas trocam de carteira (só a Savannah troca de unidade).
-- Os postos em si o Capacity monta pela regra dele no próximo recálculo ("Gerar" do site).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_planning`;

UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Aja' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Aja Free' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Alix Lynx' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Apex Free' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Apex VIP' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Britt OFTV' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Britt VIP' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Diana Rider' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Eden The Doll' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Ellie D' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Elly Clutch 100m' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Goddess Sylvanas 150m' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Haven  Free' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet Free' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Janet VIP' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Jay' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Jessie' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Katie Flood' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Lily Lou OFTV' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Liv' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Madison Moore Free 115s' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Madison Moore VIP 115m' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Mia Melons' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Midwest Emma Free 221s' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Molly Stewart VIP' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Natasha Nice VIP' AS pagina, 'U1C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Savannah' AS pagina, 'U1C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Sebi Barraza' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Sophie Sierra' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Taylor Ballard' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Victorya 188m' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'zOFTV Diana Rider' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'zOFTV Molly Stewart' AS pagina, 'U2C2' AS de, 'U2C1' AS para) x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.carteira = x.para, c.obs = 'Postos por agência e C1 60% / C2 40% (ES_64)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde = '2026-11-02';

INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT x.pagina, '2026-11-02', NULL, x.para, c.modelo, c.regra, c.ordem, c.fas_h_estimado, 'Postos por agência e C1 60% / C2 40% (ES_64)'
  FROM (SELECT 'Aja' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Aja Free' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Alix Lynx' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Apex Free' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Apex VIP' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Britt OFTV' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Britt VIP' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Diana Rider' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Eden The Doll' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Ellie D' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Elly Clutch 100m' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Goddess Sylvanas 150m' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Haven  Free' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Janet Free' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Janet VIP' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Jay' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Jessie' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Katie Flood' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Lily Lou OFTV' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Liv' AS pagina, 'U3C2' AS de, 'U3C1' AS para
        UNION ALL SELECT 'Madison Moore Free 115s' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Madison Moore VIP 115m' AS pagina, 'U3C1' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Mia Melons' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Midwest Emma Free 221s' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Molly Stewart VIP' AS pagina, 'U2C2' AS de, 'U2C1' AS para
        UNION ALL SELECT 'Natasha Nice VIP' AS pagina, 'U1C1' AS de, 'U1C2' AS para
        UNION ALL SELECT 'Savannah' AS pagina, 'U1C2' AS de, 'U3C2' AS para
        UNION ALL SELECT 'Sebi Barraza' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Sophie Sierra' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'Taylor Ballard' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'Victorya 188m' AS pagina, 'U2C1' AS de, 'U2C2' AS para
        UNION ALL SELECT 'zOFTV Diana Rider' AS pagina, 'U1C2' AS de, 'U1C1' AS para
        UNION ALL SELECT 'zOFTV Molly Stewart' AS pagina, 'U2C2' AS de, 'U2C1' AS para) x
  JOIN fc_pagina_carteira c ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci AND c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

UPDATE fc_pagina_carteira c
  JOIN (SELECT pagina, carteira AS para FROM fc_pagina_carteira WHERE vigente_desde = '2026-11-02' AND vigente_ate IS NULL AND obs = 'Postos por agência e C1 60% / C2 40% (ES_64)') n ON n.pagina = c.pagina
   SET c.vigente_ate = '2026-11-02', c.obs = 'Postos por agência e C1 60% / C2 40%: saiu da carteira (ES_64)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02' AND c.carteira <> n.para;

USE `5c_escalas`;
INSERT INTO esc_politica_troca (tipo, pagina, turno, de_ref, para_ref, status, vale_desde, motivo)
SELECT 'UNIDADE', 'Savannah', NULL, '1', '3', 'APLICADA', '2026-11-02', 'Fanvue: mesmo time da Ivy Vale (ES_64)' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM esc_politica_troca WHERE tipo = 'UNIDADE' AND pagina = 'Savannah' AND vale_desde = '2026-11-02');
