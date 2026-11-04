SET NAMES utf8mb4;
-- =====================================================================
-- ES_110_saida_matheus_ramos.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário em 04/11 (noite): Matheus de Castro Ramos (M106697, U1 C n1, SR 3; 3 vagas; sem equipe
-- fixa) pediu desligamento. Fica fora a partir de 05/11: cadastro (config 558) e vagas fecham em 04/11.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '558', '{"rep":"M106697","nome":"Matheus de Castro Ramos"}', '{"vigente_ate":"2026-11-04"}', NULL);

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-04', observacao = LEFT(CONCAT('Pediu desligamento em 04/11 (ES_110); ', IFNULL(observacao, '')), 255)
 WHERE id = 558 AND UPPER(rep_code) = 'M106697' AND vigente_ate IS NULL;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Desligamento (ES_110); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'M106697' AND vigente_desde <= '2026-11-04' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'M106697' AND vigente_desde >= '2026-11-05';
