-- =====================================================================
-- ES_13_overlap_defasado.sql  (revisão de 28/10)
-- O Overlap (dia de treinamento, página 5C Schedule com -5) deixa de cair no
-- mesmo dia para o núcleo todo: cada SR tem o próprio dia. Os SRs de cada
-- núcleo (pela ordem do código) se revezam entre as folgas do ciclo de 21
-- dias nas posições 5, 6, 7, 16 e 17 (sáb e dom da semana 1, seg da semana 2,
-- qua e qui da semana 3). O OS e os assentos (postos e vagas) não mudam.
-- Ficam de fora a terça da semana 1 e a sexta da semana 2: com o Overlap
-- nelas o SR emendaria 8 dias seguidos; nas posições escolhidas são no
-- máximo 5.
-- Rodar depois do ES_11. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_parametro
   SET valor = '5,6,7,16,17',
       descricao = 'Posições de folga do ciclo de 21 dias (0 a 20) usadas como dia de Overlap; os SRs de cada núcleo se revezam entre elas. Vazio = sem Overlap'
 WHERE chave = 'v4_overlap_posicao_ciclo';

-- Conferência
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_overlap_posicao_ciclo';
