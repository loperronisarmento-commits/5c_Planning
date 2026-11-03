SET NAMES utf8mb4;
-- =====================================================================
-- ES_91_merito_pontos_motor_alocacao.sql  (03/11, 5C Escalas 1.19.0)
-- Aplicado em 03/11/2026, na publicação da 1.19.0 (ok do usuário).
--
-- 1) Nota em pontos do mérito (regras M-01 a M-09 de contexto/regras_merito.md, validadas em 02/11):
--    o cache esc_sr_merito ganha uma coluna por componente, a faixa e os dados que as telas mostram;
--    a coluna nota passa a guardar a soma dos pontos. Parâmetros novos merito_pts_*. Inclui a M-10
--    (sequência de semanas batendo a meta, validada em 03/11).
-- 2) Motor de alocações (regras A-01 a A-04 de contexto/regras_motor_alocacao.md, validadas em 03/11):
--    passo 0 juntado com a P-01 (todas as páginas, de cima para baixo, com continuidade), na ordem
--    meta na página >= 65%, faixa, nota em pontos, tempo de casa; faixa também nos passos 5, 6 e 7.
--    Cada chave v4_merito_* = 0 volta o passo à regra da 1.18.
-- O código da 1.19.0 funciona sem este script (usa os padrões e grava só as colunas que existem),
-- mas o escopo de todas as páginas (v4_melhores_receita_min = 0) só vale depois dele.
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_sr_merito
  ADD COLUMN pts_tier SMALLINT NULL AFTER nota,
  ADD COLUMN pts_meta SMALLINT NULL AFTER pts_tier,
  ADD COLUMN pts_faltas SMALLINT NULL AFTER pts_meta,
  ADD COLUMN pts_sla SMALLINT NULL AFTER pts_faltas,
  ADD COLUMN pts_casa SMALLINT NULL AFTER pts_sla,
  ADD COLUMN pts_vendas SMALLINT NULL AFTER pts_casa,
  ADD COLUMN pts_cargo SMALLINT NULL AFTER pts_vendas,
  ADD COLUMN pts_infracao SMALLINT NULL AFTER pts_cargo,
  ADD COLUMN pts_seq SMALLINT NULL AFTER pts_infracao,
  ADD COLUMN faixa ENUM('ACIMA','MEDIA','ABAIXO') NULL AFTER pts_seq,
  ADD COLUMN faltas SMALLINT NULL AFTER faixa,
  ADD COLUMN tier VARCHAR(3) NULL AFTER faltas,
  ADD COLUMN pagina_principal VARCHAR(120) NULL AFTER tier,
  ADD COLUMN venda_h DECIMAL(9,2) NULL AFTER pagina_principal,
  ADD COLUMN anos SMALLINT NULL AFTER venda_h,
  ADD COLUMN semanas_meta SMALLINT NULL AFTER anos;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao, atualizado_em) VALUES
  ('merito_pts_tier', 'F:0,E:1,D:2,C:3,B:5,A:8,S:8,SS:8', 'TEXTO', 'merito', 'M-02: pontos pelo tier da página principal do SR (a de mais horas na janela, regular ou assistente)', NOW()),
  ('merito_pts_meta_passo', '0.10', 'DECIMAL', 'merito', 'M-03: 1 ponto a cada este tanto de atingimento da meta acima ou abaixo de 100%, cortado em direção ao zero', NOW()),
  ('merito_pts_meta_limite', '10', 'INT', 'merito', 'M-03: limite de pontos da meta, para mais e para menos', NOW()),
  ('merito_pts_falta_max_x', '5', 'INT', 'merito', 'M-04: faltas valem −2^(x−1) pontos, com x = dias com falta na janela, até este x (5 = −16)', NOW()),
  ('merito_pts_sla_meta', '0.80', 'DECIMAL', 'merito', 'M-05: SLA a partir do qual não perde ponto', NOW()),
  ('merito_pts_sla_faixa', '0.10', 'DECIMAL', 'merito', 'M-05: a cada faixa deste tamanho abaixo da meta de SLA a pena dobra (−1, −2, −4...)', NOW()),
  ('merito_pts_sla_max_k', '5', 'INT', 'merito', 'M-05: número máximo de faixas abaixo da meta de SLA (5 = −16)', NOW()),
  ('merito_pts_ano', '1', 'DECIMAL', 'merito', 'M-06: pontos por ano completo de empresa (início do contrato no headcount); também desempata', NOW()),
  ('merito_pts_vendas_faixas', '5', 'INT', 'merito', 'M-07: faixas da venda por hora entre os SRs com dado (5 = quintis, de 0 a 4 pontos)', NOW()),
  ('merito_pts_cargo_antigo', '1:5,2:3,3:3,4:1,5:0', 'TEXTO', 'merito', 'M-08: pontos pelo maior cargo da estrutura antiga, pelo código do cargo no histórico (1 líder gerente do time, 2 e 3 outros líderes, 4 segundo, 5 pool)', NOW()),
  ('merito_pts_cargo_ate', '2026-10-31', 'TEXTO', 'merito', 'M-08: último dia do histórico de cargos que conta (a estrutura antiga terminou em 31/10)', NOW()),
  ('merito_pts_infracao', '2', 'DECIMAL', 'merito', 'M-09: pontos tirados por infração dos últimos merito_pts_infracao_dias dias', NOW()),
  ('merito_pts_infracao_dias', '90', 'INT', 'merito', 'M-09: dias em que a infração conta', NOW()),
  ('merito_pts_seq_semanas', '4', 'INT', 'merito', 'M-10: quantas semanas fechadas (segunda a domingo) a sequência olha, da última para trás', NOW()),
  ('merito_pts_seq_meta', '1.00', 'DECIMAL', 'merito', 'M-10: atingimento da meta na semana (mesma conta da M-03) para a semana contar na sequência', NOW()),
  ('merito_pts_seq', '1:1,2:3,3:5', 'TEXTO', 'merito', 'M-10: pontos pela sequência de semanas batendo a meta (semanas:pontos; o último degrau vale "ou mais")', NOW()),
  ('merito_pts_faixa_acima', '8', 'DECIMAL', 'merito', 'Faixa Acima: nota em pontos a partir deste valor', NOW()),
  ('merito_pts_faixa_abaixo', '-8', 'DECIMAL', 'merito', 'Faixa Abaixo: nota em pontos menor que este valor', NOW()),
  ('v4_melhores_meta_min', '0.65', 'DECIMAL', 'v4', 'Passo 0: quem tem este atingimento da meta na página (30 dias) ou mais vem antes de tudo, também da faixa (A-01)', NOW()),
  ('v4_merito_faixa_passo0', '1', 'BOOL', 'v4', 'A-01/P-01: no passo 0 escolhe por meta na página, faixa, nota em pontos e tempo de casa, com continuidade (0 = venda por hora, como na 1.18)', NOW()),
  ('v4_merito_abaixo_fora_topo', '1', 'BOOL', 'v4', 'A-02: o SR Abaixo só fica como regular em página prioritária sem outro disponível, sinalizado; no remanejamento não sobe para ela', NOW()),
  ('v4_merito_faixa_acumulo', '1', 'BOOL', 'v4', 'A-03: no acúmulo e na sobrecarga a faixa é o 1º critério (o Abaixo vai por último; nada é proibido)', NOW()),
  ('v4_merito_faixa_remanejar', '1', 'BOOL', 'v4', 'A-04: no remanejamento sobe o SR de melhor faixa (no empate, o do posto que menos fatura); equipe fixa não sai da página dela', NOW()),
  ('v4_pagina_prioritaria_min', '100000', 'DECIMAL', 'v4', 'Página prioritária (A-02): modelo com este faturamento em 30 dias (US$) ou mais', NOW())
