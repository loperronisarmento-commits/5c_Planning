SET NAMES utf8mb4;
-- =====================================================================
-- ES_96_saidas_0411.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Saídas informadas em 04/11; ficam fora a partir de 04/11 ("de hoje em diante"):
--   Barbara Helena Nieviadonski  B074838  (U3, turno A, SR 1; 3 vagas) – movimentação interna para outro setor
--   Felister Mwende Mwongela     F417564  (U1, turno D, SR 1; 3 vagas) – desligada
-- O cadastro (config 317 e 362) e as vagas fecham em 03/11. Nenhuma das duas estava em equipe fixa.
-- Também informados, sem nada a fazer no 5C Escalas:
--   Julia Xavier Broggio (J108431): sem cadastro na escala e sem células nas versões de 04/11 em diante.
--   Luana Alves de Araujo: não encontrada no headcount, na estrutura nem no controle de turnos (pendente).
--   Rayssa Rayene Mulari (R105360): o usuário cancelou o pedido.
-- A v32.0 (plano 101, semana de 02/11, publicada) ainda tem a Barbara (8 células, 06 a 08/11) e a
-- Felister (32 células, 04 a 09/11): recalcular a partir do próximo turno e postar.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '317', '{"rep":"B074838","nome":"Barbara Helena Nieviadonski","motivo":"movimentação interna","vagas":[2783,2784,3277]}', '{"vigente_ate":"2026-11-03"}', NULL),
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '362', '{"rep":"F417564","nome":"Felister Mwende Mwongela","motivo":"desligamento","vagas":[2879,3107,3128]}', '{"vigente_ate":"2026-11-03"}', NULL);

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03', observacao = LEFT(CONCAT('Movimentação interna para outro setor, informada em 04/11 (ES_96); ', IFNULL(observacao, '')), 255)
 WHERE id = 317 AND rep_code = 'B074838' AND vigente_ate IS NULL;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03', observacao = LEFT(CONCAT('Desligamento informado em 04/11 (ES_96); ', IFNULL(observacao, '')), 255)
 WHERE id = 362 AND rep_code = 'F417564' AND vigente_ate IS NULL;

UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Movimentação interna (ES_96); ', IFNULL(motivo, '')), 255)
 WHERE id IN (2783, 2784, 3277) AND rep_code = 'B074838' AND vigente_ate IS NULL;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Desligamento (ES_96); ', IFNULL(motivo, '')), 255)
 WHERE id IN (2879, 3107, 3128) AND rep_code = 'F417564' AND vigente_ate IS NULL;
