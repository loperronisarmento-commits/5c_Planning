SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_39_hc_to_data_nula.sql  (12/11/2026)
--
-- O cálculo diário (ev_fc_forecast_diario → sp_fc_rodar_dia) falha desde 11/11 na etapa hc_to:
--   "Incorrect datetime value: 'null' for function str_to_date"
-- Um cadastro removido do headcount (5c_headcount.sync_removed_rows) veio com startContract = "null"
-- (texto), e o STR_TO_DATE quebra o INSERT em modo estrito. Com a etapa parada, a foto das páginas
-- (fc_pagina_status_diario, etapa paginas_eventos) ficou em 10/11: o Capacity usava o site de 10/11 e
-- deixava de fora quem ativou depois (Louise).
--
-- Mudanças:
--   1. sp_fc_hc_to: a data de contrato só é convertida quando está no formato dd/mm/aaaa; fora disso, NULL.
--      O resto da procedure é o mesmo (copiado do banco em 12/11).
--   2. Postos fixos próprios para as páginas que entraram depois do script 38 e ainda não tinham grupo:
--      Louise (U1C2) e Liv Loxey (U3C1), 1 pessoa nos 3 turnos (com pouco histórico de chat, a carga
--      tirava o posto delas em algum turno).
--   3. Roda o diário de hoje de novo (sp_fc_rodar_dia).
-- Rodar pelo mysql.exe com o 5c.cnf (DELIMITER e CALL).
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, MAX(dia) AS ultima_foto FROM fc_pagina_status_diario;

