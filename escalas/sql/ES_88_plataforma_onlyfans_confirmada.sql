SET NAMES utf8mb4;
-- =====================================================================
-- ES_88_plataforma_onlyfans_confirmada.sql  (03/11, aplicado pelo Claude via MCP a pedido do Luis Otávio)
-- Regra P-04 (uma plataforma por SR), em contexto/regras_prioridade_paginas.md.
-- O ES_85 gravou como OnlyFans, por padrão, as 7 páginas sem plataforma no site (5c_pages.pages).
-- O usuário confirmou em 03/11 que todas são OnlyFans: só muda a origem do valor.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_config
   SET plataforma = 'OnlyFans', plataforma_origem = 'manual: Luis Otávio 03/11'
 WHERE pagina IN ('Ariana Marie', 'Blair OFTV 155t', 'Britt OFTV', 'Haven  Free', 'Lexis Star', 'Maddie', 'Rebecca')
   AND plataforma_origem = 'padrão: sem plataforma no site';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       '{"plataforma":"OnlyFans","plataforma_origem":"padrão: sem plataforma no site"}',
       '{"plataforma":"OnlyFans","plataforma_origem":"manual: Luis Otávio 03/11","script":"ES_88"}', NULL
  FROM esc_pagina_config
 WHERE pagina IN ('Ariana Marie', 'Blair OFTV 155t', 'Britt OFTV', 'Haven  Free', 'Lexis Star', 'Maddie', 'Rebecca');

-- Conferência
SELECT plataforma, plataforma_origem, COUNT(*) AS paginas FROM esc_pagina_config GROUP BY plataforma, plataforma_origem;
