-- =====================================================================
-- ES_51_trocas_unidade_e_regerar_0211.sql  (31/10)
-- Primeira geração da estrutura nova: as 2 trocas de unidade do Equilíbrio entram
-- já em 02/11, sem esperar os 28 dias de maturação (ninguém trabalhou ainda na
-- estrutura nova). A modelo vai junta:
--   - Mikayla Free + Mikayla Paid: U1 (U1C2) → U3 (U3C1);
--   - Nicole Doshi + Nicole OFTV:  U2 (U2C2) → U1 (U1C2).
-- Faturamento de 30 dias por unidade: U1 2,01 → 1,68 mi; U2 1,84 → 1,65 mi;
-- U3 1,20 → 1,71 mi (todas a até 2% da média; antes +19%, +10% e −29%).
-- Também tira a v22.0 da semana de 02/11 de publicada (nada foi postado), para o
-- "Gerar" sair do zero, e recalcula o Capacity.
-- Mesmo padrão da tela (carteira_sair / carteira_entrar). Pode rodar de novo.
-- Depois: Gerar escala › semana de 02/11 › Gerar; conferir, publicar e postar.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Trocas de unidade a partir de 02/11 (a linha atual fecha em 02/11, a nova começa em 02/11)
USE `5c_planning`;
START TRANSACTION;

INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT x.pagina, '2026-11-02', NULL, x.para, c.modelo, c.regra, c.ordem, c.fas_h_estimado, 'Equilíbrio das unidades: primeira geração (ES_51)'
  FROM (SELECT 'Mikayla Free' AS pagina, 'U1C2' AS de, 'U3C1' AS para UNION ALL SELECT 'Mikayla Paid', 'U1C2', 'U3C1'
        UNION ALL SELECT 'Nicole Doshi', 'U2C2', 'U1C2' UNION ALL SELECT 'Nicole OFTV', 'U2C2', 'U1C2') x
  JOIN fc_pagina_carteira c ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci AND c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

UPDATE fc_pagina_carteira c
  JOIN (SELECT 'Mikayla Free' AS pagina, 'U1C2' AS de UNION ALL SELECT 'Mikayla Paid', 'U1C2'
        UNION ALL SELECT 'Nicole Doshi', 'U2C2' UNION ALL SELECT 'Nicole OFTV', 'U2C2') x
    ON c.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND c.carteira = x.de COLLATE utf8mb4_unicode_ci
   SET c.vigente_ate = GREATEST(c.vigente_desde, '2026-11-02'), c.obs = 'Equilíbrio das unidades: saiu da carteira (ES_51)'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-02';

COMMIT;

-- 2. Registro das trocas (política de páginas: carência e limite por semana)
USE `5c_escalas`;
INSERT INTO esc_politica_troca (tipo, pagina, turno, de_ref, para_ref, status, vale_desde, motivo)
SELECT 'UNIDADE', x.pagina, NULL, x.de, x.para, 'APLICADA', '2026-11-02', 'Equilíbrio das unidades na primeira geração (ES_51)'
  FROM (SELECT 'Mikayla Free' AS pagina, '1' AS de, '3' AS para UNION ALL SELECT 'Mikayla Paid', '1', '3'
        UNION ALL SELECT 'Nicole Doshi', '2', '1' UNION ALL SELECT 'Nicole OFTV', '2', '1') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_politica_troca t WHERE t.tipo = 'UNIDADE' AND t.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND t.vale_desde = '2026-11-02');

-- 3. A v22.0 (semana de 02/11) deixa de ser a publicada: o próximo Gerar sai do zero
UPDATE esc_plano SET status = 'DESCARTADO' WHERE id = 69 AND semana_inicio = '2026-11-02' AND status = 'PUBLICADO';

-- 4. Capacity recalculado com as carteiras novas
CALL `5c_planning`.`sp_fc_recalcular_postos`('2026-10-31', 'ES_51: trocas de unidade do Equilíbrio');

-- Conferência
SELECT p.pagina, p.carteira, p.vigente_desde, p.vigente_ate FROM `5c_planning`.`fc_pagina_carteira` p
 WHERE p.pagina IN ('Mikayla Free', 'Mikayla Paid', 'Nicole Doshi', 'Nicole OFTV') ORDER BY p.pagina, p.vigente_desde;
SELECT id, versao, subversao, status FROM esc_plano WHERE semana_inicio = '2026-11-02' ORDER BY id DESC LIMIT 3;
