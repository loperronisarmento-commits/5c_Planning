SET NAMES utf8mb4;
-- =====================================================================
-- ES_40 · Desfaz a atualização de estrutura de 30/10 23:30 (1.14.0)
--
-- O que aconteceu: as 19 páginas que entraram sozinhas no Capacity foram gravadas com
-- vigência a partir de 30/10. O Capacity usa a menor data da carteira como início da
-- estrutura; com 30/10, todas as outras páginas (que valem de 01/11) ficaram fora do
-- cálculo de hoje: 28 postos em vez de ~150. A escala seguiu esse cálculo e fechou 159
-- postos, criando 28 (versão CAP20261030_233023). Corrigido no código (1.14.1): a página
-- nova entra no início da estrutura e uma trava impede a troca se o Capacity vier com bem
-- menos páginas que a escala.
--
-- Este script:
--   1. reabre os 159 postos fechados pela atualização (e as equipes deles) como estavam:
--      a partir de 01/11 (a Andressa, 02/11), sem fim;
--   2. fecha os 28 postos criados por ela (e as equipes) antes da virada (29/10);
--   3. no Capacity, as 18 páginas novas passam a valer de 01/11, e a Cherie Deville 257t
--      sai (sem escala desde 07/10: entrou pela janela de 28 dias, agora 14);
--   4. libera a próxima atualização de estrutura (o registro de 23:30 fica no log, renomeado).
-- Os postos fechados antes pelas saídas de página e pela ES_39 continuam fechados.
--
-- Trava: só roda se os postos da versão CAP20261030_233023 ainda valem.
-- Depois: subir o zip 1.14.1 e Recalcular as semanas 26/10 e 02/11 (o Capacity é
-- recalculado certo antes de gerar), conferir e publicar de novo.
-- =====================================================================

USE `5c_escalas`;

SET @ok := (SELECT COUNT(*) FROM `esc_posto` WHERE versao = 'CAP20261030_233023' AND vigente_ate IS NULL);

START TRANSACTION;

-- Postos fechados antes da atualização (saídas de página e ES_39): ficam fechados.
DROP TEMPORARY TABLE IF EXISTS es40_antes;
CREATE TEMPORARY TABLE es40_antes (id INT PRIMARY KEY);
INSERT INTO es40_antes VALUES (256),(257),(258),(259),(265),(266),(267),(268),(274),(275),(276),(277),
  (311),(321),(330),(431),(432),(433),(289),(298),(305),(441);

DROP TEMPORARY TABLE IF EXISTS es40_volta;
CREATE TEMPORARY TABLE es40_volta (id INT PRIMARY KEY, desde DATE);
INSERT INTO es40_volta (id, desde)
SELECT p.id, IF(p.versao = 'ES32_ANDRESSA', '2026-11-02', '2026-11-01')
  FROM `esc_posto` p
 WHERE @ok > 0
   AND p.versao IN ('CAP20261028_1607', 'CAP20261028_1607-S', 'ES32_ANDRESSA')
   AND p.vigente_desde = '2026-10-29' AND p.vigente_ate = '2026-10-29'
   AND p.id NOT IN (SELECT id FROM es40_antes);

-- 1. Reabre equipes e postos (a equipe velha de 31/10 que a ES_39 fechou continua fechada)
UPDATE `esc_vaga_equipe` e JOIN es40_volta v ON v.id = e.posto_id
   SET e.vigente_desde = v.desde, e.vigente_ate = NULL
 WHERE e.vigente_desde = '2026-10-29' AND e.vigente_ate = '2026-10-29'
   AND (e.motivo IS NULL OR e.motivo NOT LIKE 'ES\_39:%');
UPDATE `esc_posto` p JOIN es40_volta v ON v.id = p.id
   SET p.vigente_desde = v.desde, p.vigente_ate = NULL;

-- 2. Fecha os 28 postos criados pela atualização
UPDATE `esc_vaga_equipe` e JOIN `esc_posto` p ON p.id = e.posto_id
   SET e.vigente_desde = '2026-10-29', e.vigente_ate = '2026-10-29'
 WHERE @ok > 0 AND p.versao = 'CAP20261030_233023';
UPDATE `esc_posto` SET vigente_desde = '2026-10-29', vigente_ate = '2026-10-29'
 WHERE @ok > 0 AND versao = 'CAP20261030_233023';

-- 3. Capacity: páginas novas a partir do início da estrutura; Cherie Deville 257t sai
DELETE FROM `5c_planning`.`fc_pagina_carteira`
 WHERE @ok > 0 AND pagina = 'Cherie Deville 257t' AND vigente_desde = '2026-10-30' AND obs LIKE 'Entrou antes de gerar%';
UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_desde = '2026-11-01'
 WHERE @ok > 0 AND vigente_desde = '2026-10-30' AND obs LIKE 'Entrou antes de gerar%';

-- 4. Libera a próxima atualização (a trava de 30 minutos olha este registro)
UPDATE `esc_log` SET acao = 'estrutura_desfeita_es40'
 WHERE @ok > 0 AND acao = 'estrutura_atualizada' AND quando >= '2026-10-30';

COMMIT;

-- ---------- Conferência ----------
SELECT 'trava (1+ = rodou)' AS item, @ok AS valor
UNION ALL SELECT 'postos reabertos (esperado 159)', COUNT(*) FROM es40_volta
UNION ALL SELECT CONCAT('postos que valem em 02/11: ', versao), COUNT(*) FROM `esc_posto`
 WHERE vigente_desde <= '2026-11-02' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-02') GROUP BY versao
UNION ALL SELECT 'páginas do Capacity que valem a partir de 01/11', COUNT(*) FROM `5c_planning`.`fc_pagina_carteira`
 WHERE vigente_desde <= '2026-11-01' AND (vigente_ate IS NULL OR vigente_ate > '2026-11-01')
UNION ALL SELECT 'menor vigência da carteira (esperado 2026-11-01)', MIN(vigente_desde) FROM `5c_planning`.`fc_pagina_carteira`;
