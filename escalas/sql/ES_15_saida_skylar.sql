-- =====================================================================
-- ES_15_saida_skylar.sql  (28/10)
-- A Skylar saiu: a página "Skylar Mae VIP" deixa de ter postos a partir
-- de 01/11. Eram 4 postos por turno (1 titular + 3 assistentes) na U1C1,
-- 12 no total, sem nenhuma outra página junto.
--   1. Fecha os 12 postos e as equipes deles (vigência só em 31/10, igual
--      ao ES_10 fez com os postos antigos). A escala até 01/11 05:00 não muda.
--   2. Os SRs que eram donos desses postos ficam livres para o motor: cobrem
--      lacunas, os melhores vão para as páginas SS/A/B, e o resto vira buffer.
--   3. Três deles viram donos fixos dos postos da U1 que estavam sem dono,
--      no núcleo certo (o motor também os usaria, mas assim a vaga fica fixa).
-- Pode rodar de novo. Depois: gerar de novo 26/10 e 02/11.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- 1. Postos e equipes da Skylar fora a partir de 01/11
UPDATE esc_vaga_equipe e
   SET e.vigente_desde = '2026-10-31', e.vigente_ate = '2026-10-31'
 WHERE e.posto_id IN (SELECT pp.posto_id FROM esc_posto_pagina pp WHERE pp.pagina = 'Skylar Mae VIP')
   AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-01');

UPDATE esc_posto p
   SET p.vigente_desde = '2026-10-31', p.vigente_ate = '2026-10-31'
 WHERE p.id IN (SELECT pp.posto_id FROM esc_posto_pagina pp WHERE pp.pagina = 'Skylar Mae VIP')
   AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-01');

-- 2. Donos fixos para postos da U1 sem dono (SR do núcleo dono, sem outra vaga)
--    289 U1C2 A seq 8  Maddie Cavill         dono A007546 (núcleo 3) · cobertura J058241 (núcleo 1)
--    278 U1C1 D seq 5  Bianca                dono B032241 (núcleo 8)
--    301 U1C2 D seq 3  Jena Wolfy VIP 001m   dono G457983 (núcleo 9)
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem)
SELECT n.posto_id, n.vaga, n.rep_code, 1.000, 1, 'PROPOSTA', 'saída da Skylar (ES_15)', '2026-11-01', 'MONTADOR'
  FROM (SELECT 289 AS posto_id, 'DONO' AS vaga, 'A007546' AS rep_code
        UNION ALL SELECT 289, 'COBERTURA', 'J058241'
        UNION ALL SELECT 278, 'DONO', 'B032241'
        UNION ALL SELECT 301, 'DONO', 'G457983') n
  JOIN esc_posto p ON p.id = n.posto_id AND p.vigente_ate IS NULL
 WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e
                    WHERE e.posto_id = n.posto_id AND e.vaga = n.vaga COLLATE utf8mb4_unicode_ci
                      AND (n.vaga = 'COBERTURA' AND e.rep_code = n.rep_code COLLATE utf8mb4_unicode_ci OR n.vaga = 'DONO')
                      AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-01'));

-- Conferência 1: postos da Skylar vigentes em 02/11 (esperado 0)
SELECT COUNT(*) AS postos_skylar_vigentes
  FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id
 WHERE pp.pagina = 'Skylar Mae VIP' AND p.vigente_desde <= '2026-11-02' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-02');

-- Conferência 2: postos por unidade e turno em 02/11 (U1: A 13, C 14, D 12) e quantos ainda sem dono
SELECT p.unidade, p.turno, COUNT(*) AS postos,
       SUM(NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = p.id AND e.vaga = 'DONO'
                         AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-02'))) AS sem_dono
  FROM esc_posto p
 WHERE p.vigente_desde <= '2026-11-02' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-02')
 GROUP BY p.unidade, p.turno ORDER BY p.unidade, FIELD(p.turno, 'A', 'C', 'D');
