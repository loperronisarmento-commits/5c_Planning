-- =====================================================================
-- ES_11_overlap_treinamento.sql
-- Dia de treinamento do 5x2 rotativo de 21 dias como Overlap (pedido de 28/10):
-- cada núcleo trabalha 14 dias no posto a cada 21 e o 15º dia (a folga
-- isolada da semana 1, terça, posição 1 do ciclo 101110001110111100111,
-- contando do 0) é o dia de treinamento. Nesse dia o SR entra na escala
-- 5C Schedule como Overlap, no posto dele, com "-5" no fim do código no CSV.
-- Depois se decide se ele vira buffer ou faz o treinamento.
--   - esc_plano_alocacao.origem ganha 'OVERLAP';
--   - parâmetro v4_overlap_posicao_ciclo = 1 (vazio = sem Overlap).
-- O Capacity não muda: o fator 1,5 continua.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

ALTER TABLE esc_plano_alocacao
  MODIFY origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA',
                     'VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO','OVERLAP') NOT NULL;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
VALUES ('v4_overlap_posicao_ciclo', '1', 'TEXTO', 'v4',
        'Posição no ciclo de 21 dias (0 a 20) do dia de treinamento: o SR entra como Overlap (-5 no CSV). Vazio = sem Overlap')
    AS n ON DUPLICATE KEY UPDATE valor = n.valor, descricao = n.descricao;

-- Conferência
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_alocacao' AND COLUMN_NAME = 'origem';
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_overlap_posicao_ciclo';
