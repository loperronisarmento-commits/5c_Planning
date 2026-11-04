SET NAMES utf8mb4;
-- =====================================================================
-- ES_99_rhazzel_retorno_1611.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário: Rhazzel John Manabo Marfa (R025361, U1 D núcleo 1, SR 1) volta ao trabalho em
-- 16/11 às 21:00 de Brasília, ou seja, no turno D de 16/11. A indisponibilidade 29 (todos os dias
-- até 01/02/2027, turno D) fica até 15/11; os dias de 16/11 em diante são cancelados.
-- =====================================================================

USE `5c_escalas`;

UPDATE esc_indisponibilidade_data SET status = 'CANCELADO', comentario = LEFT(CONCAT('Retorno em 16/11 às 21:00 (ES_99); ', IFNULL(comentario, '')), 255)
 WHERE indisponibilidade_id = 29 AND dia >= '2026-11-16' AND status = 'APROVADO';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'indisponibilidade.encurtar', 'esc_indisponibilidade', '29',
        '{"rep":"R025361","ate":"2027-02-01"}', '{"script":"ES_99","ate":"2026-11-15","volta":"2026-11-16 21:00 (turno D)"}', NULL);

-- Conferência
SELECT status, MIN(dia) AS de, MAX(dia) AS ate, COUNT(*) AS dias FROM esc_indisponibilidade_data WHERE indisponibilidade_id = 29 GROUP BY status;
