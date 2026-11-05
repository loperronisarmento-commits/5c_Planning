SET NAMES utf8mb4;
-- =====================================================================
-- ES_113_thiago_larsen_so_assist.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Thiago Larsen (T082480, U2 C): "não escalar como regular; só assist ou micro páginas". Com o baixo fluxo
-- (ES_112) ele ainda saía regular em posto de até 12 fãs/h (Matty Caron, Sean Adams, que não são micro).
-- Para não quebrar o "não escalar como regular", passa a só assistente (v4_sr_so_assistente).
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_parametro SET valor = 'M175553,V088215', descricao = 'SRs só em posto de até v4_baixo_fluxo_max fãs/h ou de assistente: Matheus Chagas, Victor Stangl'
 WHERE chave = 'v4_sr_baixo_fluxo';
UPDATE esc_parametro SET valor = 'L118244,L049922,T082480', descricao = 'Só entram como assistente, em qualquer página: Letícia Zaleski, Larissa Soares, Thiago Larsen'
 WHERE chave = 'v4_sr_so_assistente';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_parametro', 'ES_113', '{"T082480":"baixo fluxo"}', '{"T082480":"só assistente"}', NULL);

COMMIT;

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_baixo_fluxo', 'v4_sr_so_assistente');
