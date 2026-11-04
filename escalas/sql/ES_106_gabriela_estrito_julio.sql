SET NAMES utf8mb4;
-- =====================================================================
-- ES_106_gabriela_estrito_julio.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11 (motor 1.19.10):
--   1. Gabriela Boretti Pereira (G386564): "jamais trabalhar em páginas de fluxo maior, inclusive Molly
--      Stewart e Ariana Marie". Sai de v4_sr_baixo_fluxo (que deixa ser assistente em qualquer página) e
--      vai para v4_sr_baixo_fluxo_estrito: nenhum posto acima de v4_baixo_fluxo_max (12 fãs/h), nem de
--      assistente. Também fica proibida (FORA) na Molly Stewart VIP, na zOFTV Molly Stewart e na Ariana Marie.
--      O Matheus Chagas segue em v4_sr_baixo_fluxo (assistente em qualquer página ou página pequena).
--   2. Julio Candia Antezana (J621606): "retire o proibido, ele pode em todas". A proibição na Sophie Sierra
--      (id 10, de 02/11) fecha em 03/11.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_parametro SET valor = 'M175553', descricao = 'SRs só em página de baixo fluxo (até v4_baixo_fluxo_max fãs/h) ou de assistente: Matheus Chagas'
 WHERE chave = 'v4_sr_baixo_fluxo';
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_sr_baixo_fluxo_estrito', 'G386564', 'TEXTO', 'v4', 'SRs que nunca entram em posto acima de v4_baixo_fluxo_max fãs/h, nem de assistente: Gabriela Boretti'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_parametro WHERE chave = 'v4_sr_baixo_fluxo_estrito');

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, vigente_desde, vigente_ate, motivo)
SELECT x.pagina, 'G386564', 'FORA', '2026-11-04', NULL, 'Manager: Gabriela Boretti jamais em página de fluxo maior (04/11, ES_106)'
  FROM (SELECT 'Molly Stewart VIP' AS pagina UNION ALL SELECT 'zOFTV Molly Stewart' UNION ALL SELECT 'Ariana Marie') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND a.rep_code = 'G386564' AND a.papel = 'FORA');

UPDATE esc_pagina_assistente SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('ES_106: pode em todas; ', IFNULL(motivo, '')), 255)
 WHERE id = 10 AND UPPER(rep_code) = 'J621606' AND papel = 'FORA' AND vigente_ate IS NULL;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_pagina_assistente', 'ES_106', NULL,
        '{"G386564":"baixo fluxo estrito + FORA Molly e Ariana Marie","J621606":"sem proibição (Sophie Sierra)"}', NULL);

COMMIT;

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_baixo_fluxo', 'v4_sr_baixo_fluxo_estrito', 'v4_baixo_fluxo_max');
SELECT id, pagina, UPPER(rep_code) AS rep, papel, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_assistente WHERE UPPER(rep_code) IN ('G386564', 'J621606');
