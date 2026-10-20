-- =====================================================================
-- 5C | Falhas Administrativas (AF) — diagnóstico (SOMENTE LEITURA)
-- Objetivo: recalibrar `peso_falha_adm` (hoje 0,53) do 5c_planning e
--           separar AF por causa (escala errada x realocação não registrada
--           x rep em turno próprio).
-- Rodar no Navicat (servidor .11). Nenhum comando grava nada.
-- Exportar cada resultado como CSV com o nome Qn (Q0 … Q9).
-- Janela: dias FECHADOS (hoje fica de fora), 8 semanas.
-- =====================================================================
SET NAMES utf8mb4;
SET @ate   = DATE_SUB(CURDATE(), INTERVAL 1 DAY);          -- último dia fechado
SET @de    = DATE_SUB(@ate, INTERVAL 55 DAY);               -- 56 dias
SET @ajuste_h = 0;   -- horas a somar no DATE_TIME do msgdashboard para casar com a escala
                     -- (v_ajuste_fuso da procedure; manter 0 se msgdashboard já estiver em Brasília)

-- ---------------------------------------------------------------------
-- Q0. Estrutura e regra vigente (preciso ver as colunas e a procedure)
-- ---------------------------------------------------------------------
DESCRIBE `5c_wfm_db_mis`.`abs_adh_af`;
SHOW CREATE PROCEDURE `5c_wfm_db_mis`.`sp_abs_adh_af_processar`;
SELECT * FROM `5c_planning`.`fc_parametros`;

-- As consultas abaixo assumem em abs_adh_af as colunas:
--   codigo, code_infloww, slot_inicio (datetime), dia, hora,
--   situacao (ADH/NADH/ABS), classificacao (ADH/NADH/ABS/AF),
--   horas_fora_escala, administrative_failure
-- Se o nome de alguma for diferente, me mande o Q0 que eu ajusto.

-- ---------------------------------------------------------------------
-- Q1. Volume por classificação e por turno (A = T2/T3, C = T4/T5, D = T6/T1)
-- ---------------------------------------------------------------------
SELECT
  CASE HOUR(a.slot_inicio) WHEN 5 THEN 'A' WHEN 9 THEN 'A'
                           WHEN 13 THEN 'C' WHEN 17 THEN 'C'
                           WHEN 21 THEN 'D' WHEN 1 THEN 'D' ELSE '?' END AS turno,
  a.classificacao,
  COUNT(*)                                   AS slots,
  COUNT(DISTINCT a.codigo)                   AS reps,
  COUNT(DISTINCT a.codigo, a.dia)            AS rep_dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
GROUP BY 1, 2
ORDER BY 1, 2;

-- ---------------------------------------------------------------------
-- Q2. Série semanal: escalados, ABS, AF, NADH (tendência)
-- ---------------------------------------------------------------------
SELECT
  DATE_SUB(a.dia, INTERVAL WEEKDAY(a.dia) DAY)                 AS semana_seg,
  COUNT(*)                                                      AS slots_escalados,
  SUM(a.classificacao = 'ABS')                                  AS abs_,
  SUM(a.classificacao = 'AF')                                   AS af,
  SUM(a.classificacao = 'NADH')                                 AS nadh,
  ROUND(100 * SUM(a.classificacao = 'ABS') / COUNT(*), 2)       AS pct_abs,
  ROUND(100 * SUM(a.classificacao = 'AF')  / COUNT(*), 2)       AS pct_af,
  ROUND(100 * SUM(a.classificacao IN ('ABS','AF')) / COUNT(*), 2) AS pct_abs_mais_af
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
GROUP BY 1
ORDER BY 1;

-- ---------------------------------------------------------------------
-- Q3. Sensibilidade do limiar de 3 h: distribuição de horas_fora_escala
--     nos rep-dias com falta (ABS ou AF)
-- ---------------------------------------------------------------------
SELECT
  LEAST(a.horas_fora_escala, 8)       AS horas_fora_escala,
  SUM(a.classificacao = 'ABS')        AS slots_abs,
  SUM(a.classificacao = 'AF')         AS slots_af,
  COUNT(DISTINCT a.codigo, a.dia)     AS rep_dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
  AND a.classificacao IN ('ABS','AF')
