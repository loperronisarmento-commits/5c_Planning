-- =====================================================================
-- ES_46_folga_juntos_ajustes.sql  (31/10, revisto)
-- Alinha o ciclo dos 3 casais da Folga juntos (ES_45), do mesmo jeito que o
-- botão "Aplicar" da tela Pessoas › Folga juntos, a partir de 09/11 (a semana de
-- 02/11 já está postada):
--   1. OC: Rafael Gauze (R096272, U1 A) troca o ciclo com Raphael Estevam
--      (R095006, U3 A): Gauze 7 → 0 (o do João Caial, OS núcleo 1), Estevam 0 → 7.
--      Os 3 OCs do turno A continuam em ciclos diferentes (0, 14, 7).
--   2. SR: Marcos Vinicius Cid Mélo (M137535) sai da U2 C núcleo 2 para a U2 D
--      núcleo 3: o turno que ele marcou no formulário e o ciclo do Rafael Garcia
--      da Costa (U1 D núcleo 3, ciclo 7, que não muda). Continua na Privacy: a
--      cobertura da Andressa Urach no D é do núcleo 3. Sai das vagas de dono da
--      Andressa C e da Ivy Vale + Keera; a geração completa.
--   3. SR: Iris Castro Moreira (I131039) vai do núcleo 2 para o núcleo 3 da U3 C
--      (ciclo 7, o do Matheus Boaventura). Sai da vaga de dona da Krystal 141m +
--      141t (posto 292); a geração completa.
-- Cada passo só roda se o cadastro ainda estiver como hoje. Pode rodar de novo.
-- Depois: gerar a semana de 09/11.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;
SET @aplicados := 0;

START TRANSACTION;

-- 1. OCs do turno A: troca de ciclo a partir de 09/11 (a linha atual fecha em 08/11)
SET @ok_oc := (SELECT COUNT(*) FROM esc_lideranca
                WHERE ((id = 12 AND UPPER(rep_code) = 'R096272' AND deslocamento = 7) OR (id = 6 AND UPPER(rep_code) = 'R095006' AND deslocamento = 0))
                  AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_lideranca (nivel, unidade, turno, rep_code, nome, team_id, deslocamento, padrao_semana, vigente_desde, vigente_ate, origem)
SELECT nivel, unidade, turno, rep_code, nome, team_id, CASE id WHEN 12 THEN 0 ELSE 7 END, padrao_semana, '2026-11-09', NULL, 'TELA'
  FROM esc_lideranca WHERE id IN (12, 6) AND @ok_oc = 2;
UPDATE esc_lideranca SET vigente_ate = '2026-11-08' WHERE id IN (12, 6) AND @ok_oc = 2;
INSERT INTO esc_log (acao, entidade, entidade_id, depois, canal)
SELECT 'casal.ajuste', 'esc_folga_par', '1', JSON_OBJECT('ajuste', 'Rafael Gauze troca o ciclo com Raphael Estevam (vai para o ciclo 0)', 'desde', '2026-11-09', 'script', 'ES_46'), 'SISTEMA'
  FROM DUAL WHERE @ok_oc = 2;
SET @aplicados := @aplicados + (@ok_oc = 2);

-- Marcos Vinicius Cid Mélo (M137535): U2 D núcleo 3 a partir de 09/11 (marcou A/D, estava no C; casal com Rafael Garcia da Costa (ciclo 7))
SET @okc0 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 471 AND UPPER(rep_code) = 'M137535' AND nucleo_id = 14 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'D', 2, 18, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Folga juntos a partir de 09/11/2026 (ES_46): marcou A/D, estava no C; casal com Rafael Garcia da Costa (ciclo 7)', 'TELA'
  FROM esc_colaborador_config WHERE id = 471 AND @okc0 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 471 AND @okc0 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'M137535' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @okc0 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'casal.ajuste', 'esc_colaborador_config', '471', JSON_OBJECT('nucleo_id', 14), JSON_OBJECT('rep', 'M137535', 'unidade', 2, 'turno', 'D', 'nucleo_id', 18, 'desde', '2026-11-09', 'script', 'ES_46'), 'SISTEMA'
  FROM DUAL WHERE @okc0 = 1;
SET @aplicados := @aplicados + @okc0;

-- Iris Castro Moreira (I131039): U3 C núcleo 3 a partir de 09/11 (casal com Matheus Luz Boaventura (ciclo 7))
SET @okc1 := (SELECT COUNT(*) FROM esc_colaborador_config WHERE id = 387 AND UPPER(rep_code) = 'I131039' AND nucleo_id = 5 AND vigente_ate IS NULL AND vigente_desde < '2026-11-09');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT rep_code, modelo_id, tipo, 'C', 3, 6, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, '2026-11-09', NULL, 'Folga juntos a partir de 09/11/2026 (ES_46): casal com Matheus Luz Boaventura (ciclo 7)', 'TELA'
  FROM esc_colaborador_config WHERE id = 387 AND @okc1 = 1;
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 387 AND @okc1 = 1;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-08' WHERE UPPER(rep_code) = 'I131039' AND vigente_desde < '2026-11-09' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-09') AND @okc1 = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'casal.ajuste', 'esc_colaborador_config', '387', JSON_OBJECT('nucleo_id', 5), JSON_OBJECT('rep', 'I131039', 'unidade', 3, 'turno', 'C', 'nucleo_id', 6, 'desde', '2026-11-09', 'script', 'ES_46'), 'SISTEMA'
  FROM DUAL WHERE @okc1 = 1;
SET @aplicados := @aplicados + @okc1;

COMMIT;

SELECT @aplicados AS aplicados; -- esperado: 3 (OCs, Marcos, Iris)

-- Ciclo de cada pessoa dos 3 pares em 09/11 (os 2 de cada par devem ter o mesmo ciclo)
SELECT p.id AS par, x.rep, x.cargo, x.unidade, x.turno, x.nucleo, x.ciclo
  FROM esc_folga_par p
  JOIN (SELECT UPPER(c.rep_code) AS rep, IF(c.funcao = 'DECURIO', 'OS', 'SR') AS cargo, n.unidade, n.turno, n.numero AS nucleo, n.deslocamento AS ciclo
          FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
         WHERE c.funcao IN ('EQUITE', 'DECURIO') AND c.vigente_desde <= '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09')
        UNION ALL
        SELECT UPPER(l.rep_code), l.nivel, l.unidade, l.turno, NULL, l.deslocamento
          FROM esc_lideranca l
         WHERE l.vigente_desde <= '2026-11-09' AND (l.vigente_ate IS NULL OR l.vigente_ate >= '2026-11-09')) x
    ON x.rep COLLATE utf8mb4_unicode_ci IN (p.rep_a, p.rep_b)
 WHERE p.vigente_ate IS NULL
 ORDER BY p.id, x.rep;
