-- =====================================================================
-- ES_62_ajuste_unidades_usuario_0211.sql  (01/11)
-- Ajuste do usuário sobre o ES_61, a partir de 02/11 (já aplicado em 01/11 pelo MCP):
--   Mikayla Free e Paid → U1 (U1C1); Nicole Doshi e Nicole OFTV → U2 (U2C1);
--   CJ Miles e CJ VIP → U2 (U2C1); Cynthia → U3 (U3C1); Ms Red Free e VIP → U3 (U3C1).
--   Ficam: Sisi Rose na U1, Midwest Emma e Ariana Marie na U2, Elly Clutch na U3.
--   Compensação (queda acima de 15% em 30 dias), U3 → U2C1: Blair VIP e Blair OFTV, Jay,
--   Sophie Sierra e Sebi Barraza.
-- Contagem sem as OFTV com menos de US$ 8 mil em 30 dias: U1 35 páginas, US$ 1,73 mi;
-- U2 27, US$ 1,68 mi; U3 32, US$ 1,67 mi (maior diferença 3,7%).
-- Depois: Gerar a semana de 02/11 no site (recalcula o Capacity antes).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_planning`;

-- Linhas que já começam em 02/11 (ES_61): troca a carteira na própria linha
UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Mikayla Free' AS pagina, 'U2C1' AS de, 'U1C1' AS para UNION ALL SELECT 'Mikayla Paid', 'U2C1', 'U1C1'
        UNION ALL SELECT 'Cynthia', 'U1C1', 'U3C1' UNION ALL SELECT 'Nicole Doshi', 'U3C1', 'U2C1' UNION ALL SELECT 'Nicole OFTV', 'U3C1', 'U2C1'
        UNION ALL SELECT 'CJ Miles', 'U1C2', 'U2C1' UNION ALL SELECT 'CJ VIP', 'U1C2', 'U2C1' UNION ALL SELECT 'Blair OFTV 155t', 'U3C1', 'U2C1'
        UNION ALL SELECT 'Blair VIP 155m', 'U3C1', 'U2C1' UNION ALL SELECT 'Jay', 'U3C1', 'U2C1') x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.carteira = x.para, c.obs = 'Ajuste do usuário em 01/11 (ES_62)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde = '2026-11-02';

-- As outras: a linha atual fecha em 02/11 e a nova começa em 02/11
INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT x.pagina, '2026-11-02', NULL, x.para, c.modelo, c.regra, c.ordem, c.fas_h_estimado, 'Ajuste do usuário em 01/11 (ES_62)'
  FROM (SELECT 'Ms Red Free' AS pagina, 'U1C1' AS de, 'U3C1' AS para UNION ALL SELECT 'Ms Red VIP', 'U1C1', 'U3C1'
        UNION ALL SELECT 'Sophie Sierra', 'U3C1', 'U2C1' UNION ALL SELECT 'Sebi Barraza', 'U3C1', 'U2C1') x
  JOIN fc_pagina_carteira c ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci AND c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Ms Red Free' AS pagina, 'U1C1' AS de UNION ALL SELECT 'Ms Red VIP', 'U1C1' UNION ALL SELECT 'Sophie Sierra', 'U3C1' UNION ALL SELECT 'Sebi Barraza', 'U3C1') x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.vigente_ate = '2026-11-02', c.obs = 'Ajuste do usuário em 01/11: saiu da carteira (ES_62)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

-- Registro das trocas de unidade (a troca do ES_61 passa a apontar o destino final; a que voltou
-- para a unidade de origem deixa de ser troca)
USE `5c_escalas`;
UPDATE esc_politica_troca t
  JOIN (SELECT 'Mikayla Free' AS pagina, '1' AS para UNION ALL SELECT 'Mikayla Paid', '1' UNION ALL SELECT 'Cynthia', '3'
        UNION ALL SELECT 'Nicole Doshi', '2' UNION ALL SELECT 'Nicole OFTV', '2' UNION ALL SELECT 'CJ Miles', '2' UNION ALL SELECT 'CJ VIP', '2'
        UNION ALL SELECT 'Blair OFTV 155t', '2' UNION ALL SELECT 'Blair VIP 155m', '2' UNION ALL SELECT 'Jay', '2') x
    ON t.pagina = x.pagina COLLATE utf8mb4_unicode_ci
   SET t.para_ref = x.para, t.motivo = 'Redistribuição pelo Legacy e equilíbrio (ES_61), ajustada pelo usuário (ES_62)'
 WHERE t.tipo = 'UNIDADE' AND t.vale_desde = '2026-11-02' AND t.motivo LIKE '%ES_61%';
INSERT INTO esc_politica_troca (tipo, pagina, turno, de_ref, para_ref, status, vale_desde, motivo)
SELECT 'UNIDADE', x.pagina, NULL, x.de, x.para, 'APLICADA', '2026-11-02', 'Ajuste do usuário em 01/11 (ES_62)'
  FROM (SELECT 'Ms Red Free' AS pagina, '1' AS de, '3' AS para UNION ALL SELECT 'Ms Red VIP', '1', '3'
        UNION ALL SELECT 'Sophie Sierra', '3', '2' UNION ALL SELECT 'Sebi Barraza', '3', '2') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_politica_troca t WHERE t.tipo = 'UNIDADE' AND t.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND t.vale_desde = '2026-11-02' AND t.motivo LIKE '%ES_62%');
DELETE FROM esc_politica_troca WHERE tipo = 'UNIDADE' AND vale_desde = '2026-11-02' AND motivo LIKE '%ES_62%' AND de_ref = para_ref;
