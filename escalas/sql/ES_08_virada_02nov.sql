-- =====================================================================
-- ES_08_virada_02nov.sql
-- A escala antiga foi postada até 01/11 T6 (revisão de 28/10). O motor v4
-- passa a começar no turno A de 02/11 e não há mais dia de transição:
--   - v4_virada        → 2026-11-02 01:00 (o T1 de 02/11 é o fim do turno
--                        D de 01/11 e o CSV repete quem está no T6 postado);
--   - v4_dia_transicao → vazio (01/11 não é mais montado pelo motor).
-- Rodar depois do ES_04. Pode rodar de novo.
-- Para voltar à virada de 01/11 05:00 com transição:
--   UPDATE esc_parametro SET valor = '2026-11-01 05:00' WHERE chave = 'v4_virada';
--   UPDATE esc_parametro SET valor = '2026-11-01' WHERE chave = 'v4_dia_transicao';
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_parametro
   SET valor = '2026-11-02 01:00',
       descricao = 'Início da estrutura nova: a escala antiga vai até 01/11 T6; o T1 de 02/11 continua o turno D postado'
 WHERE chave = 'v4_virada';

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
VALUES ('v4_dia_transicao', '', 'TEXTO', 'v4', 'Dia montado com quem já está escalado no scheduleday (vazio = sem transição)')
    AS n ON DUPLICATE KEY UPDATE valor = n.valor, descricao = n.descricao;

-- Conferência
SELECT chave, valor, descricao FROM esc_parametro WHERE chave IN ('v4_virada', 'v4_dia_transicao', 'v4_rotacao_ancora');
