-- =====================================================================
-- ES_70_ajustes_managers_5.sql  (01/11)
-- Quinta leva dos managers para a semana de 02/11:
--   - Correção do ES_68: o "Caetano Júnior" que saiu é o Caetano Aparecido Pedroso Junior (C473710,
--     U3 C), não o Carlos Adriano Junior (C860704). O Carlos volta com a mesma configuração (id 339;
--     as vagas fixas antigas eram de postos que fecham em 02/11 e o "Gerar" completa as novas).
--   - Mikayla Free, T4/T5: o Jonas Santos (J432005) no lugar do Lucas Giusti; o Giusti não faz Mikayla
--     (fora da Free e da VIP/Paid) e vai para a Rocket Reyna no lugar do Jonas. A Mikayla VIP fica
--     como estava: Mari (M443425) e José Melo (J104720) na equipe fixa dela, para testar os dois.
--   - Férias da Rafaela Gaspar (R107827): 03 a 10/11 (antes até 11/11) e 10 a 17/12, aprovadas;
--     o Nicolas Kulik fica na Rocket Reyna até 10/11.
--   - Quem cobre o Caetano em U3 T4/T5, por dia (equipe fixa com data; o motor 1.18.1 respeita a data
--     de cada linha): Ivy Vale 05/11 Alexandre Ferreira; Savannah 04 e 05/11 John Balasta; Kaylin
--     04/11 Alexandre e 05/11 Angela Miyagi; Liam 07 e 08/11 Lucas Dupont; Alexis Clark 07 e 08/11
--     Idris Gambari; Bianca 07 e 08/11 Diego Guedes. (Ivy Vale 04/11 com o Jonas Santos não entra: ele
--     é da U1.) Páginas que dividem posto ficam com uma pessoa só por dia.
-- Já aplicado em 01/11 pelo MCP; o recálculo do Capacity (ES_68) rodou pelo mysql.exe.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_colaborador_config (id, rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
       folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem, criado_em, atualizado_em)
VALUES (339, 'C860704', 5, 'NUCLEO', 'C', 3, 5, 3, 'EQUITE', NULL, NULL, NULL, NULL, 1, '2026-11-01', NULL,
        'Estrutura de 01/11 (modelo de 27/10); devolvido em 01/11 (ES_70): saiu por engano no ES_68', 'CARGA_ESTRUTURA', '2026-10-28 00:12:02', NOW());

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '550', '{"rep":"C473710","nome":"Caetano Aparecido Pedroso Junior","unidade":3,"turno":"C","nucleo_id":6,"vagas":[1392,2196,2324,2388]}', NULL, NULL),
  (NOW(), NULL, 'Claude (correção)', 'SISTEMA', 'CORRECAO', 'esc_colaborador_config', '339', NULL, '{"rep":"C860704","motivo":"saiu por engano no ES_68 (não é o Caetano Júnior); configuração devolvida"}', NULL);
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'C473710' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
DELETE FROM esc_colaborador_config WHERE id = 550 AND UPPER(rep_code) = 'C473710';

-- Férias da Rafaela
DELETE FROM esc_indisponibilidade_data WHERE indisponibilidade_id = 31 AND dia = '2026-11-11';
UPDATE esc_indisponibilidade SET motivo_texto = 'Férias pedidas à empresa (03 a 10/11)' WHERE id = 31;
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, urgente, status, solicitado_em, solicitado_por, decidido_em, comentario_decisao)
VALUES ('R107827', 'VARIOS_DIAS', 'WFM', 'PESSOAL', 'Férias pedidas à empresa (10 a 17/12)', 0, 'APROVADO', NOW(), 'PLANEJAMENTO', NOW(), 'ES_70: pedido dos managers');
SET @ind = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em)
SELECT @ind, DATE_ADD('2026-12-10', INTERVAL n DAY), 'C', NULL, 'APROVADO', NOW()
  FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) d;
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-10', motivo = 'Manager: Nicolas Kulik no lugar da Rafaela Gaspar (férias de 03 a 10/11)'
 WHERE pagina = 'Rocket Reyna VIP' AND rep_codes = 'N087021';

-- Mikayla e Rocket Reyna
UPDATE esc_pagina_prioridade SET rep_codes = 'A283671,M443425,J104720', motivo = 'Manager: Angeline (T6/T1); Mari e José Melo (T4/T5) na Mikayla VIP'
 WHERE pagina = 'Mikayla Paid' AND rep_codes = 'A283671' AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate) VALUES
  ('Mikayla Free', 'J432005', 'Manager: Jonas Santos no lugar do Lucas Giusti (T4/T5)', '2026-11-02', NULL),
  ('Rocket Reyna VIP', 'L007270', 'Manager: Lucas Giusti no lugar do Jonas Santos (T4/T5)', '2026-11-02', NULL),
  ('Ivy Vale', 'A007546', 'Manager: no lugar do Caetano Junior', '2026-11-05', '2026-11-05'),
  ('Savannah', 'J010257', 'Manager: no lugar do Caetano Junior', '2026-11-04', '2026-11-05'),
  ('Kaylin', 'A007546', 'Manager: no lugar do Caetano Junior', '2026-11-04', '2026-11-04'),
  ('Kaylin', 'A012515', 'Manager: no lugar do Caetano Junior', '2026-11-05', '2026-11-05'),
  ('Liam', 'L099242', 'Manager: no lugar do Caetano Junior', '2026-11-07', '2026-11-08'),
  ('Alexis Clark', 'S154683', 'Manager: no lugar do Caetano Junior', '2026-11-07', '2026-11-08'),
  ('Bianca', 'D105788', 'Manager: no lugar do Caetano Junior', '2026-11-07', '2026-11-08');
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde) VALUES
  ('Mikayla Free', 'L007270', 'FORA', 'Manager: Giusti não faz Mikayla', '2026-11-02'),
  ('Mikayla Paid', 'L007270', 'FORA', 'Manager: Giusti não faz Mikayla', '2026-11-02');
