SET NAMES utf8mb4;
-- =====================================================================
-- ES_118_juliana_goddess_ivy.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Planilha do OM da U3 (ES_114): a "Juliana Santos" é a J557703, Juliana Simões Fonseca dos Santos
-- (nome social; U3 D núcleo 3, SR). Confirmado pelo usuário em 04/11. A partir de 05/11:
--   - main da Goddess Sylvanas 150m no turno D;
--   - cover da Ivy Vale no turno D.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, 'J557703', x.papel, 'Planilha do OM da U3: Juliana Simões (ES_118)', '2026-11-05', NULL
  FROM (SELECT 'Goddess Sylvanas 150m' AS pagina, 'MAIN' AS papel UNION ALL SELECT 'Ivy Vale', 'COVER') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND FIND_IN_SET('J557703', p.rep_codes) AND p.vigente_ate IS NULL);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_pagina_prioridade', 'ES_118', NULL,
        '{"J557703":"main Goddess D, cover Ivy D"}', NULL);

COMMIT;

SELECT pagina, papel, rep_codes, DATE(vigente_desde) AS de FROM esc_pagina_prioridade
 WHERE pagina IN ('Goddess Sylvanas 150m', 'Ivy Vale') AND vigente_ate IS NULL ORDER BY pagina, papel;
