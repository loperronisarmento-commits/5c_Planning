SET NAMES utf8mb4;
-- =====================================================================
-- ES_108_danilo_privacy_restricoes.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11:
--   1. Danilo Oliveira (D422040, U2 D n2, SR 1, brasileiro) na equipe da Privacy: entra na equipe fixa da
--      Aline Novak (U2C2) a partir de 04/11. Segue proibido na Molly Stewart (ES_107).
--   2. Alexandre Mortagua (A101727, U1 C n3, SR 1) e Oliver Barroso Melo (O030503, U1 A n1, SR 1): "as
--      piores páginas e solo, pra não ter problema com falta" → só uma página (v4_sr_uma_pagina: não acumula
--      nem cobre) e baixo fluxo estrito (v4_sr_baixo_fluxo_estrito: só posto de até 12 fãs/h, nem de assistente).
--   3. Rodjah Christie Maddy (R009812, U1 A n2, SR 1): como o Omari, não faz várias páginas → v4_sr_uma_pagina.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Aline Novak', 'D422040', 'Manager: Danilo Oliveira na equipe da Privacy (ES_108)', '2026-11-04', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Aline Novak' AND rep_codes = 'D422040' AND vigente_ate IS NULL);

UPDATE esc_parametro SET valor = 'A311550,R009812,A101727,O030503',
       descricao = 'SRs que só trabalham com uma página (não pegam posto de várias páginas, não acumulam nem cobrem): Omari Mambo, Rodjah Maddy, Alexandre Mortagua, Oliver Melo'
 WHERE chave = 'v4_sr_uma_pagina';
UPDATE esc_parametro SET valor = 'G386564,A101727,O030503',
       descricao = 'SRs que nunca entram em posto acima de v4_baixo_fluxo_max fãs/h, nem de assistente: Gabriela Boretti, Alexandre Mortagua, Oliver Melo'
 WHERE chave = 'v4_sr_baixo_fluxo_estrito';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_parametro', 'ES_108', NULL,
        '{"D422040":"equipe da Aline Novak","A101727":"uma página + baixo fluxo estrito","O030503":"uma página + baixo fluxo estrito","R009812":"uma página"}', NULL);

COMMIT;

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_uma_pagina', 'v4_sr_baixo_fluxo_estrito');
SELECT pagina, rep_codes, DATE(vigente_desde) AS de FROM esc_pagina_prioridade WHERE pagina = 'Aline Novak' AND vigente_ate IS NULL;
