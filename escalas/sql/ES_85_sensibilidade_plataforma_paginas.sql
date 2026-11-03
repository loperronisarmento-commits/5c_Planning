SET NAMES utf8mb4;
-- =====================================================================
-- ES_85_sensibilidade_plataforma_paginas.sql  (03/11, aplicado pelo Claude a pedido do Luis Otávio)
-- Regras P-02 e P-04 de contexto/regras_prioridade_paginas.md. Este script só grava os dados:
-- o motor ainda não lê estas colunas (a implementação no Gerar é um passo separado).
--
-- sensibilidade (P-02): VERDE, AMARELA ou VERMELHA.
--   VERMELHA = só SR que já trabalhou na página (7+ dias nos últimos 90);
--   AMARELA  = no máximo 20% dos turnos de SR da semana com quem não a conhece;
--   VERDE    = pela regra de mérito.
--   Fonte, nesta ordem: 1) a bandeira da última mensagem do Telegram "Movement Alert" (export de
--   03/11, 22/05 a 03/11/2026; 🟢 verde, 🟡 amarela), casada pelo @ da página com
--   5c_wfm_db.client.page; 2) 5c_wfm_db.client.flag (1 = verde, 2 = amarela, 3 = vermelha).
--   Sem nenhuma das duas fica NULL (Maddie e Haven Free).
-- plataforma (P-04): a de 5c_pages.pages.platform; sem plataforma no site, OnlyFans (padrão).
--   O SR não mistura plataformas no turno.
-- As colunas *_origem dizem de onde veio cada valor. As páginas são as 103 da carteira vigente em 03/11
-- (fc_pagina_carteira); as linhas que já existiam mantêm sla_modo e alocacao.
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_pagina_config
  ADD COLUMN sensibilidade ENUM('VERDE','AMARELA','VERMELHA') NULL DEFAULT NULL AFTER alocacao,
  ADD COLUMN sensibilidade_origem VARCHAR(60) NULL DEFAULT NULL AFTER sensibilidade,
  ADD COLUMN plataforma VARCHAR(20) NULL DEFAULT NULL AFTER sensibilidade_origem,
  ADD COLUMN plataforma_origem VARCHAR(60) NULL DEFAULT NULL AFTER plataforma;

