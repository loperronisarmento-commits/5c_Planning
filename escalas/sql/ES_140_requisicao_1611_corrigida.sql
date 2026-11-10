SET NAMES utf8mb4;
-- =====================================================================
-- ES_140_requisicao_1611_corrigida.sql  (10/11/2026, pedido do usuário)
--
-- A requisição da turma de 16/11 veio da carga do plano de 28/10 (ES_136): 5 A / 9 C / 6 D.
-- A requisição feita ao recrutamento foi 0 A / 9 C / 11 D (20 no total).
-- Mesmo efeito de refazer a requisição na tela (escReqAbrir): turno com 0 vira CANCELADA.
-- Só o schema de produção (5c_escalas); o Modo Treino não tem a turma de 16/11.
-- O plano oficial do Capacity (5c_planning.fc_contratacoes, 5/9/6) não muda aqui.
-- =====================================================================

USE `5c_escalas`;

SELECT 'antes' AS momento, id, turma_inicio, turno, qtd_pedida, qtd_contratada, etapa, obs
  FROM esc_requisicao_vaga WHERE turma_inicio = '2026-11-16' ORDER BY turno;

UPDATE esc_requisicao_vaga
   SET etapa = 'CANCELADA', obs = 'Requisição real: sem vagas no A (ES_140)'
 WHERE turma_inicio = '2026-11-16' AND turno = 'A' AND etapa = 'ABERTA';

UPDATE esc_requisicao_vaga
   SET qtd_pedida = 9, obs = 'Requisição real: 9 no C (ES_140)'
 WHERE turma_inicio = '2026-11-16' AND turno = 'C' AND etapa = 'ABERTA';

UPDATE esc_requisicao_vaga
   SET qtd_pedida = 11, obs = 'Requisição real: 11 no D (ES_140)'
 WHERE turma_inicio = '2026-11-16' AND turno = 'D' AND etapa = 'ABERTA';

SELECT 'depois' AS momento, id, turma_inicio, turno, qtd_pedida, qtd_contratada, etapa, obs
  FROM esc_requisicao_vaga WHERE turma_inicio = '2026-11-16' ORDER BY turno;