GROUP BY 1
ORDER BY 1;

-- ---------------------------------------------------------------------
-- Q4. Por rep: AF x ABS x escalados, time/party atual e turno predominante
-- ---------------------------------------------------------------------
WITH base AS (
  SELECT a.codigo, a.code_infloww,
         COUNT(*)                        AS slots,
         SUM(a.classificacao = 'AF')     AS af,
         SUM(a.classificacao = 'ABS')    AS abs_,
         COUNT(DISTINCT CASE WHEN a.classificacao = 'AF' THEN a.dia END) AS dias_af
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate
  GROUP BY a.codigo, a.code_infloww
),
turno_pred AS (
  SELECT codigo, turno FROM (
    SELECT a.codigo,
           CASE HOUR(a.slot_inicio) WHEN 5 THEN 'A' WHEN 9 THEN 'A' WHEN 13 THEN 'C'
                                    WHEN 17 THEN 'C' WHEN 21 THEN 'D' WHEN 1 THEN 'D' END AS turno,
           ROW_NUMBER() OVER (PARTITION BY a.codigo ORDER BY COUNT(*) DESC) AS rn
    FROM `5c_wfm_db_mis`.`abs_adh_af` a
    WHERE a.dia BETWEEN @de AND @ate
    GROUP BY a.codigo, 2
  ) t WHERE rn = 1
)
SELECT b.codigo, b.code_infloww,
       e.time_atual, e.party_atual, e.cargo_atual,
       tp.turno                       AS turno_predominante,
       b.slots, b.af, b.dias_af, b.abs_,
       ROUND(100 * b.af / b.slots, 1)   AS pct_af,
       ROUND(100 * b.abs_ / b.slots, 1) AS pct_abs
FROM base b
LEFT JOIN turno_pred tp ON tp.codigo = b.codigo
LEFT JOIN (
  SELECT codigo COLLATE utf8mb4_unicode_ci AS codigo,
         `time` AS time_atual, party AS party_atual, cargo AS cargo_atual
  FROM `5c_wfm_db_mis`.`vw_rep_estrutura_atual`
) e ON e.codigo = b.codigo COLLATE utf8mb4_unicode_ci
WHERE b.af > 0
ORDER BY b.af DESC, b.slots DESC
LIMIT 60;
-- Se vw_rep_estrutura_atual tiver nomes de coluna diferentes (time/party/cargo), me avise.

-- ---------------------------------------------------------------------
-- Q5. Padrão "escalado X, trabalhou Y": por rep-dia com AF, quais blocos
--     estavam escalados e em quais blocos o rep mandou mensagem
-- ---------------------------------------------------------------------
WITH af_dias AS (
  SELECT DISTINCT a.codigo, a.code_infloww, a.dia
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
),
escalado AS (
  SELECT a.codigo, a.dia,
         GROUP_CONCAT(DISTINCT CONCAT('T', CASE HOUR(a.slot_inicio) WHEN 1 THEN 1 WHEN 5 THEN 2 WHEN 9 THEN 3
                                                   WHEN 13 THEN 4 WHEN 17 THEN 5 WHEN 21 THEN 6 END)
                      ORDER BY HOUR(a.slot_inicio) SEPARATOR '') AS blocos_escalados
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  JOIN af_dias d ON d.codigo = a.codigo AND d.dia = a.dia
  GROUP BY a.codigo, a.dia
),
msgs AS (
  SELECT d.codigo, d.dia,
         GROUP_CONCAT(DISTINCT bloco ORDER BY ordem SEPARATOR '') AS blocos_trabalhados,
         COUNT(*) AS msgs
  FROM af_dias d
  JOIN (
    SELECT SUBSTRING_INDEX(m.SENDER, ' ', 1) AS code_infloww,
           DATE(DATE_SUB(DATE_ADD(m.DATE_TIME, INTERVAL @ajuste_h HOUR), INTERVAL 5 HOUR)) AS dia_turno,
           CASE FLOOR(((HOUR(DATE_ADD(m.DATE_TIME, INTERVAL @ajuste_h HOUR)) + 23) % 24) / 4)
                WHEN 0 THEN 'T1' WHEN 1 THEN 'T2' WHEN 2 THEN 'T3'
                WHEN 3 THEN 'T4' WHEN 4 THEN 'T5' ELSE 'T6' END AS bloco,
           FLOOR(((HOUR(DATE_ADD(m.DATE_TIME, INTERVAL @ajuste_h HOUR)) + 23) % 24) / 4) AS ordem
    FROM `5c_wfm_db`.`msgdashboard` m
    WHERE m.DATE_TIME >= DATE_SUB(@de, INTERVAL 1 DAY)
      AND m.DATE_TIME <  DATE_ADD(@ate, INTERVAL 2 DAY)
      AND m.SOURCE = 'Employee'
  ) x ON x.code_infloww = d.code_infloww COLLATE utf8mb4_unicode_ci
     AND x.dia_turno = d.dia
  GROUP BY d.codigo, d.dia
)
SELECT e.blocos_escalados, COALESCE(mg.blocos_trabalhados, '(sem msg)') AS blocos_trabalhados,
       COUNT(*) AS rep_dias, COUNT(DISTINCT e.codigo) AS reps