INSERT INTO esc_pagina_config (pagina, sensibilidade, sensibilidade_origem, plataforma, plataforma_origem)
VALUES
  ('Abg', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Aja', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Aja Free', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Alexis Clark', 'VERDE', 'Telegram 13/07', 'OnlyFans', '5c_pages.pages'),
  ('Aline Novak', 'VERDE', 'Telegram 14/10', 'Privacy', '5c_pages.pages'),
  ('Alix Lynx', 'VERDE', 'Telegram 27/08', 'OnlyFans', '5c_pages.pages'),
  ('Angel', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Apex Free', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Apex VIP', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Aria', 'VERDE', 'Telegram 27/10', 'OnlyFans', '5c_pages.pages'),
  ('Ariana Marie', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Becky Recme', 'VERDE', 'Telegram 27/10', 'OnlyFans', '5c_pages.pages'),
  ('Bianca', 'VERDE', 'Telegram 06/09', 'OnlyFans', '5c_pages.pages'),
  ('Blair OFTV 155t', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Blair VIP 155m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Blaire', 'VERDE', 'Telegram 31/10', 'OnlyFans', '5c_pages.pages'),
  ('Blake', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Brandi Love', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Brandi Love FREE', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Britt OFTV', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Britt VIP', 'VERDE', 'Telegram 05/10', 'OnlyFans', '5c_pages.pages'),
  ('CJ Miles', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('CJ VIP', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Charlie 306m', 'VERDE', 'Telegram 09/08', 'OnlyFans', '5c_pages.pages'),
  ('Charly', 'VERDE', 'Telegram 31/10', 'OnlyFans', '5c_pages.pages'),
  ('Coco Bunnie 260m', 'VERDE', 'Telegram 26/05', 'OnlyFans', '5c_pages.pages'),
  ('Courtney', 'VERDE', 'Telegram 02/10', 'OnlyFans', '5c_pages.pages'),
  ('Cynthia', 'VERDE', 'Telegram 21/10', 'OnlyFans', '5c_pages.pages'),
  ('Dakota', 'VERDE', 'Telegram 31/10', 'OnlyFans', '5c_pages.pages'),
  ('Diana Rider', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', '5c_pages.pages'),
  ('Draya', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Eden The Doll', 'VERDE', 'Telegram 13/07', 'OnlyFans', '5c_pages.pages'),
  ('Ellie D', 'VERDE', 'Telegram 17/08', 'OnlyFans', '5c_pages.pages'),
  ('Elly Clutch 100m', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', '5c_pages.pages'),
  ('Goddess Sylvanas 150m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Haven  Free', NULL, NULL, 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Holly Jane', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Isla', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', '5c_pages.pages'),
  ('Ivy Vale', 'AMARELA', '5c_wfm_db.client flag 2', 'Fanvue', '5c_pages.pages'),
  ('Janet Free', 'VERDE', 'Telegram 02/10', 'OnlyFans', '5c_pages.pages'),
  ('Janet VIP', 'VERDE', 'Telegram 02/10', 'OnlyFans', '5c_pages.pages'),
  ('Jay', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Jena Wolfy Free 001s', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Jena Wolfy OFTV 001t', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Jena Wolfy VIP 001m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Jessie', 'VERDE', 'Telegram 27/10', 'OnlyFans', '5c_pages.pages'),
  ('Kas', 'VERDE', 'Telegram 27/10', 'OnlyFans', '5c_pages.pages'),
  ('Katie Flood', 'VERDE', 'Telegram 14/07', 'OnlyFans', '5c_pages.pages'),
  ('Kaylin', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Keera', 'VERDE', 'Telegram 26/10', 'OnlyFans', '5c_pages.pages'),
  ('Krystal 141m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Krystal 141t', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Levi Free', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Levi Paid', 'VERDE', 'Telegram 07/08', 'OnlyFans', '5c_pages.pages'),
  ('Lexis Star', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Liam', 'VERDE', 'Telegram 21/10', 'OnlyFans', '5c_pages.pages'),
  ('Lily Lou OFTV', 'VERDE', 'Telegram 12/10', 'OnlyFans', '5c_pages.pages'),
  ('Liv', 'VERDE', 'Telegram 09/09', 'OnlyFans', '5c_pages.pages'),
  ('Livinia Roberts OFTV', 'VERDE', 'Telegram 16/10', 'OnlyFans', '5c_pages.pages'),
  ('Maddie', NULL, NULL, 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Madison Moore Free 115s', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Madison Moore VIP 115m', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Mandi CAT', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('MandiCat OFTV', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Marie Dee VIP', 'VERDE', 'Telegram 22/10', 'OnlyFans', '5c_pages.pages'),
  ('Mario Adrion', 'VERDE', 'Telegram 07/08', 'OnlyFans', '5c_pages.pages'),
  ('Matty Caron', 'VERDE', 'Telegram 07/08', 'OnlyFans', '5c_pages.pages'),
  ('Maya', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Melody', 'VERDE', 'Telegram 01/10', 'OnlyFans', '5c_pages.pages'),
  ('Mia Melons', 'VERDE', 'Telegram 18/08', 'OnlyFans', '5c_pages.pages'),
  ('Midwest Emma Free 221s', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', '5c_pages.pages'),
  ('Mikayla Free', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Mikayla Paid', 'VERDE', 'Telegram 28/05', 'OnlyFans', '5c_pages.pages'),
  ('Mo Saffari', 'VERDE', 'Telegram 01/06', 'OnlyFans', '5c_pages.pages'),
  ('Molly Stewart VIP', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Mrs Robinson 303m', 'VERDE', 'Telegram 03/08', 'OnlyFans', '5c_pages.pages'),
  ('Ms Red Free', 'VERDE', 'Telegram 21/10', 'OnlyFans', '5c_pages.pages'),
  ('Ms Red VIP', 'VERDE', 'Telegram 21/10', 'OnlyFans', '5c_pages.pages'),
  ('Natasha Nice VIP', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Nicole Doshi', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Nicole OFTV', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages'),
  ('Rebecca', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', 'padrão: sem plataforma no site'),
  ('Rebecca OFTV', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Rocket Reyna OFTV', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Rocket Reyna VIP', 'VERDE', 'Telegram 29/09', 'OnlyFans', '5c_pages.pages'),
  ('Sarah H 329m', 'VERDE', 'Telegram 09/10', 'OnlyFans', '5c_pages.pages'),
  ('Savannah', 'VERDE', 'Telegram 31/10', 'OnlyFans', '5c_pages.pages'),
  ('Sean Adams', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Sebi Barraza', 'VERDE', 'Telegram 06/08', 'OnlyFans', '5c_pages.pages'),
  ('Silvia Saige Free', 'VERDE', 'Telegram 27/10', 'PlayBoy', '5c_pages.pages'),
  ('Silvia Saige VIP', 'VERDE', 'Telegram 27/10', 'PlayBoy', '5c_pages.pages'),
  ('Sisi Rose', 'VERDE', 'Telegram 30/09', 'OnlyFans', '5c_pages.pages'),
  ('Sophie Sierra', 'VERDE', 'Telegram 27/10', 'OnlyFans', '5c_pages.pages'),
  ('Sumner', 'VERDE', 'Telegram 08/10', 'OnlyFans', '5c_pages.pages'),
  ('Taylor Ballard', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Thalia 147m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Thalia 147t', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Tiff 176m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Tiny', 'VERDE', 'Telegram 18/08', 'OnlyFans', '5c_pages.pages'),
  ('Victorya 188m', 'AMARELA', '5c_wfm_db.client flag 2', 'OnlyFans', '5c_pages.pages'),
  ('Yuni Yung', 'VERDE', 'Telegram 22/09', 'OnlyFans', '5c_pages.pages'),
  ('zOFTV Diana Rider', 'VERDE', '5c_wfm_db.client flag 1', 'OnlyFans', '5c_pages.pages'),
  ('zOFTV Molly Stewart', 'VERMELHA', '5c_wfm_db.client flag 3', 'OnlyFans', '5c_pages.pages')
ON DUPLICATE KEY UPDATE sensibilidade = VALUES(sensibilidade), sensibilidade_origem = VALUES(sensibilidade_origem),
                        plataforma = VALUES(plataforma), plataforma_origem = VALUES(plataforma_origem);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       NULL, JSON_OBJECT('sensibilidade', sensibilidade, 'sensibilidade_origem', sensibilidade_origem,
                         'plataforma', plataforma, 'plataforma_origem', plataforma_origem, 'script', 'ES_85'), NULL
  FROM esc_pagina_config WHERE plataforma IS NOT NULL;

-- Conferência
SELECT sensibilidade, LEFT(sensibilidade_origem, 8) AS origem, COUNT(*) AS paginas
  FROM esc_pagina_config GROUP BY sensibilidade, LEFT(sensibilidade_origem, 8) ORDER BY sensibilidade, origem;
SELECT plataforma, plataforma_origem, COUNT(*) AS paginas FROM esc_pagina_config GROUP BY plataforma, plataforma_origem;
