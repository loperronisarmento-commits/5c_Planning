-- =====================================================================
-- ES_60_saida_arthur_larissa.sql  (01/11)
-- Saída da empresa (informada pelo usuário em 01/11):
--   Arthur Novais Moraes   A098851  (U1, turno A, núcleo 19, SR 3)
--   Larissa Ceola Guchert  L102778  (U1, turno D, núcleo 25, SR 3)
-- Faz o que o Roster faz ao tirar alguém a partir de 01/11: a configuração e as vagas fixas que
-- começam em 01/11 nunca valeram e saem; as anteriores já terminam em 31/10. As vagas ficam sem
-- equipe fixa e o motor cobre. O plano publicado da semana de 02/11 não muda: refazer no site.
-- O fim do arquivo tem como desfazer.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '547', '{"rep":"A098851","nucleo_id":19,"vagas":[1665,2235,2467]}', NULL, NULL),
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '556', '{"rep":"L102778","nucleo_id":25,"vagas":[2252]}', NULL, NULL);

DELETE FROM esc_vaga_equipe WHERE id IN (1665, 2235, 2467, 2252) AND UPPER(rep_code) IN ('A098851', 'L102778');
DELETE FROM esc_colaborador_config WHERE id IN (547, 556) AND UPPER(rep_code) IN ('A098851', 'L102778');

-- Conferência: nada vigente a partir de 01/11
SELECT 'config' AS o_que, COUNT(*) AS n FROM esc_colaborador_config WHERE UPPER(rep_code) IN ('A098851', 'L102778') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01')
UNION ALL
SELECT 'vagas', COUNT(*) FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('A098851', 'L102778') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');

-- ---------------------------------------------------------------------
-- Desfazer (se alguém não saiu):
-- INSERT INTO esc_colaborador_config (id, rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, pode_assistente, vigente_desde, observacao, origem, criado_em, atualizado_em) VALUES
--   (547, 'A098851', 5, 'NUCLEO', 'A', 1, 19, 3, 'EQUITE', 1, '2026-11-01', 'ES_06: ativo fora do modelo de 27/10, sem equipe fixa', 'CARGA_ESTRUTURA', '2026-10-28 11:47:35', '2026-10-30 20:03:19'),
--   (556, 'L102778', 5, 'NUCLEO', 'D', 1, 25, 3, 'EQUITE', 1, '2026-11-01', 'ES_06: ativo fora do modelo de 27/10, sem equipe fixa', 'CARGA_ESTRUTURA', '2026-10-28 11:47:35', '2026-10-30 20:03:19');
-- INSERT INTO esc_vaga_equipe (id, posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, origem, criado_em) VALUES
--   (1665, 492, 'COBERTURA', 'A098851', 1.000, 1, 'PROPOSTA', 'Capacity de 2026-10-30: veio do posto 401; nível 3, fixo na vaga, sem histórico nas páginas', '2026-11-01', 'MONTADOR', '2026-10-30 23:59:50'),
--   (2235, 631, 'COBERTURA', 'A098851', 1.000, 1, 'PROPOSTA', 'Capacity de 2026-10-31: veio do posto 578; Capacity de 2026-10-31: veio do posto 483; vaga sem equipe, completada antes de gerar: ninguém com folga no turno: o menos carregado, 2 dias nas páginas em 8 semanas', '2026-11-01', 'MONTADOR', '2026-10-31 15:49:10'),
--   (2252, 650, 'DONO', 'L102778', 1.000, 1, 'PROPOSTA', 'Capacity de 2026-10-31: veio do posto 583; Capacity de 2026-10-31: veio do posto 488; Capacity de 2026-10-30: veio do posto 392; nível 3, fixo na vaga, sem histórico nas páginas', '2026-11-01', 'MONTADOR', '2026-10-31 15:49:10'),
--   (2467, 755, 'COBERTURA', 'A098851', 1.000, 1, 'PROPOSTA', 'Capacity de 2026-10-31: veio do posto 653; Capacity de 2026-10-31: veio do posto 403; nível 3, fixo na vaga, sem histórico nas páginas', '2026-11-01', 'MONTADOR', '2026-10-31 16:26:45');