DELIMITER $$
DROP PROCEDURE IF EXISTS sp_fc_hc_to $$
CREATE PROCEDURE sp_fc_hc_to()
BEGIN

  DECLARE v_gap INT DEFAULT 14;

  DECLARE v_gap_cad INT DEFAULT 45;

  DECLARE v_prox_seg DATE;

  SELECT CAST(valor AS SIGNED) INTO v_gap FROM fc_parametros WHERE chave = 'dias_sem_escala_saida';

  SELECT COALESCE(MAX(CAST(valor AS SIGNED)), 45) INTO v_gap_cad FROM fc_parametros WHERE chave = 'dias_sem_escala_cadastro';

  SET v_prox_seg = CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY + INTERVAL 7 DAY;



  -- turnos de 8 h por rep (dia do turno + A/C/D)

  TRUNCATE TABLE fc_wrk_turnos;

  INSERT INTO fc_wrk_turnos (codigo, nome, td, tu)

  SELECT s.codigo, s.colaboradorNome,

         DATE(STR_TO_DATE(LEFT(s.shiftDateAndHour,19), '%Y-%m-%dT%H:%i:%s') - INTERVAL 5 HOUR),

         CASE SUBSTRING(s.shiftDateAndHour,12,2) WHEN '05' THEN 'A' WHEN '09' THEN 'A'

              WHEN '13' THEN 'C' WHEN '17' THEN 'C' ELSE 'D' END

  FROM `5c_schedule`.scheduleday s

  WHERE s.modeloId <> 3 AND s.shiftDateAndHour >= '2025-01-01T05'

    AND s.codigo NOT IN ('L000000','T000001');



  TRUNCATE TABLE fc_wrk_rep;

  INSERT INTO fc_wrk_rep (codigo, nome, primeira, ultima, ultima_plan)

  SELECT codigo, MAX(nome), MIN(td), MAX(CASE WHEN td < CURDATE() THEN td END), MAX(td)

  FROM fc_wrk_turnos GROUP BY codigo;



  -- (1) HC semanal: rep conta no turno em que mais trabalhou na semana (até a semana atual)

  DELETE FROM fc_hc_semanal;

  INSERT INTO fc_hc_semanal (ano_iso, semana_iso, mes, ano, inicio_semana, hc_a, hc_c, hc_d, hc_total, semana_fechada)

  WITH w AS (

    SELECT t.codigo, c.ano_iso, c.semana_iso, t.tu, COUNT(*) AS n

    FROM fc_wrk_turnos t JOIN fc_calendario c ON c.dia = t.td

    WHERE t.td < v_prox_seg

    GROUP BY t.codigo, c.ano_iso, c.semana_iso, t.tu),

  r AS (SELECT w.*, ROW_NUMBER() OVER (PARTITION BY codigo, ano_iso, semana_iso ORDER BY n DESC, tu) AS rn FROM w)

  SELECT r.ano_iso, r.semana_iso, MIN(c.mes_semana), r.ano_iso, MIN(c.inicio_semana),

         SUM(r.tu='A'), SUM(r.tu='C'), SUM(r.tu='D'), COUNT(*), MIN(c.inicio_semana) < v_prox_seg - INTERVAL 7 DAY

  FROM r JOIN fc_calendario c ON c.ano_iso = r.ano_iso AND c.semana_iso = r.semana_iso AND c.dia_semana = 1

  WHERE r.rn = 1

  GROUP BY r.ano_iso, r.semana_iso;



  -- desligamentos: (a) saiu do cadastro de headcount; (b) sem escala há v_gap dias, sem escala futura e fora do cadastro

  DELETE FROM fc_desligamentos;

  INSERT INTO fc_desligamentos (code, nome, data_saida, turno, origem, primeira_escala, dias_de_casa)

  WITH rm AS (

    SELECT z.code, MAX(z.nome) AS nome, MIN(z.dr) AS dr, MAX(z.adm) AS adm

    FROM (SELECT JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.code')) COLLATE utf8mb4_unicode_ci AS code,

                 JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.name')) COLLATE utf8mb4_unicode_ci AS nome,

                 DATE(removed_at) AS dr,

                 IF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.startContract'))) REGEXP '^[0-9]{1,2}/[0-9]{1,2}/[0-9]{4}$',
                    STR_TO_DATE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.startContract'))),'%d/%m/%Y'), NULL) AS adm  -- script 39: 'null' e texto fora do formato viram NULL

          FROM `5c_headcount`.sync_removed_rows

          WHERE table_name = 'headcount'

            AND JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.position')) = 'Sales Representative') z

    GROUP BY z.code),

  ativos AS (SELECT code COLLATE utf8mb4_unicode_ci AS code FROM `5c_headcount`.headcount),

  ativos_sr AS (SELECT code COLLATE utf8mb4_unicode_ci AS code FROM `5c_headcount`.headcount

                WHERE position = 'Sales Representative'),

  ht AS (SELECT code COLLATE utf8mb4_unicode_ci AS code, tipo_turno COLLATE utf8mb4_unicode_ci AS tu,

                ROW_NUMBER() OVER (PARTITION BY code ORDER BY (vigente_ate IS NULL) DESC, vigente_desde DESC) AS rn

         FROM `5c_headcount`.headcount_turno),

  tm AS (SELECT t.codigo, t.tu, ROW_NUMBER() OVER (PARTITION BY t.codigo ORDER BY COUNT(*) DESC, t.tu) AS rn

         FROM fc_wrk_turnos t JOIN fc_wrk_rep r ON r.codigo = t.codigo

         WHERE t.td BETWEEN r.ultima - INTERVAL 28 DAY AND r.ultima

         GROUP BY t.codigo, t.tu),

  x AS (

    SELECT rm.code, COALESCE(rm.nome, r.nome) AS nome,

           LEAST(rm.dr, IFNULL(r.ultima + INTERVAL 1 DAY, rm.dr)) AS ds, 'HEADCOUNT' AS o,

           COALESCE(r.primeira, rm.adm) AS pri

    FROM rm LEFT JOIN fc_wrk_rep r ON r.codigo = rm.code

    WHERE rm.code NOT IN (SELECT code FROM ativos)

    UNION ALL

    SELECT r.codigo, r.nome, r.ultima + INTERVAL 1 DAY, 'ESCALA', r.primeira

    FROM fc_wrk_rep r

    WHERE r.ultima < CURDATE() - INTERVAL v_gap DAY AND r.ultima_plan < CURDATE()

      AND r.codigo NOT IN (SELECT code FROM ativos)

      AND r.codigo NOT IN (SELECT code FROM rm)

    UNION ALL

    -- (c) ainda no cadastro, mas sem escala há v_gap_cad dias (pode ser afastado: corrigir em fc_desligamento_motivo)

    SELECT r.codigo, r.nome, r.ultima + INTERVAL 1 DAY, 'SEM_ESCALA_CADASTRO', r.primeira

    FROM fc_wrk_rep r

    WHERE r.ultima < CURDATE() - INTERVAL v_gap_cad DAY AND r.ultima_plan < CURDATE()

      AND r.codigo IN (SELECT code FROM ativos_sr)

      AND r.codigo NOT IN (SELECT code FROM rm))

  SELECT x.code, x.nome, x.ds, COALESCE(tm.tu, ht.tu), x.o, x.pri, DATEDIFF(x.ds, x.pri)

  FROM x

  LEFT JOIN tm ON tm.codigo = x.code AND tm.rn = 1

  LEFT JOIN ht ON ht.code = x.code AND ht.rn = 1

  WHERE x.code NOT IN (SELECT code FROM fc_desligamento_motivo WHERE tipo IN ('AFASTADO','NAO_DESLIGADO'));



  -- (2) turnover semanal (todas as semanas, com zero quando não houve saída)

  DELETE FROM fc_to_semanal;

  INSERT INTO fc_to_semanal (ano_iso, semana_iso, mes, ano, inicio_semana, to_a, to_c, to_d, to_sem_turno, to_total,

                             to_ate_90_dias, to_voluntario, to_involuntario)

  SELECT w.ano_iso, w.semana_iso, w.mes_semana, w.ano_iso, w.dia,

         COALESCE(s.ta,0), COALESCE(s.tc,0), COALESCE(s.td,0), COALESCE(s.tt,0) - COALESCE(s.ta,0) - COALESCE(s.tc,0) - COALESCE(s.td,0), COALESCE(s.tt,0),

         COALESCE(s.t90,0), COALESCE(s.tv,0), COALESCE(s.ti,0)

  FROM fc_calendario w

  LEFT JOIN (

    SELECT c.ano_iso, c.semana_iso,

           SUM(d.turno='A') AS ta, SUM(d.turno='C') AS tc, SUM(d.turno='D') AS td, COUNT(*) AS tt,

           SUM(d.dias_de_casa <= 90) AS t90, SUM(m.tipo='VOLUNTARIO') AS tv, SUM(m.tipo='INVOLUNTARIO') AS ti

    FROM fc_desligamentos d

    JOIN fc_calendario c ON c.dia = d.data_saida

    LEFT JOIN fc_desligamento_motivo m ON m.code = d.code

    GROUP BY c.ano_iso, c.semana_iso) s ON s.ano_iso = w.ano_iso AND s.semana_iso = w.semana_iso

  WHERE w.dia_semana = 1 AND w.dia >= '2025-01-06' AND w.dia < v_prox_seg;

END $$
DELIMITER ;

INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs)
SELECT x.carteira, (SELECT COALESCE(MAX(f.grupo), 0) + x.n FROM fc_posto_fixo f WHERE f.carteira = x.carteira AND f.vigente_ate IS NULL),
       1, x.pagina, 1, 0, NULL, '2026-11-12', NULL, 'Script 39: entrou depois do 38, posto próprio nos 3 turnos'
  FROM (SELECT 'U1C2' AS carteira, 'Louise' AS pagina, 1 AS n UNION ALL SELECT 'U3C1', 'Liv Loxey', 1) x
 WHERE NOT EXISTS (SELECT 1 FROM fc_posto_fixo f WHERE f.pagina = x.pagina AND f.vigente_ate IS NULL);

CALL sp_fc_rodar_dia();

SELECT 'depois' AS momento, id, data_calculo, etapa, status, LEFT(mensagem, 120) AS mensagem FROM fc_log_execucao ORDER BY id DESC LIMIT 1;
SELECT 'depois' AS momento, MAX(dia) AS ultima_foto FROM fc_pagina_status_diario;
SELECT 'depois' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.obs, v.precisa, v.tem, v.saldo, v.buffer
  FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM fc_capacity_versao_unidade u
 WHERE u.versao = (SELECT MAX(versao) FROM fc_capacity_versao) ORDER BY u.unidade, u.turno;
SELECT 'depois' AS momento, p.carteira, p.turno, GROUP_CONCAT(p.pagina) AS paginas FROM fc_postos_pagina p
 WHERE p.data_calculo = CURDATE() AND p.pagina IN ('Louise', 'Liv Loxey', 'Lais', 'Isabel') GROUP BY p.carteira, p.turno;
