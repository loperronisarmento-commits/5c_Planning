-- =====================================================================
-- ES_71_trocas_antigas.sql  (01/11)
-- As trocas de unidade de 31/10 (ES_51: Mikayla Free e Paid para a U3; Nicole Doshi e OFTV para a
-- U1) continuavam APLICADA. A redistribuição de 01/11 (ES_61/62) gravou as trocas contrárias, mas o
-- "Gerar" das 19:36 reaplicou as antigas (postos POL20261101): Mikayla foi para a U3 e Nicole para
-- a U1. As quatro ficam DESCARTADA; a 1.18.2 só considera a troca mais recente de cada página.
-- Depois: "Gerar" de novo a semana de 02/11 (a sincronização desfaz os postos POL20261101).
-- Já aplicado em 01/11 pelo MCP.
-- =====================================================================

SET NAMES utf8mb4;

UPDATE `5c_escalas`.`esc_politica_troca`
   SET status = 'DESCARTADA', motivo = LEFT(CONCAT('Substituída pela redistribuição de 01/11 (ES_61/62); ', IFNULL(motivo, '')), 255)
 WHERE id IN (1, 2, 3, 4) AND status = 'APLICADA' AND tipo = 'UNIDADE';
