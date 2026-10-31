SET NAMES utf8mb4;
-- =====================================================================
-- ES_42 · Código da página no site nos postos novos (31/10)
--
-- A atualização de estrutura das 23:59 criou os postos novos, mas buscou o código da
-- página no site (5c_pages) diferenciando maiúsculas: "Mandi CAT" (Capacity) não achou
-- "Mandi Cat" (site), nem "Eden The Doll" achou "Eden the Doll". Sem o código, o plano não
-- tem CSV da página e a postagem manda só apagar. Corrigido no código (1.14.1).
--
-- Este script preenche o código das páginas sem código nos postos que valem a partir de
-- 01/11 (a página ativa de mesmo nome; a comparação do MySQL não diferencia maiúsculas).
-- Depois: Recalcular as semanas 26/10 e 02/11 (o plano pega o código novo) e publicar.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE `esc_posto_pagina` pp
  JOIN `esc_posto` p ON p.id = pp.posto_id
  JOIN (SELECT name, MAX(id) AS id FROM `5c_pages`.`pages` WHERE active = 1 GROUP BY name) g ON g.name = pp.pagina
   SET pp.page_id = g.id
 WHERE pp.page_id IS NULL AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-01');

COMMIT;

-- ---------- Conferência ----------
SELECT 'páginas sem código nos postos a partir de 01/11 (esperado 0)' AS item, COUNT(*) AS n
  FROM `esc_posto_pagina` pp JOIN `esc_posto` p ON p.id = pp.posto_id
 WHERE pp.page_id IS NULL AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-01');
