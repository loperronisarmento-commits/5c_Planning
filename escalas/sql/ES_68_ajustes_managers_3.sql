-- =====================================================================
-- ES_68_ajustes_managers_3.sql  (01/11)
-- Terceira leva de pedidos dos managers (Natan) para a semana de 02/11:
--   - Mikayla Paid, T6/T1 (turno D): sai a Melody Vale (M203717), entra a Angeline Butal (A283671).
--   - Mikayla Free: a Sheila (S349873) sai da página.
--   - Rocket Reyna, turno D: a Melody Vale no lugar do Rodrigo Felice (a página está em feature);
--     os assistentes ficam.
--   - Sem assistente (posto fixo de 2 para 1 pessoa): Elly Clutch, Liv e Madison Moore (VIP e Free).
--   - Liv (SFW, página sensível): o José Feliciano (J337675) não entra nela e vai para a Elly no
--     turno C; o Matteo Guedes (M036514) fica com a Liv no C; o Lucas Moraes Gomes de Oliveira
--     (L389704: 44 turnos na Liv em 60 dias, 151% da meta) vai da U2 D para a U3 D, núcleo 7, e fica
--     com a Liv no D.
--   - Izabelly Lima (I084013) não trabalha mais: sai a configuração e as vagas fixas.
--   - Maddie saiu da 5C (tinha sido reativada por engano no site): página inativa, foto do dia
--     corrigida e fora dos postos fixos.
--   - Páginas por OS pelo valor (lib/estrutura.php, 1.18.1): v4_os_equilibrio liga a divisão e
--     v4_os_novato marca o Jan Roger (núcleo 21, U1 A), novato na liderança, para páginas menores.
-- Depois, recalcular os postos do Capacity (CALL abaixo, ou o "Gerar").
-- Já aplicado em 01/11 pelo MCP (menos o CALL).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde) VALUES
  ('Mikayla Paid', 'M203717', 'FORA', 'Manager: Melody vai bem, mas não na Mikayla; vai para a Rocket Reyna', '2026-11-02'),
  ('Mikayla Free', 'S349873', 'FORA', 'Manager: Sheila sai da Mikayla Free', '2026-11-02'),
  ('Liv', 'J337675', 'FORA', 'Manager: Liv é SFW; o José é acostumado a club/scam', '2026-11-02');

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde) VALUES
  ('Mikayla Paid', 'A283671', 'Manager: Angeline no lugar da Melody na Mikayla Paid (T6/T1)', '2026-11-02'),
  ('Rocket Reyna VIP', 'M203717', 'Manager: Melody no lugar do Rodrigo Felice (página em feature)', '2026-11-02'),
  ('Liv', 'M036514,L389704', 'Manager: Matteo (T4/T5) e Lucas Moraes (T6/T1) na Liv, página SFW sensível', '2026-11-02'),
  ('Elly Clutch 100m', 'J337675', 'Manager: José Feliciano na Elly (T4/T5)', '2026-11-02');

-- Lucas Moraes: U2 D -> U3 D (núcleo 7) a partir de 02/11; vagas fixas da U2 fecham em 01/11.
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha,
       ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'D', 3, 7, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente,
       '2026-11-02', NULL, 'ES_68: Liv no turno D', 'TELA'
  FROM esc_colaborador_config WHERE id = 448 AND rep_code = 'L389704';
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-01' WHERE id = 448 AND rep_code = 'L389704';
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-01' WHERE rep_code = 'L389704' AND vigente_ate IS NULL AND vigente_desde <= '2026-11-01';

-- Izabelly Lima: saiu da empresa.
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '555', '{"rep":"I084013","nucleo_id":9,"vagas":[1781,2187,2205]}', NULL, NULL);
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'I084013' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
DELETE FROM esc_colaborador_config WHERE id = 555 AND UPPER(rep_code) = 'I084013';

INSERT INTO esc_parametro (chave, valor, grupo, tipo, descricao, atualizado_em) VALUES
  ('v4_os_equilibrio', '1', 'v4', 'BOOL', 'Divide as páginas dos postos novos entre os OSs do turno pelo faturamento (comissão)', NOW()),
  ('v4_os_novato', '21', 'v4', 'TEXTO', 'Núcleos de OS novato na liderança (ids): só páginas até a mediana do turno, cerca de metade do valor', NOW());

-- Sem assistente
UPDATE `5c_planning`.`fc_posto_fixo` SET pessoas = 1, obs = LEFT(CONCAT('ES_68: sem assistente; ', IFNULL(obs, '')), 255)
 WHERE vigente_ate IS NULL AND pagina IN ('Elly Clutch 100m', 'Liv', 'Madison Moore VIP 115m', 'Madison Moore Free 115s');

-- Maddie saiu da 5C
UPDATE `5c_pages`.`pages` SET active = 0 WHERE id = 412 AND name = 'Maddie';
UPDATE `5c_planning`.`fc_pagina_status_diario` SET ativo = 0 WHERE page_id = 412 AND dia = '2026-11-01';
DELETE FROM `5c_planning`.`fc_posto_fixo` WHERE id = 296 AND pagina = 'Maddie';

-- Rodar no Navicat (o MCP não faz CALL):
-- CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'ES_68');

-- Saídas da 5C informadas depois (Natan, 01/11): Demétrius Carvalho (D861706) e o "Caetano Júnior",
-- que pelo histórico é o Carlos Adriano Junior dos Santos Carvalho (C860704, U3 C: Alexis Clark,
-- Blake, Eden, Mario Adrion, Lily Lou, Kaylin e Mandi no T4/T5). Para desfazer, o esc_log guarda a
-- configuração e as vagas de cada um.
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '551', '{"rep":"D861706","unidade":3,"turno":"D","nucleo_id":8,"vagas":[1018,2390,2398]}', NULL, NULL),
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '339', '{"rep":"C860704","unidade":3,"turno":"C","nucleo_id":5,"vagas":[2195,2339]}', NULL, NULL);
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('D861706', 'C860704') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
DELETE FROM esc_colaborador_config WHERE id IN (551, 339) AND UPPER(rep_code) IN ('D861706', 'C860704');

-- Lucas Moraes (L389704) na Liv em todos os turnos possíveis do T6/T1, temporariamente, até ter mais
-- gente: fora da rotação do núcleo, com uma folga a cada 15 dias (motor 1.18.1).
INSERT INTO esc_parametro (chave, valor, grupo, tipo, descricao, atualizado_em) VALUES
  ('v4_todos_os_dias', 'L389704', 'v4', 'TEXTO', 'SRs que trabalham todos os dias do turno, fora da rotação (temporário): Lucas Moraes na Liv', NOW()),
  ('v4_todos_os_dias_seguidos', '14', 'v4', 'INT', 'Dias seguidos antes da folga de quem trabalha todos os dias (14 = uma folga a cada 15 dias)', NOW());

-- Saída da 5C informada depois: Caroline Gonçalves Souza (C051206, U1 A, núcleo 21).
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido dos managers)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '331', '{"rep":"C051206","unidade":1,"turno":"A","nucleo_id":21,"vagas":[2229,2466]}', NULL, NULL);
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'C051206' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
DELETE FROM esc_colaborador_config WHERE id = 331 AND UPPER(rep_code) = 'C051206';
