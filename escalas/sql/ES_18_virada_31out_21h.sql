-- =====================================================================
-- ES_18_virada_31out_21h.sql  (29/10)
-- A virada para a estrutura nova passa para 31/10 às 21:00: o turno D de 31/10
-- (T6 de 31/10 + T1 de 01/11) já é da estrutura nova. Até 31/10 T5 o CSV
-- repete o que já está postado.
-- O turno D de 31/10 e o dia 01/11 são montados como transição: quem já está
-- escalado no 5C Schedule nesse turno cobre as páginas da estrutura nova (só SRs).
-- Para 01/11 seguir a rotação normal dos núcleos, deixe v4_dia_transicao só com
-- '2026-10-31'.
-- Precisa do zip do sistema de 29/10 (motor com virada por hora).
-- Depois: gerar de novo as semanas de 26/10 e 02/11, nessa ordem.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_parametro
   SET valor = '2026-10-31 21:00',
       descricao = 'Início da estrutura nova (dia e hora): 05:00 = turno A, 13:00 = turno C, 21:00 = turno D (T6 do dia + T1 do dia seguinte)'
 WHERE chave = 'v4_virada';

UPDATE esc_parametro
   SET valor = '2026-10-31,2026-11-01',
       descricao = 'Dias montados com quem já está escalado no scheduleday, só SRs (separados por vírgula; vazio = sem transição)'
 WHERE chave = 'v4_dia_transicao';

-- Conferência
SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_virada', 'v4_dia_transicao');
