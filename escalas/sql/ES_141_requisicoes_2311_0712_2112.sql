SET NAMES utf8mb4;
-- =====================================================================
-- ES_141_requisicoes_2311_0712_2112.sql  (10/11/2026, pedido do usuário)
--
-- Requisições abertas ao recrutamento (informadas em 10/11; data de abertura não informada):
--   23/11: 10 D (turma intermediária, fora do plano)
--   07/12: 12 A · 12 C · 12 D (substitui o plano de 10/10/10: "pode remover a anterior")
--   21/12:  8 A ·  8 C ·  8 D (turma intermediária, fora do plano)
-- A requisição substitui o plano: as três turmas ficam CONFIRMADA em 5c_planning.fc_contratacoes
-- com os números pedidos (opera = início + 14 dias), para o Capacity contar o que foi pedido.
-- O plano de 16/11 (5/9/6, contra a requisição real 0/9/11 do ES_140) não é mexido aqui.
-- =====================================================================

-- 0. Antes
SELECT 'antes' AS momento, turma_inicio, turno, qtd_pedida, etapa, obs
  FROM `5c_escalas`.esc_requisicao_vaga
 WHERE turma_inicio IN ('2026-11-23', '2026-12-07', '2026-12-21') ORDER BY turma_inicio, turno;
SELECT 'antes' AS momento, turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
  FROM `5c_planning`.fc_contratacoes
 WHERE data_inicio_trn IN ('2026-11-23', '2026-12-07', '2026-12-21') ORDER BY data_inicio_trn, turno;

-- 1. Requisições (ABERTA)
INSERT INTO `5c_escalas`.esc_requisicao_vaga (turma_inicio, turno, qtd_pedida, etapa, aberta_em, obs) VALUES
 ('2026-11-23', 'D', 10, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-07', 'A', 12, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-07', 'C', 12, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-07', 'D', 12, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-21', 'A',  8, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-21', 'C',  8, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)'),
 ('2026-12-21', 'D',  8, 'ABERTA', NULL, 'Requisição informada em 10/11 (ES_141)');

-- 2. Plano do Capacity
UPDATE `5c_planning`.fc_contratacoes
   SET qtd = 12, status = 'CONFIRMADA', obs = 'Requisição de 10/11: 12A | 12C | 12D; substitui o plano de 10 (ES_141)'
 WHERE data_inicio_trn = '2026-12-07' AND status <> 'CANCELADA';

INSERT INTO `5c_planning`.fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs, fixa) VALUES
 ('2026-11-23', '2026-11-23', '2026-12-07', 'D', 10, 'CONFIRMADA', 'Requisição de 10/11: 10D (ES_141)', 0),
 ('2026-12-21', '2026-12-21', '2027-01-04', 'A',  8, 'CONFIRMADA', 'Requisição de 10/11: 8A | 8C | 8D (ES_141)', 0),
 ('2026-12-21', '2026-12-21', '2027-01-04', 'C',  8, 'CONFIRMADA', 'Requisição de 10/11: 8A | 8C | 8D (ES_141)', 0),
 ('2026-12-21', '2026-12-21', '2027-01-04', 'D',  8, 'CONFIRMADA', 'Requisição de 10/11: 8A | 8C | 8D (ES_141)', 0);

-- 3. Depois
SELECT 'depois' AS momento, turma_inicio, turno, qtd_pedida, etapa, obs
  FROM `5c_escalas`.esc_requisicao_vaga
 WHERE turma_inicio IN ('2026-11-23', '2026-12-07', '2026-12-21') ORDER BY turma_inicio, turno;
SELECT 'depois' AS momento, turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
  FROM `5c_planning`.fc_contratacoes
 WHERE data_inicio_trn IN ('2026-11-23', '2026-12-07', '2026-12-21') ORDER BY data_inicio_trn, turno;
