-- =====================================================================
-- ES_48_remanejamento_preferencias.sql  (31/10)
-- Remanejamento dos SRs pelas preferências do Shift Preference Form, para
-- diminuir os gaps: as unidades × turnos ficam todas entre 73% e 76% do que o
-- Capacity pede (31/10), contra 64% a 91% hoje; a falta de titulares cai de 24
-- para 18. Cada pessoa só vai para um turno que marcou; mantém o número do
-- núcleo (o mesmo ciclo de folgas) e muda de unidade e/ou turno.
-- 15 SRs (o Marcos Cid está no ES_46, junto com os casais). 4 estavam num turno
-- que não marcaram: Javans, Jean, Giovanna e Julia.
-- Datas: segunda-feira (o motor monta a semana com a estrutura do domingo). Quem
-- vai para um turno mais cedo (C→A, D→C) só muda depois de um domingo de folga,
-- para não emendar turnos sem descanso: Talita em 16/11; Javans, Stephen e
-- Julia em 23/11; os outros em 09/11. Ninguém passa de 5 dias seguidos.
-- As equipes de cada um fecham na véspera; a geração completa as vagas.
-- Cada troca só roda se o cadastro ainda estiver como hoje. Pode rodar de novo.
-- Depois: gerar as semanas de 09/11, 16/11 e 23/11 normalmente.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;
SET @aplicados := 0;

START TRANSACTION;

-- Javans Omondi Onyango (J368655): U1 A núcleo 3 a partir de 23/11 (marcou A/D, estava no C)
SET @ok0 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 415 AND UPPER(rep_code) = 'J368655' AND nucleo_id = 24 AND vigente_ate IS NULL AND vigente_desde < '2026-11-23');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'A', 1, 21, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-23', NULL, 'Remanejamento a partir de 23/11/2026 (ES_48): marcou A/D, estava no C', 'TELA'
  FROM esc_colaborador_config WHERE id = 415 AND @ok0 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-22' WHERE id = 415 AND @ok0 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-22' WHERE UPPER(rep_code) = 'J368655' AND vigente_desde < '2026-11-23' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-23') AND @ok0 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '415', JSON_OBJECT('nucleo_id', 24), JSON_OBJECT('rep', 'J368655', 'unidade', 1, 'turno', 'A', 'nucleo_id', 21, 'desde', '2026-11-23', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok0 = 1;
SET @aplicados := @aplicados + @ok0;