FROM escalado e
LEFT JOIN msgs mg ON mg.codigo = e.codigo AND mg.dia = e.dia
GROUP BY 1, 2
ORDER BY rep_dias DESC
LIMIT 40;

-- ---------------------------------------------------------------------
-- Q6. O slot AF ficou descoberto? (a) o próprio rep mandou msg dentro do
--     slot (reproduz os 47%); (b) outra pessoa trabalhou a página no slot
-- ---------------------------------------------------------------------
WITH af_slots AS (
  SELECT a.codigo, a.code_infloww, a.dia, a.slot_inicio,
         DATE_ADD(a.slot_inicio, INTERVAL 4 HOUR) AS slot_fim
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
),
slot_pagina AS (
  SELECT s.*, sd.modeloNome
  FROM af_slots s
  LEFT JOIN `5c_schedule`.`scheduleday` sd
    ON sd.codigo = s.codigo COLLATE utf8mb4_unicode_ci
   AND sd.shiftDateAndHour = DATE_FORMAT(s.slot_inicio, '%Y-%m-%dT%H:%i:%s')
   AND sd.modeloId <> 3
),
msg_win AS (
  SELECT SUBSTRING_INDEX(m.SENDER, ' ', 1) AS code_infloww, m.CREATOR,
         DATE_ADD(m.DATE_TIME, INTERVAL @ajuste_h HOUR) AS dt
  FROM `5c_wfm_db`.`msgdashboard` m
  WHERE m.DATE_TIME >= DATE_SUB(@de, INTERVAL 1 DAY)
    AND m.DATE_TIME <  DATE_ADD(@ate, INTERVAL 2 DAY)
    AND m.SOURCE = 'Employee'
)
SELECT
  COUNT(*)                                                           AS slots_af,
  SUM(proprio.n > 0)                                                 AS rep_mandou_msg_no_slot,
  SUM(outro.n  > 0)                                                  AS outro_rep_cobriu_pagina,
  SUM(proprio.n = 0 AND outro.n = 0)                                 AS descoberto_de_fato,
  ROUND(SUM(proprio.n = 0 AND outro.n = 0) / COUNT(*), 3)            AS peso_sugerido_global,
  SUM(sp.modeloNome IS NULL)                                         AS slots_sem_pagina_na_escala
FROM slot_pagina sp
JOIN LATERAL (
  SELECT COUNT(*) n FROM msg_win w
  WHERE w.code_infloww = sp.code_infloww COLLATE utf8mb4_unicode_ci
    AND w.dt >= sp.slot_inicio AND w.dt < sp.slot_fim
) proprio ON TRUE
JOIN LATERAL (
  SELECT COUNT(*) n FROM msg_win w
  JOIN `5c_bot`.`vw_pagina` p ON p.name = w.CREATOR COLLATE utf8mb4_unicode_ci
  WHERE p.modelo = sp.modeloNome COLLATE utf8mb4_unicode_ci
    AND w.code_infloww <> sp.code_infloww COLLATE utf8mb4_unicode_ci
    AND w.dt >= sp.slot_inicio AND w.dt < sp.slot_fim
) outro ON TRUE;
-- O mesmo, aberto por turno (para peso por turno A/C/D):
-- repita o SELECT acima trocando o primeiro bloco por
--   CASE HOUR(sp.slot_inicio) ... END AS turno, ... GROUP BY turno

