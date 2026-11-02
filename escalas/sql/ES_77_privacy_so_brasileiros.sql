SET NAMES utf8mb4;
-- =====================================================================
-- ES_77_privacy_so_brasileiros.sql  (02/11, aplicado pelo Claude via MCP)
-- Regra do usuário: as páginas da Privacy só podem ter SRs brasileiros.
--   - Parâmetro v4_plataforma_so_brasil (padrão "Privacy"). O Gerar (1.18.11) põe FORA dessas páginas
--     quem tem nacionalidade diferente de Brazil no headcount; a proteção do publicado também respeita.
--   - Páginas da Privacy ativas em 02/11 (5c_pages.platform): Aline Novak e Andressa Urach.
--   - Equipe fixa da Andressa Urach: sai a M203717 (Filipinas); ficam Marcos Cid e Loik Silva.
--     A da Aline Novak (ES_76) já é toda brasileira.
-- No plano 93 (já postado), há 2 estrangeiros na Aline Novak, a acertar à mão no site:
--   L368271 (Quênia): T6 de 03/11 e T1 de 04/11; R152507 (Filipinas): T6 de 06/11 e T1 de 07/11.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao, atualizado_em) VALUES
  ('v4_plataforma_so_brasil', 'Privacy', 'TEXTO', 'v4', 'Plataformas (5c_pages.platform, separadas por vírgula) cujas páginas só aceitam SR de nacionalidade Brazil no headcount; os demais ficam FORA delas no Gerar', NOW())
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-02' WHERE id = 1 AND pagina = 'Andressa Urach' AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Andressa Urach', 'M137535,L052002', 'Privacy: time legado Felix Legion III (Marcos Vinicius Cid Mélo), só brasileiros (a M203717 saiu em 02/11)', '2026-11-03', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Andressa Urach' AND vigente_desde = '2026-11-03');
