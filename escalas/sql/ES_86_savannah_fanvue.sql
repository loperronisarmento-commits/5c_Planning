SET NAMES utf8mb4;
-- =====================================================================
-- ES_86_savannah_fanvue.sql  (03/11, aplicado pelo Claude via MCP a pedido do Luis Otávio)
-- Regra P-04 (uma plataforma por SR), em contexto/regras_prioridade_paginas.md.
-- A Savannah é Fanvue, como a Ivy Vale: as duas dividem o posto fixo U3C2 grupo 2 e a equipe fixa
-- da Fanvue. O site (5c_pages.pages.platform) e o Telegram de 31/10 ("Infloww 5C") a mostram como
-- OnlyFans. Vale a decisão do usuário de 03/11; o ES_85 tinha gravado OnlyFans.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_config
   SET plataforma = 'Fanvue', plataforma_origem = 'manual: Luis Otávio 03/11'
 WHERE pagina = 'Savannah';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', 'Savannah',
        '{"plataforma":"OnlyFans","plataforma_origem":"5c_pages.pages"}',
        '{"plataforma":"Fanvue","plataforma_origem":"manual: Luis Otávio 03/11","script":"ES_86"}', NULL);

-- Conferência
SELECT pagina, plataforma, plataforma_origem FROM esc_pagina_config WHERE plataforma = 'Fanvue' ORDER BY pagina;
