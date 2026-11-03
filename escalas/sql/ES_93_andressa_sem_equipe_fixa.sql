SET NAMES utf8mb4;
-- =====================================================================
-- ES_93_andressa_sem_equipe_fixa.sql  (03/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário: a Andressa Urach (cancelada no ES_63, ainda ativa no site) deixa de ter equipe
-- fixa. A equipe dela (id 27: Marcos Cid e Loik) tirava o Loik da Aline Novak no turno C
-- (no plano 97, o Loik ficou na Andressa em 04, 05, 07 e 08/11). A página continua recebendo gente
-- pelo motor normal. A equipe fixa da Aline (id 26) não muda.
-- Na Aline, sem ninguém da equipe no turno, continua valendo a regra de hoje: outro SR brasileiro.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-03'
 WHERE id = 27 AND pagina = 'Andressa Urach' AND vigente_ate IS NULL;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'equipe.fixa', 'esc_pagina_prioridade', '27',
        '{"pagina":"Andressa Urach","rep_codes":"M137535,L052002","vigente_ate":null}', '{"script":"ES_93","vigente_ate":"2026-11-03"}', NULL);

-- Conferência
SELECT id, pagina, rep_codes, vigente_desde, vigente_ate FROM esc_pagina_prioridade WHERE pagina IN ('Andressa Urach', 'Aline Novak') ORDER BY id;
