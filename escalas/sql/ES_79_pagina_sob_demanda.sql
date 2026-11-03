SET NAMES utf8mb4;
-- =====================================================================
-- ES_79_pagina_sob_demanda.sql  (02/11, aplicado pelo Claude via MCP)
-- Pedido do comercial (planilha de 02/11): cada página tem um modo de alocação.
--   EQUIPE      = Active team (padrão): o Gerar põe gente na página, como sempre.
--   SOB_DEMANDA = On demand: o Gerar não põe ninguém. Quando alguém pede, a alocação é manual
--                 (célula travada na Grade).
-- Configura-se na tela Páginas (Configuração das páginas), coluna "Alocação".
-- Páginas On demand da planilha (nome na planilha → nome no site):
--   Molly Stewart OFTV → zOFTV Molly Stewart   (Timeless)
--   Diana Rider OFTV   → zOFTV Diana Rider     (Timeless)
--   Britt OFTV         → Britt OFTV            (Elysium)
--   Blair OFTV         → Blair OFTV 155t       (Fat Bear)
--   Jena Wolfy OFTV    → Jena Wolfy OFTV 001t  (Fat Bear)
-- As demais da planilha (OFTV da 5C e da Noah, todas as Free) são Active team: ficam no padrão.
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_pagina_config
  ADD COLUMN alocacao ENUM('EQUIPE','SOB_DEMANDA') NOT NULL DEFAULT 'EQUIPE' AFTER sla_modo;

INSERT INTO esc_pagina_config (pagina, sla_modo, alocacao, atualizado_por)
VALUES ('zOFTV Molly Stewart', 'AUTO', 'SOB_DEMANDA', NULL),
       ('zOFTV Diana Rider', 'AUTO', 'SOB_DEMANDA', NULL),
       ('Britt OFTV', 'AUTO', 'SOB_DEMANDA', NULL),
       ('Blair OFTV 155t', 'AUTO', 'SOB_DEMANDA', NULL),
       ('Jena Wolfy OFTV 001t', 'AUTO', 'SOB_DEMANDA', NULL)
ON DUPLICATE KEY UPDATE alocacao = VALUES(alocacao);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       '{"alocacao":"EQUIPE"}', '{"alocacao":"SOB_DEMANDA","origem":"planilha do comercial 02/11"}', NULL
  FROM esc_pagina_config WHERE alocacao = 'SOB_DEMANDA';

-- Conferência: as 5 páginas sob demanda
SELECT pagina, sla_modo, alocacao FROM esc_pagina_config WHERE alocacao = 'SOB_DEMANDA' ORDER BY pagina;
