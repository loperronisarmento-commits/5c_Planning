SET NAMES utf8mb4;
-- =====================================================================
-- ES_148 · Relato do OM da U3 (12/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
-- Vigência: a partir de 12/11 (os cadastros fecham em 11/11). Os mesmos passos da tela Colaboradores.
--   1. Angela Hikari Miyagi (A012515): cadastro estava na U3 C núcleo 1, mas ela trabalha na U1 C (a escala
--      travada da U1 a usa em Levi, Rebecca e Charly, com as folgas do núcleo 1). Passa para a U1 C núcleo 1,
--      nível 3; as 3 vagas fixas na U3 fecham e ela sai do COVER de Blake, Mario Adrion e Liam (páginas da U3).
--   2. Saídas de SR (cadastro fechado; seguem no headcount até o RH tirar, e aparecem em Fora das unidades):
--      Fernanda Bezerra de Oliveira (F058412, U3 C n1): o RH já a tirou do headcount em 10/11; último turno 04/11.
--      Yasmim Batista Barbosa (Y099118, U3 D n2): desligamento; último turno 03/11.
--      Francisco Joshua dos Santos Leite (F472566, U3 D n3): sem escala desde 31/10 (o OM não tinha certeza).
--      As vagas fixas fecham e Yasmim e Francisco saem da equipe fixa da Savannah (Fanvue).
--   3. Recalcula o Capacity de hoje.
-- =====================================================================

USE `5c_escalas`;

SELECT 'antes' AS momento, c.id, c.rep_code, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.rep_code IN ('A012515', 'F058412', 'Y099118', 'F472566') AND c.vigente_ate IS NULL;

START TRANSACTION;

-- 1. Angela → U1 C núcleo 1 (id 22)
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, 'NUCLEO', 'C', 1, 22, nivel, 'EQUITE', fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-12', NULL,
       'Trabalha na U1 C (escala da OM da U1); cadastro estava na U3 C núcleo 1 (relato do OM da U3, ES_148)', 'TELA'
  FROM esc_colaborador_config WHERE id = 293 AND vigente_ate IS NULL;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-11' WHERE id = 293 AND vigente_ate IS NULL;

-- 2. Saídas: fecha o cadastro
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-11',
       observacao = LEFT(CONCAT('Saiu (relato do OM da U3 em 12/11, ES_148); ', IFNULL(observacao, '')), 255)
 WHERE rep_code IN ('F058412', 'Y099118', 'F472566') AND vigente_ate IS NULL;

-- Vagas fixas das quatro
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-11', motivo = LEFT(CONCAT('ES_148: saiu ou mudou de unidade; ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) IN ('A012515', 'F058412', 'Y099118', 'F472566') AND vigente_ate IS NULL AND vigente_desde < '2026-11-12';

-- Equipes fixas: nova versão sem essas pessoas
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT pagina,
       TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(CONCAT(',', REPLACE(rep_codes, ' ', ''), ','), ',A012515,', ','), ',F472566,', ','), ',Y099118,', ',')),
       papel, LEFT(CONCAT(motivo, ' · ES_148: sem quem saiu ou mudou de unidade'), 255), '2026-11-12', NULL
  FROM esc_pagina_prioridade WHERE id IN (28, 119, 145, 183) AND vigente_ate IS NULL;
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-11' WHERE id IN (28, 119, 145, 183) AND vigente_ate IS NULL;

COMMIT;

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Relato do OM da U3: Angela na U1 C; saídas de Fernanda, Yasmim e Francisco (ES_148)');

SELECT 'depois' AS momento, c.id, c.rep_code, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.rep_code IN ('A012515', 'F058412', 'Y099118', 'F472566') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-11');
SELECT 'depois' AS momento, id, pagina, papel, rep_codes, vigente_desde, vigente_ate FROM esc_pagina_prioridade
 WHERE pagina IN ('Savannah', 'Blake', 'Mario Adrion', 'Liam') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-11') ORDER BY pagina, vigente_desde;
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM `5c_planning`.`fc_capacity_versao_unidade` u
 WHERE u.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`) ORDER BY u.unidade, u.turno;
