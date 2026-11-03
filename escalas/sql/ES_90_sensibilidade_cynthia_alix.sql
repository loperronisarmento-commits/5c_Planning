SET NAMES utf8mb4;
-- =====================================================================
-- ES_90_sensibilidade_cynthia_alix.sql  (03/11, aplicado pelo Claude via MCP a pedido do Luis Otávio)
-- Regra P-02 (sensibilidade da página), em contexto/regras_prioridade_paginas.md.
-- Pelo Telegram (bandeira da entrada) as duas ficaram VERDE no ES_85; no 5c_wfm_db.client são 3
-- (vermelha). O usuário definiu as duas como AMARELA em 03/11. Mia Melons e Tiny continuam VERDE.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_pagina_config
   SET sensibilidade = 'AMARELA', sensibilidade_origem = 'manual: Luis Otávio 03/11'
 WHERE pagina IN ('Cynthia', 'Alix Lynx');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       '{"sensibilidade":"VERDE"}', '{"sensibilidade":"AMARELA","sensibilidade_origem":"manual: Luis Otávio 03/11","script":"ES_90"}', NULL
  FROM esc_pagina_config WHERE pagina IN ('Cynthia', 'Alix Lynx');

-- Conferência
SELECT pagina, sensibilidade, sensibilidade_origem FROM esc_pagina_config WHERE pagina IN ('Cynthia', 'Alix Lynx');
SELECT sensibilidade, COUNT(*) AS paginas FROM esc_pagina_config GROUP BY sensibilidade;
