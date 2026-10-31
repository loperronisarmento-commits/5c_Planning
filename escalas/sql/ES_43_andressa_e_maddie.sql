SET NAMES utf8mb4;
-- =====================================================================
-- ES_43 · Andressa Urach e Maddie de volta à estrutura (31/10)
--
-- 1. Andressa Urach começa em 02/11. O Capacity de hoje olha a estrutura de 01/11, então ela
--    não entrou no cálculo, e a atualização das 23:59 fechou os postos dela (A, C e D): sem
--    escala na semana 02/11. Corrigido no código (1.14.1): posto de página que só começa
--    depois da referência do Capacity fica como está. Aqui os 3 postos e a equipe voltam a
--    valer de 02/11.
-- 2. Maddie (ativa no site desde 30/10, troca de nome da Maddie Cavill) está na carteira, mas a
--    foto diária de páginas ativas do Capacity de 30/10 marca a página como inativa, e o
--    Capacity ignora página inativa na foto. Aqui a foto de 30/10 passa a marcá-la ativa, como
--    está no site (5c_pages.active = 1).
-- Depois: Recalcular 26/10 e 02/11 (o Capacity é recalculado antes e a Maddie ganha posto).
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- 1. Andressa
UPDATE `esc_vaga_equipe` SET vigente_desde = '2026-11-02', vigente_ate = NULL
 WHERE posto_id IN (434, 435, 436) AND vigente_desde = '2026-10-29' AND vigente_ate = '2026-10-29';
UPDATE `esc_posto` SET vigente_desde = '2026-11-02', vigente_ate = NULL
 WHERE id IN (434, 435, 436) AND versao = 'ES32_ANDRESSA' AND vigente_ate = '2026-10-29';

-- 2. Maddie ativa na foto de 30/10 (só se está ativa no site)
UPDATE `5c_planning`.`fc_pagina_status_diario` s
  JOIN `5c_pages`.`pages` g ON g.name = s.pagina AND g.active = 1
   SET s.ativo = 1
 WHERE s.pagina = 'Maddie' AND s.dia = '2026-10-30' AND s.ativo = 0;

COMMIT;

-- ---------- Conferência ----------
SELECT 'postos da Andressa a partir de 02/11 (esperado 3)' AS item, COUNT(*) AS n FROM `esc_posto`
 WHERE id IN (434, 435, 436) AND vigente_desde = '2026-11-02' AND vigente_ate IS NULL
UNION ALL SELECT 'Maddie ativa na foto de 30/10 (esperado 1)', MAX(ativo) FROM `5c_planning`.`fc_pagina_status_diario`
 WHERE pagina = 'Maddie' AND dia = '2026-10-30';
