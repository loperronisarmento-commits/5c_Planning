SET NAMES utf8mb4;
-- =====================================================================
-- ES_41 · Limpa o que o zip antigo (1.14.0) gravou às 23:52 de 30/10
--
-- O recálculo das 23:52 ainda rodou com o código da 1.14.0: a Cherie Deville 257t (sem
-- escala desde 07/10) entrou de novo no Capacity com vigência de 30/10. Com essa data o
-- Capacity tira do cálculo todas as outras páginas (que valem de 01/11), ficou sem postos e
-- falhou ao gravar a versão ("Column 'buffer' cannot be null"). A escala não foi trocada.
--
-- Este script:
--   1. tira a Cherie Deville 257t do Capacity (entrou sozinha, sem movimento);
--   2. libera a próxima atualização de estrutura (o registro das 23:52 vira "estrutura_erro").
-- Depois: subir o zip novo (1.14.1) e Recalcular 26/10 e 02/11. A mensagem deve dizer
-- "Capacity recalculado antes de gerar: ... postos iguais, ... novos, ... fechados".
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

DELETE FROM `5c_planning`.`fc_pagina_carteira`
 WHERE pagina = 'Cherie Deville 257t' AND vigente_desde < '2026-11-01' AND obs LIKE 'Entrou antes de gerar%';

UPDATE `esc_log` SET acao = 'estrutura_erro'
 WHERE acao = 'estrutura_atualizada' AND quando >= '2026-10-30 23:40:00'
   AND depois LIKE '%"erro": "SQLSTATE%';

COMMIT;

-- ---------- Conferência ----------
SELECT 'menor vigência da carteira (esperado 2026-11-01)' AS item, CAST(MIN(vigente_desde) AS CHAR) AS valor FROM `5c_planning`.`fc_pagina_carteira`
UNION ALL SELECT 'páginas que entraram sozinhas', CAST(COUNT(*) AS CHAR) FROM `5c_planning`.`fc_pagina_carteira` WHERE obs LIKE 'Entrou antes de gerar%'
UNION ALL SELECT 'atualizações de estrutura com sucesso hoje (esperado 0)', CAST(COUNT(*) AS CHAR) FROM `esc_log` WHERE acao = 'estrutura_atualizada' AND quando >= '2026-10-30';