-- ---------------------------------------------------------------------
-- Q7. Sinal de mudança tardia na escala: quando a linha do scheduleday
--     foi atualizada em relação ao início do turno (AF x ADH x ABS)
-- ---------------------------------------------------------------------
SELECT a.classificacao,
       CASE
         WHEN sd.updated_at IS NULL THEN 'sem linha na escala'
         WHEN sd.updated_at >= a.slot_inicio THEN 'alterada depois do turno'
         WHEN sd.updated_at >= DATE_SUB(a.slot_inicio, INTERVAL 24 HOUR) THEN 'alterada < 24h antes'
         WHEN sd.updated_at >= DATE_SUB(a.slot_inicio, INTERVAL 72 HOUR) THEN 'alterada 1-3 dias antes'
         ELSE 'estável (> 3 dias)'
       END AS momento_alteracao,
       COUNT(*) AS slots
FROM `5c_wfm_db_mis`.`abs_adh_af` a
LEFT JOIN `5c_schedule`.`scheduleday` sd
  ON sd.codigo = a.codigo COLLATE utf8mb4_unicode_ci
 AND sd.shiftDateAndHour = DATE_FORMAT(a.slot_inicio, '%Y-%m-%dT%H:%i:%s')
 AND sd.modeloId <> 3
WHERE a.dia BETWEEN @de AND @ate
GROUP BY 1, 2
ORDER BY 1, 2;

-- Turnos removidos da origem (espelho da API) nos rep-dias com AF
SELECT COUNT(*) AS turnos_removidos_em_rep_dias_af
FROM `5c_schedule`.`sync_removed_rows` r
JOIN (SELECT DISTINCT codigo, dia FROM `5c_wfm_db_mis`.`abs_adh_af`
      WHERE dia BETWEEN @de AND @ate AND classificacao = 'AF') d
  ON JSON_UNQUOTE(JSON_EXTRACT(r.row_json, '$.codigo')) = d.codigo COLLATE utf8mb4_unicode_ci
 AND DATE(JSON_UNQUOTE(JSON_EXTRACT(r.row_json, '$.shiftDateAndHour'))) = d.dia
WHERE r.table_name = 'scheduleday';

-- ---------------------------------------------------------------------
-- Q8. Recorrência por modelo/página escalada e por time
-- ---------------------------------------------------------------------
SELECT sd.modeloNome, sd.teamName,
       COUNT(*)                          AS slots_af,
       COUNT(DISTINCT a.codigo)          AS reps,
       COUNT(DISTINCT a.dia)             AS dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
JOIN `5c_schedule`.`scheduleday` sd
  ON sd.codigo = a.codigo COLLATE utf8mb4_unicode_ci
 AND sd.shiftDateAndHour = DATE_FORMAT(a.slot_inicio, '%Y-%m-%dT%H:%i:%s')
 AND sd.modeloId <> 3
WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
GROUP BY 1, 2
ORDER BY slots_af DESC
LIMIT 40;

-- ---------------------------------------------------------------------
-- Q9. Persistência: reps com AF em dias seguidos (indício de turno fixo
--     cadastrado errado, não de evento pontual)
-- ---------------------------------------------------------------------
WITH d AS (
  SELECT DISTINCT codigo, dia
  FROM `5c_wfm_db_mis`.`abs_adh_af`
  WHERE dia BETWEEN @de AND @ate AND classificacao = 'AF'
),
seq AS (
  SELECT codigo, dia,
         DATE_SUB(dia, INTERVAL ROW_NUMBER() OVER (PARTITION BY codigo ORDER BY dia) DAY) AS grp
  FROM d
)
SELECT codigo, MIN(dia) AS inicio, MAX(dia) AS fim, COUNT(*) AS dias_seguidos_af
FROM seq
GROUP BY codigo, grp
HAVING COUNT(*) >= 3
ORDER BY dias_seguidos_af DESC, codigo
LIMIT 40;
