SET NAMES utf8mb4;
-- =====================================================================
-- ES_145 · Mudanças de pessoas na U1 (12/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
-- Os mesmos passos das telas Roster (troca de OS) e Colaboradores (transferência), com vigência em 12/11:
--   1. Jonas Gabriel da Silva Santos (J432005), SR nível 3 da U1 C núcleo 2, vira OS da U1 A núcleo 3 (T2/T3)
--      no lugar do Jan; as 4 vagas fixas dele na U1 C fecham (o motor cobre).
--   2. Jan Roger Maia da Silva (J019571), OS da U1 A núcleo 3, é rebaixado: fica como SR nível 3 no mesmo núcleo.
--   3. Guilherme de Lucca Vicente (G115750), SR nível 5 da U2 D núcleo 2, vai para a U1 D núcleo 3 (o menor time
--      do turno: 7 SRs, nível médio 1,6); a vaga fixa dele na U2 fecha (o fixo tem de ser da unidade da página).
--   4. Maximus Mota Diniz da Silva (M704415), que saiu em 04/11 (ES_112), volta em 13/11 como SR nível 1 na
--      U1 A núcleo 2 (T2/T3; o núcleo com menos SRs, 8).
--   5. fc_estrutura_lider (a Nora lê): Jan sai e Jonas entra como OS da U1 A; e o acerto de 08/11 que faltou
--      (Marcos Vinicius Cid Mélo no lugar do Guilherme como OS da U2 D).
--   6. Recalcula o Capacity de hoje (o "tem" vem do cadastro do 5C Escalas).
-- Folgas: pelo núcleo, no 5x2 rotativo de 21 dias (núcleo 1 = 0, 2 = 14, 3 = 7; âncora 02/11).
-- =====================================================================

USE `5c_escalas`;

-- 0. Antes
SELECT 'antes' AS momento, c.id, c.rep_code, c.funcao, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.rep_code IN ('J432005', 'J019571', 'G115750', 'M704415') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-04') ORDER BY c.rep_code;
SELECT 'antes' AS momento, id, unidade, turno, numero, decurio_code, decurio_nome FROM esc_nucleo WHERE id IN (20, 21, 27);

START TRANSACTION;

-- 1 e 2. Troca do OS da U1 A núcleo 3 (id 21)
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT 'J432005', modelo_id, 'NUCLEO', 'A', 1, 21, NULL, 'DECURIO', NULL, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-12', NULL,
       'roster: J432005 entra como OS U1 · T2/T3 · núcleo 3 no lugar de J019571 (ES_145)', 'TELA'
  FROM esc_colaborador_config WHERE id = 417;
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT 'J019571', modelo_id, 'NUCLEO', 'A', 1, 21, 3, 'EQUITE', NULL, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-12', NULL,
       'roster: rebaixado de OS para SR nível 3 no mesmo núcleo, no lugar dele entra J432005 (ES_145)', 'TELA'
  FROM esc_colaborador_config WHERE id = 398;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-11' WHERE id IN (417, 398) AND vigente_ate IS NULL;
UPDATE esc_nucleo SET decurio_code = 'J432005', decurio_nome = 'Jonas Gabriel da Silva Santos' WHERE id = 21;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-11', motivo = LEFT(CONCAT('roster: virou OS da U1 A núcleo 3 (ES_145); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'J432005' AND vigente_ate IS NULL AND vigente_desde < '2026-11-12';

-- 3. Guilherme: U2 D núcleo 2 → U1 D núcleo 3 (id 27)
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT 'G115750', modelo_id, 'NUCLEO', 'D', 1, 27, 5, 'EQUITE', NULL, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-12', NULL,
       'Transferido da U2 D núcleo 2 para a U1 D núcleo 3 (ES_145)', 'TELA'
  FROM esc_colaborador_config WHERE id = 722;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-11' WHERE id = 722 AND vigente_ate IS NULL;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-11', motivo = LEFT(CONCAT('transferido para a U1 (ES_145); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'G115750' AND vigente_ate IS NULL AND vigente_desde < '2026-11-12';

-- 4. Maximus volta em 13/11 na U1 A núcleo 2 (id 20)
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT 'M704415', modelo_id, 'NUCLEO', 'A', 1, 20, 1, 'EQUITE', NULL, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-13', NULL,
       'Voltou em 13/11 (tinha saído em 04/11): U1 A núcleo 2 (ES_145)', 'TELA'
  FROM esc_colaborador_config WHERE id = 481
   AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config x WHERE x.rep_code = 'M704415' AND x.vigente_desde >= '2026-11-13');

-- 5. Espelho da liderança para a Nora
UPDATE `5c_planning`.`fc_estrutura_lider` SET vigente_ate = '2026-11-11' WHERE code = 'J019571' AND nivel = 'OS' AND vigente_ate IS NULL;
INSERT INTO `5c_planning`.`fc_estrutura_lider` (nome, vigente_desde, vigente_ate, nivel, unidade, turno, oc, code)
SELECT 'Jonas Gabriel da Silva Santos', '2026-11-12', NULL, 'OS', 1, 'A', 'Rafael Gauze Pereira dos Reis', 'J432005' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_estrutura_lider` WHERE code = 'J432005' AND vigente_ate IS NULL);
UPDATE `5c_planning`.`fc_estrutura_lider` SET vigente_ate = '2026-11-07' WHERE code = 'G115750' AND nivel = 'OS' AND vigente_ate IS NULL;
INSERT INTO `5c_planning`.`fc_estrutura_lider` (nome, vigente_desde, vigente_ate, nivel, unidade, turno, oc, code)
SELECT 'Marcos Vinicius Cid Mélo', '2026-11-08', NULL, 'OS', 2, 'D', 'Daniel Fratane Amado', 'M137535' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_estrutura_lider` WHERE code = 'M137535' AND vigente_ate IS NULL);

COMMIT;

-- 6. Capacity de hoje
CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Pessoas: Jonas OS U1 A, Jan SR U1 A, Guilherme U2 D → U1 D, Maximus volta 13/11 na U1 A (ES_145)');

-- 7. Depois
SELECT 'depois' AS momento, c.id, c.rep_code, c.funcao, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.rep_code IN ('J432005', 'J019571', 'G115750', 'M704415') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-11') ORDER BY c.rep_code, c.vigente_desde;
SELECT 'depois' AS momento, id, unidade, turno, numero, decurio_code, decurio_nome FROM esc_nucleo WHERE id IN (20, 21, 27);
SELECT 'depois' AS momento, nome, nivel, unidade, turno, vigente_desde, vigente_ate FROM `5c_planning`.`fc_estrutura_lider` WHERE code IN ('J019571', 'J432005', 'G115750', 'M137535');
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
