SET NAMES utf8mb4;
-- =====================================================================
-- ES_89_sensibilidade_maddie_haven.sql  (03/11, aplicado pelo Claude via MCP a pedido do Luis Otávio)
-- Regra P-02 (sensibilidade da página), em contexto/regras_prioridade_paginas.md.
-- Maddie e Haven Free não aparecem no Telegram nem no 5c_wfm_db.client, e o ES_85 as deixou sem cor.
-- O usuário definiu as duas como VERDE em 03/11.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_config
   SET sensibilidade = 'VERDE', sensibilidade_origem = 'manual: Luis Otávio 03/11'
 WHERE pagina IN ('Maddie', 'Haven  Free') AND sensibilidade IS NULL;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       '{"sensibilidade":null}', '{"sensibilidade":"VERDE","sensibilidade_origem":"manual: Luis Otávio 03/11","script":"ES_89"}', NULL
  FROM esc_pagina_config WHERE pagina IN ('Maddie', 'Haven  Free');

-- Conferência
SELECT sensibilidade, COUNT(*) AS paginas FROM esc_pagina_config GROUP BY sensibilidade;
