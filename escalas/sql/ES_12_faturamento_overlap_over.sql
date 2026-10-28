-- =====================================================================
-- ES_12_faturamento_overlap_over.sql  (revisão de 28/10)
-- 1. As vagas são preenchidas da página de maior faturamento para a de menor
--    (US$/dia do turno, `5c_wfm_db_mis`.tab_statement_interval, últimos
--    v4_receita_dias dias).
-- 2. Overlap (dia de treinamento): o SR vai para a página 5C Schedule com
--    "-5" no código. Se sobrar gente de Overlap e houver lacuna, ele cobre a
--    lacuna, dentro da capacidade do posto.
-- 3. Lacuna que sobra: um SR já alocado no turno acumula o posto, se a
--    Política deixa juntar as páginas, até v4_over_capacity_max (15%) acima da
--    capacidade. Acima de 100% fica marcado como SOBRECARGA (⚠ na grade):
--    previsão de problema de capacity.
-- 4. Páginas dos tiers SS, A e B (Intraday): o SR com a maior venda/hora na
--    página (cap_rep_hora, últimos v4_receita_dias dias; sem histórico na
--    página, a venda/hora geral) entra antes da equipe fixa.
-- Rodar depois do ES_11. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

ALTER TABLE esc_plano_alocacao
  MODIFY origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA',
                     'VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO','OVERLAP','SOBRECARGA') NOT NULL;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('v4_receita_dias',          '28',          'INT',     'v4', 'Dias de faturamento (statement) para ordenar o preenchimento das vagas'),
  ('v4_over_capacity_max',     '0.15',        'DECIMAL', 'v4', 'Quanto um SR pode passar da capacidade ao acumular um posto em lacuna (sinalizado)'),
  ('v4_overlap_pagina',        '5C Schedule', 'TEXTO',   'v4', 'Página onde o Overlap (dia de treinamento) entra, com -5 no código'),
  ('v4_tiers_melhores_srs',    'SS,A,B',      'TEXTO',   'v4', 'Tiers do Intraday em que o melhor SR do turno (venda/hora na página) entra antes da equipe fixa')
    AS n ON DUPLICATE KEY UPDATE valor = n.valor, descricao = n.descricao;

-- Conferência
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_alocacao' AND COLUMN_NAME = 'origem';
SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_receita_dias', 'v4_over_capacity_max', 'v4_overlap_pagina', 'v4_overlap_posicao_ciclo', 'v4_tiers_melhores_srs');
SELECT id, name, active FROM `5c_pages`.pages WHERE name = '5C Schedule';