ON DUPLICATE KEY UPDATE valor = VALUES(valor), tipo = VALUES(tipo), grupo = VALUES(grupo), descricao = VALUES(descricao), atualizado_em = NOW();

-- P-01 junto da A-01: o passo 0 vale para todas as páginas (decisão de 03/11).
UPDATE esc_parametro SET valor = '0', atualizado_em = NOW(),
       descricao = 'Passo 0 nas páginas das modelos com este faturamento em 30 dias (US$) ou mais; 0 = todas as páginas, de cima para baixo (P-01, desde a 1.19.0)'
 WHERE chave = 'v4_melhores_receita_min';

-- Parâmetros da fórmula antiga, fora de uso desde a 1.19.0 (ficam para consulta).
UPDATE esc_parametro SET descricao = LEFT(CONCAT('Fora de uso desde a 1.19.0 (nota em pontos). ', descricao), 255), atualizado_em = NOW()
 WHERE chave IN ('merito_abs_ativo', 'merito_abs_tolerancia', 'merito_abs_critico', 'merito_abs_piso', 'merito_abs_teto_critico',
                 'merito_min_respostas', 'merito_infracao_pesos', 'merito_infracao_meia_vida_dias', 'merito_infracao_janela_dias')
   AND descricao NOT LIKE 'Fora de uso%';

-- O cache da fórmula antiga é refeito em pontos na próxima leitura (sem a faixa, o 1.19.0 recalcula).
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'parametro', 'esc_parametro', 'ES_91',
        '{"merito":"Desempenho x SLA x Presença / (1 + pena)","v4_melhores_receita_min":"100000"}',
        '{"merito":"nota em pontos M-01 a M-10","v4_melhores_receita_min":"0","regras":"A-01 a A-04","script":"ES_91"}', NULL);

-- Conferência
SELECT chave, valor FROM esc_parametro WHERE chave LIKE 'merito_pts_%' OR chave LIKE 'v4_merito_%' OR chave IN ('v4_melhores_meta_min', 'v4_melhores_receita_min', 'v4_pagina_prioritaria_min') ORDER BY chave;
SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_sr_merito' ORDER BY ORDINAL_POSITION;
