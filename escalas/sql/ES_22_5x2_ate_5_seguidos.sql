-- =====================================================================
-- ES_22_5x2_ate_5_seguidos.sql  (29/10)
-- No 5x2 vale trabalhar até 5 dias seguidos: o limite de anomalia do modelo
-- "5x2 rotativo 21 dias" passa de 4 (a maior sequência do ciclo) para 5.
-- O ciclo e a rotação continuam iguais.
-- Folga de emenda: quem já vem de 5 dias seguidos (escala postada antes da virada +
-- dias do plano) folga no dia seguinte; a vaga vai para outra pessoa do núcleo, buffer
-- ou vira lacuna. Fica gravada como folga do tipo EMENDA.
-- (O limite também pode ser mudado pela tela Modelos de escala; o tipo EMENDA só por aqui.
--  Sem ele, a folga de emenda é gravada como folga da rotação.)
-- Depois: gerar de novo as semanas de 26/10 e 02/11.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_modelo_escala SET max_seguidos = 5 WHERE nome = '5x2 rotativo 21 dias';

ALTER TABLE esc_plano_folga
  MODIFY tipo ENUM('CICLO','FOLGA_FIXA','INDISPONIBILIDADE','ADMIN','ROTACAO','EMENDA') NOT NULL;

-- Conferência
SELECT nome, ciclo, max_seguidos, '5' AS esperado FROM esc_modelo_escala WHERE nome = '5x2 rotativo 21 dias';
SELECT COLUMN_TYPE, 'com EMENDA' AS esperado FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_folga' AND COLUMN_NAME = 'tipo';