-- Jean Larraine Salvador (J572595): U2 C núcleo 3 a partir de 09/11 (marcou C/D, estava no A)
SET @ok1 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 420 AND UPPER(rep_code) = 'J572595' AND nucleo_id = 12 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 2, 15, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou C/D, estava no A', 'TELA'
  FROM esc_colaborador_config WHERE id = 420 AND @ok1 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 420 AND @ok1 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'J572595' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok1 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '420', JSON_OBJECT('nucleo_id', 12), JSON_OBJECT('rep', 'J572595', 'unidade', 2, 'turno', 'C', 'nucleo_id', 15, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok1 = 1;
SET @aplicados := @aplicados + @ok1;

-- Anna Lícia Goslar de Oliveira (A012621): U3 C núcleo 3 a partir de 09/11 (marcou A/C; U2 A → U3 C)
SET @ok2 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 294 AND UPPER(rep_code) = 'A012621' AND nucleo_id = 12 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 3, 6, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/C; U2 A → U3 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 294 AND @ok2 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 294 AND @ok2 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'A012621' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok2 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '294', JSON_OBJECT('nucleo_id', 12), JSON_OBJECT('rep', 'A012621', 'unidade', 3, 'turno', 'C', 'nucleo_id', 6, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok2 = 1;
SET @aplicados := @aplicados + @ok2;

-- Arthur Magalhães Moura de Lima (A012712): U3 C núcleo 1 a partir de 09/11 (marcou A/C; U2 A → U3 C)
SET @ok3 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 295 AND UPPER(rep_code) = 'A012712' AND nucleo_id = 10 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 3, 4, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/C; U2 A → U3 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 295 AND @ok3 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 295 AND @ok3 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'A012712' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok3 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '295', JSON_OBJECT('nucleo_id', 10), JSON_OBJECT('rep', 'A012712', 'unidade', 3, 'turno', 'C', 'nucleo_id', 4, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok3 = 1;
SET @aplicados := @aplicados + @ok3;

-- Giovanna Fumagalli Ferreira de Melo (G045315): U1 A núcleo 1 a partir de 09/11 (marcou A, estava no C)
SET @ok4 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 367 AND UPPER(rep_code) = 'G045315' AND nucleo_id = 13 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'A', 1, 19, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A, estava no C', 'TELA'
  FROM esc_colaborador_config WHERE id = 367 AND @ok4 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 367 AND @ok4 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'G045315' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok4 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '367', JSON_OBJECT('nucleo_id', 13), JSON_OBJECT('rep', 'G045315', 'unidade', 1, 'turno', 'A', 'nucleo_id', 19, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok4 = 1;
SET @aplicados := @aplicados + @ok4;

-- Stephen Omondi Owino (S799456): U1 C núcleo 3 a partir de 23/11 (marcou C/D; U2 D → U1 C)
SET @ok5 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 515 AND UPPER(rep_code) = 'S799456' AND nucleo_id = 18 AND vigente_ate IS NULL AND vigente_desde < '2026-11-23');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 1, 24, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-23', NULL, 'Remanejamento a partir de 23/11/2026 (ES_48): marcou C/D; U2 D → U1 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 515 AND @ok5 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-22' WHERE id = 515 AND @ok5 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-22' WHERE UPPER(rep_code) = 'S799456' AND vigente_desde < '2026-11-23' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-23') AND @ok5 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '515', JSON_OBJECT('nucleo_id', 18), JSON_OBJECT('rep', 'S799456', 'unidade', 1, 'turno', 'C', 'nucleo_id', 24, 'desde', '2026-11-23', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok5 = 1;
SET @aplicados := @aplicados + @ok5;

-- Talita Carolina Borges (T321412): U1 C núcleo 2 a partir de 16/11 (marcou C/D; U2 D → U1 C)
SET @ok6 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 523 AND UPPER(rep_code) = 'T321412' AND nucleo_id = 17 AND vigente_ate IS NULL AND vigente_desde < '2026-11-16');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 1, 23, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-16', NULL, 'Remanejamento a partir de 16/11/2026 (ES_48): marcou C/D; U2 D → U1 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 523 AND @ok6 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-15' WHERE id = 523 AND @ok6 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-15' WHERE UPPER(rep_code) = 'T321412' AND vigente_desde < '2026-11-16' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-16') AND @ok6 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '523', JSON_OBJECT('nucleo_id', 17), JSON_OBJECT('rep', 'T321412', 'unidade', 1, 'turno', 'C', 'nucleo_id', 23, 'desde', '2026-11-16', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok6 = 1;
SET @aplicados := @aplicados + @ok6;

-- Veronio Batista de Oliveira Junior (V412402): U1 C núcleo 1 a partir de 09/11 (marcou C/D; U2 D → U1 C)
SET @ok7 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 530 AND UPPER(rep_code) = 'V412402' AND nucleo_id = 16 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 1, 22, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou C/D; U2 D → U1 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 530 AND @ok7 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 530 AND @ok7 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'V412402' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok7 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '530', JSON_OBJECT('nucleo_id', 16), JSON_OBJECT('rep', 'V412402', 'unidade', 1, 'turno', 'C', 'nucleo_id', 22, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok7 = 1;
SET @aplicados := @aplicados + @ok7;

-- Marie Jennifer Shandrine Pereira (M801365): U1 D núcleo 2 a partir de 09/11 (marcou A/D; U2 D → U1 D)
SET @ok8 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 482 AND UPPER(rep_code) = 'M801365' AND nucleo_id = 17 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'D', 1, 26, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/D; U2 D → U1 D', 'TELA'
  FROM esc_colaborador_config WHERE id = 482 AND @ok8 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 482 AND @ok8 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'M801365' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok8 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '482', JSON_OBJECT('nucleo_id', 17), JSON_OBJECT('rep', 'M801365', 'unidade', 1, 'turno', 'D', 'nucleo_id', 26, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok8 = 1;
SET @aplicados := @aplicados + @ok8;

-- Poliana Tem Tem Cardozo (P126091): U1 D núcleo 1 a partir de 09/11 (marcou A/D; U2 D → U1 D)
SET @ok9 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 497 AND UPPER(rep_code) = 'P126091' AND nucleo_id = 16 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'D', 1, 25, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/D; U2 D → U1 D', 'TELA'
  FROM esc_colaborador_config WHERE id = 497 AND @ok9 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 497 AND @ok9 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'P126091' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok9 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '497', JSON_OBJECT('nucleo_id', 16), JSON_OBJECT('rep', 'P126091', 'unidade', 1, 'turno', 'D', 'nucleo_id', 25, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok9 = 1;
SET @aplicados := @aplicados + @ok9;

-- Rachel Grace Flores Cellon (R152507): U1 D núcleo 1 a partir de 09/11 (marcou D; U2 D → U1 D)
SET @ok10 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 507 AND UPPER(rep_code) = 'R152507' AND nucleo_id = 16 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'D', 1, 25, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou D; U2 D → U1 D', 'TELA'
  FROM esc_colaborador_config WHERE id = 507 AND @ok10 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 507 AND @ok10 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'R152507' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok10 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '507', JSON_OBJECT('nucleo_id', 16), JSON_OBJECT('rep', 'R152507', 'unidade', 1, 'turno', 'D', 'nucleo_id', 25, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok10 = 1;
SET @aplicados := @aplicados + @ok10;

-- Julia Xavier Broggio (J108431): U2 C núcleo 3 a partir de 23/11 (marcou A/C, estava no D)
SET @ok11 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 407 AND UPPER(rep_code) = 'J108431' AND nucleo_id = 18 AND vigente_ate IS NULL AND vigente_desde < '2026-11-23');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 2, 15, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-23', NULL, 'Remanejamento a partir de 23/11/2026 (ES_48): marcou A/C, estava no D', 'TELA'
  FROM esc_colaborador_config WHERE id = 407 AND @ok11 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-22' WHERE id = 407 AND @ok11 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-22' WHERE UPPER(rep_code) = 'J108431' AND vigente_desde < '2026-11-23' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-23') AND @ok11 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '407', JSON_OBJECT('nucleo_id', 18), JSON_OBJECT('rep', 'J108431', 'unidade', 2, 'turno', 'C', 'nucleo_id', 15, 'desde', '2026-11-23', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok11 = 1;
SET @aplicados := @aplicados + @ok11;

-- Vitor Souza e Silva Rosa (V053618): U1 A núcleo 2 a partir de 09/11 (marcou A/D; U3 A → U1 A)
SET @ok12 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 524 AND UPPER(rep_code) = 'V053618' AND nucleo_id = 2 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'A', 1, 20, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/D; U3 A → U1 A', 'TELA'
  FROM esc_colaborador_config WHERE id = 524 AND @ok12 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 524 AND @ok12 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'V053618' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok12 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '524', JSON_OBJECT('nucleo_id', 2), JSON_OBJECT('rep', 'V053618', 'unidade', 1, 'turno', 'A', 'nucleo_id', 20, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok12 = 1;
SET @aplicados := @aplicados + @ok12;

-- Aline Pinheiro Sodré (A016423): U3 C núcleo 2 a partir de 09/11 (marcou A/C; U3 A → U3 C)
SET @ok13 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 296 AND UPPER(rep_code) = 'A016423' AND nucleo_id = 2 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 3, 5, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/C; U3 A → U3 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 296 AND @ok13 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 296 AND @ok13 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'A016423' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok13 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '296', JSON_OBJECT('nucleo_id', 2), JSON_OBJECT('rep', 'A016423', 'unidade', 3, 'turno', 'C', 'nucleo_id', 5, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok13 = 1;
SET @aplicados := @aplicados + @ok13;

-- Thayla Alessandra da Silva Benkendorf (T099707): U3 C núcleo 3 a partir de 09/11 (marcou A/C; U3 A → U3 C)
SET @ok14 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 518 AND UPPER(rep_code) = 'T099707' AND nucleo_id = 3 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 3, 6, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A/C; U3 A → U3 C', 'TELA'
  FROM esc_colaborador_config WHERE id = 518 AND @ok14 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 518 AND @ok14 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'T099707' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @ok14 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'remanejamento', 'esc_colaborador_config', '518', JSON_OBJECT('nucleo_id', 3), JSON_OBJECT('rep', 'T099707', 'unidade', 3, 'turno', 'C', 'nucleo_id', 6, 'desde', '2026-11-09', 'script', 'ES_48'), 'SISTEMA'
  FROM DUAL WHERE @ok14 = 1;
SET @aplicados := @aplicados + @ok14;

COMMIT;

SELECT @aplicados AS aplicados; -- esperado: 15

-- Conferência: onde cada um fica (a linha nova começa na data do remanejamento)
SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, n.numero AS nucleo, n.deslocamento AS ciclo, c.vigente_desde, c.vigente_ate, c.observacao
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('J368655', 'J572595', 'A012621', 'A012712', 'G045315', 'S799456', 'T321412', 'V412402', 'M801365', 'P126091', 'R152507', 'J108431', 'V053618', 'A016423', 'T099707') AND c.funcao = 'EQUITE' AND c.vigente_desde >= '2026-11-01'
 ORDER BY rep, c.vigente_desde;
