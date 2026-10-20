-- =====================================================================
-- 5C | Falhas Administrativas (AF) — diagnóstico (SOMENTE LEITURA)
-- Objetivo: recalibrar `peso_falha_adm` (hoje 0,53) do 5c_planning e
--           separar AF por causa (escala errada x realocação não registrada
--           x rep em turno próprio).
-- Rodar no Navicat (servidor .11). Nenhum comando grava nada.
-- Exportar cada resultado como CSV com o nome Qn (Q0 … Q10).
-- Janela: dias FECHADOS (hoje fica de fora), 8 semanas.
--
-- REVISÃO 20/10/2026 (rodado pelo MCP, janela 25/08–19/10) — ajustes
-- feitos depois do Q0, registrados aqui e no AF_02_parecer.md:
--  1. Colunas de abs_adh_af conferem com o previsto. Mas `dia` é o dia de
--     CALENDÁRIO (DATE(slot_inicio)), não o dia do turno, e `hora` só guarda
--     a hora de início do slot (1, 5, 9, 13, 17, 21).
--  2. v_ajuste_fuso = 0 na procedure, e escala e mensagens estão no mesmo
--     fuso (clockIn 20:58 no slot T6/T1; hora 00 da tab_message_interval = T6).
--     @ajuste_h fica 0.
--  3. A procedure usa v_min_horas_af = 2 (a documentação diz 3 h).
--  4. A procedure lê `5c_wfm_db_mis.tab_message_interval`, não msgdashboard.
--     Q5/Q6/Q6b passaram a ler a mesma fonte (por hora × rep × página,
--     ~300 mil linhas em 56 dias): mais rápido e igual à regra.
--     Metade das linhas vem em meia hora (:30) -> truncar para a hora cheia.
--  5. Página da escala: scheduleday.modeloNome = tab_message_interval.Creator
--     (122 de 133 páginas casam; o resto é MYM/Fanvue). vw_pagina não é
--     necessária para casar a página; serve só para time/party (Q8), porque
--     scheduleday.teamName vem vazio.
--  6. vw_rep_estrutura_atual: colunas rep_code, team, party, role.
--  7. CASE HOUR(...) WHEN trocado por ELT(FIELD(...)) / IF() (parser do MCP).
--  8. Q6 com JOIN LATERAL e subconsulta correlacionada passava de 60 s:
--     reescrito expandindo o slot em 4 horas e casando por igualdade (hash join).
--  9. Q7: updated_at e imported_at da scheduleday medem o ETL, não a
--     publicação da escala; mantidos só como evidência negativa de H3.
--     Turnos removidos: sync_removed_rows só tem dados desde 03/10.
-- 10. Novas: Q10 (categoria de cada slot AF) e Q10b (peso por turno).
-- =====================================================================
SET NAMES utf8mb4;
SET @ate      = DATE_SUB(CURDATE(), INTERVAL 1 DAY);          -- último dia fechado
SET @de       = DATE_SUB(@ate, INTERVAL 55 DAY);               -- 56 dias
SET @ajuste_h = 0;   -- validado em 20/10: escala e mensagens em Brasília

-- ---------------------------------------------------------------------
-- Q0. Estrutura e regra vigente
-- ---------------------------------------------------------------------
DESCRIBE `5c_wfm_db_mis`.`abs_adh_af`;
SHOW CREATE PROCEDURE `5c_wfm_db_mis`.`sp_abs_adh_af_processar`;
SELECT * FROM `5c_planning`.`fc_parametros`;

-- ---------------------------------------------------------------------
-- Q1. Volume por classificação e por turno (A = T2/T3, C = T4/T5, D = T6/T1)
-- ---------------------------------------------------------------------
SELECT ELT(FIELD(a.hora,5,9,13,17,21,1),'A','A','C','C','D','D') AS turno,
       a.classificacao,
       COUNT(*)                        AS slots,
       COUNT(DISTINCT a.codigo)        AS reps,
       COUNT(DISTINCT a.codigo, a.dia) AS rep_dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
GROUP BY 1, 2
ORDER BY 1, 2;

-- ---------------------------------------------------------------------
-- Q2. Série semanal: escalados, ABS, AF, NADH
-- ---------------------------------------------------------------------
SELECT DATE_FORMAT(DATE_SUB(a.dia, INTERVAL WEEKDAY(a.dia) DAY), '%Y-%m-%d') AS semana_seg,
       COUNT(*)                                                        AS slots_escalados,
       SUM(a.classificacao = 'ABS')                                    AS abs_,
       SUM(a.classificacao = 'AF')                                     AS af,
       SUM(a.classificacao = 'NADH')                                   AS nadh,
       ROUND(100 * SUM(a.classificacao = 'ABS') / COUNT(*), 2)         AS pct_abs,
       ROUND(100 * SUM(a.classificacao = 'AF')  / COUNT(*), 2)         AS pct_af,
       ROUND(100 * SUM(a.classificacao IN ('ABS','AF')) / COUNT(*), 2) AS pct_abs_mais_af
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
GROUP BY 1
ORDER BY 1;

-- ---------------------------------------------------------------------
-- Q3. Sensibilidade do limiar: horas_fora_escala como a procedure calcula
--     (picos em 2, 4, 6 = defeito da hora de início, ver parecer)
-- ---------------------------------------------------------------------
SELECT LEAST(a.horas_fora_escala, 12) AS horas_fora_escala,
       SUM(a.classificacao = 'ABS')   AS slots_abs,
       SUM(a.classificacao = 'AF')    AS slots_af,
       COUNT(DISTINCT a.codigo, a.dia) AS rep_dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
WHERE a.dia BETWEEN @de AND @ate
  AND a.classificacao IN ('ABS','AF')
GROUP BY 1
ORDER BY 1;

-- Q3c. Limiar com as horas fora da escala CORRIGIDAS: hora com mensagem
--      que não cai em nenhuma janela [slot_inicio, slot_inicio + 4 h) do rep,
--      dentro do dia do turno (05:00 a 05:00)
WITH sl AS (
  SELECT a.codigo, a.code_infloww COLLATE utf8mb4_unicode_ci AS code, a.slot_inicio, a.classificacao,
         ELT(FIELD(a.hora,5,9,13,17,21,1),'A','A','C','C','D','D') AS turno,
         DATE(DATE_SUB(a.slot_inicio, INTERVAL 5 HOUR)) AS td
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao IN ('AF','ABS')
),
msg AS (
  SELECT DISTINCT SUBSTRING_INDEX(t.Sender,' ',1) COLLATE utf8mb4_unicode_ci AS code,
         DATE_SUB(t.Date_Hour, INTERVAL MINUTE(t.Date_Hour) MINUTE) AS dh
  FROM `5c_wfm_db_mis`.`tab_message_interval` t
  WHERE t.Sent_Date BETWEEN DATE_SUB(@de, INTERVAL 1 DAY) AND DATE_ADD(@ate, INTERVAL 2 DAY)
    AND t.Sender NOT LIKE 'AI %'
),
h AS (
  SELECT sl.codigo, sl.slot_inicio,
         EXISTS (SELECT 1 FROM `5c_wfm_db_mis`.`abs_adh_af` e
                 WHERE e.codigo = sl.codigo
                   AND e.slot_inicio > DATE_SUB(m.dh, INTERVAL 4 HOUR) AND e.slot_inicio <= m.dh) AS in_esc
  FROM sl
  JOIN msg m ON m.code = sl.code
            AND m.dh >= DATE_ADD(TIMESTAMP(sl.td), INTERVAL 5 HOUR)
            AND m.dh <  DATE_ADD(TIMESTAMP(sl.td), INTERVAL 29 HOUR)
),
s AS (
  SELECT sl.codigo, sl.slot_inicio, sl.turno, sl.classificacao, COALESCE(SUM(h.in_esc = 0),0) AS n_fora
  FROM sl LEFT JOIN h ON h.codigo = sl.codigo AND h.slot_inicio = sl.slot_inicio
  GROUP BY sl.codigo, sl.slot_inicio, sl.turno, sl.classificacao
)
SELECT s.classificacao, s.turno, COUNT(*) AS slots,
       SUM(s.n_fora >= 2) AS fora_2h, SUM(s.n_fora >= 3) AS fora_3h, SUM(s.n_fora >= 4) AS fora_4h
FROM s
GROUP BY s.classificacao, s.turno WITH ROLLUP;

-- ---------------------------------------------------------------------
-- Q4. Por rep: AF x ABS x escalados, time/party atual e turno predominante
--     (decomposição do AF por categoria: Q10 agrupado por rep)
-- ---------------------------------------------------------------------
WITH base AS (
  SELECT a.codigo,
         MAX(COALESCE(NULLIF(a.colaborador_nome_social,''), a.colaborador_nome)) AS nome,
         COUNT(*)                                                     AS slots,
         SUM(a.classificacao = 'AF')                                  AS af,
         SUM(a.classificacao = 'ABS')                                 AS abs_,
         COUNT(DISTINCT IF(a.classificacao = 'AF', a.dia, NULL))      AS dias_af,
         SUM(a.hora IN (5,9)) AS sa, SUM(a.hora IN (13,17)) AS sc, SUM(a.hora IN (21,1)) AS sd
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate
  GROUP BY a.codigo
)
SELECT b.codigo, b.nome, e.team, e.party, e.role,
       IF(b.sa >= b.sc AND b.sa >= b.sd, 'A', IF(b.sc >= b.sd, 'C', 'D')) AS turno_predominante,
       b.slots, b.af, b.dias_af, b.abs_,
       ROUND(100 * b.af / b.slots, 1)   AS pct_af,
       ROUND(100 * b.abs_ / b.slots, 1) AS pct_abs
FROM base b
LEFT JOIN `5c_wfm_db_mis`.`vw_rep_estrutura_atual` e
  ON e.rep_code COLLATE utf8mb4_unicode_ci = b.codigo COLLATE utf8mb4_unicode_ci
WHERE b.af > 0
ORDER BY b.af DESC, b.slots DESC
LIMIT 60;

-- ---------------------------------------------------------------------
-- Q5. Padrão "escalado X, trabalhou Y" por rep-dia do turno com AF.
--     Bloco trabalhado = bloco de 4 h com >= 2 h de mensagem do rep.
-- ---------------------------------------------------------------------
WITH afd AS (
  SELECT DISTINCT a.codigo, a.code_infloww COLLATE utf8mb4_unicode_ci AS code,
         DATE(DATE_SUB(a.slot_inicio, INTERVAL 5 HOUR)) AS td
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
),
esc AS (
  SELECT d.codigo, d.td,
         GROUP_CONCAT(DISTINCT CONCAT('T', FIELD(a.hora,1,5,9,13,17,21))
                      ORDER BY FIELD(a.hora,5,9,13,17,21,1) SEPARATOR '') AS esc,
         GROUP_CONCAT(DISTINCT IF(a.classificacao IN ('AF','ABS'), CONCAT('T', FIELD(a.hora,1,5,9,13,17,21)), NULL)
                      ORDER BY FIELD(a.hora,5,9,13,17,21,1) SEPARATOR '') AS falta
  FROM afd d
  JOIN `5c_wfm_db_mis`.`abs_adh_af` a
    ON a.codigo = d.codigo
   AND a.slot_inicio >= DATE_ADD(TIMESTAMP(d.td), INTERVAL 5 HOUR)
   AND a.slot_inicio <  DATE_ADD(TIMESTAMP(d.td), INTERVAL 29 HOUR)
  GROUP BY d.codigo, d.td
),
mh AS (
  SELECT DISTINCT SUBSTRING_INDEX(t.Sender,' ',1) COLLATE utf8mb4_unicode_ci AS code,
         DATE_SUB(t.Date_Hour, INTERVAL MINUTE(t.Date_Hour) MINUTE) AS dh
  FROM `5c_wfm_db_mis`.`tab_message_interval` t
  WHERE t.Sent_Date BETWEEN DATE_SUB(@de, INTERVAL 1 DAY) AND DATE_ADD(@ate, INTERVAL 2 DAY)
    AND t.Sender NOT LIKE 'AI %'
),
blk AS (
  SELECT d.codigo, d.td, FLOOR(((HOUR(m.dh) + 23) % 24) / 4) + 1 AS b, COUNT(*) AS horas
  FROM afd d
  JOIN mh m ON m.code = d.code
           AND m.dh >= DATE_ADD(TIMESTAMP(d.td), INTERVAL 5 HOUR)
           AND m.dh <  DATE_ADD(TIMESTAMP(d.td), INTERVAL 29 HOUR)
  GROUP BY d.codigo, d.td, FLOOR(((HOUR(m.dh) + 23) % 24) / 4) + 1
),
wk AS (
  SELECT codigo, td, GROUP_CONCAT(CONCAT('T', b) ORDER BY FIELD(b,2,3,4,5,6,1) SEPARATOR '') AS trab
  FROM blk WHERE horas >= 2
  GROUP BY codigo, td
)
SELECT e.esc AS blocos_escalados, COALESCE(e.falta,'') AS blocos_com_falta,
       COALESCE(w.trab, '(sem msg)') AS blocos_trabalhados,
       COUNT(*) AS rep_dias, COUNT(DISTINCT e.codigo) AS reps
FROM esc e
LEFT JOIN wk w ON w.codigo = e.codigo AND w.td = e.td
GROUP BY 1, 2, 3
ORDER BY rep_dias DESC
LIMIT 40;

-- ---------------------------------------------------------------------
-- Q6. O slot AF ficou descoberto? Por turno (Q6b) e total (linha ROLLUP = Q6).
--   rep_mandou_msg_no_slot  = o próprio rep mandou msg dentro do slot
--   rep_em_outra_pagina     = mandou, mas só em página fora da escala (H2)
--   outro_rep_cobriu_pagina = outra pessoa mandou msg na página escalada
--   slots_sem_pagina_na_escala = só placeholder (modeloId = 3) no slot
-- ---------------------------------------------------------------------
WITH af AS (
  SELECT a.codigo, a.code_infloww COLLATE utf8mb4_unicode_ci AS code, a.slot_inicio,
         ELT(FIELD(a.hora,5,9,13,17,21,1),'A','A','C','C','D','D') AS turno
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
),
hrs AS (
  SELECT af.codigo, af.code, af.slot_inicio, DATE_ADD(af.slot_inicio, INTERVAL n.k HOUR) AS dh
  FROM af JOIN (SELECT 0 k UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) n
),
pg AS (
  SELECT DISTINCT sd.codigo COLLATE utf8mb4_unicode_ci AS codigo,
         STR_TO_DATE(LEFT(sd.shiftDateAndHour,19), '%Y-%m-%dT%H:%i:%s') AS slot_inicio,
         sd.modeloNome COLLATE utf8mb4_unicode_ci AS pagina
  FROM `5c_schedule`.`scheduleday` sd
  WHERE sd.shiftDateAndHour >= DATE_FORMAT(DATE_SUB(@de, INTERVAL 1 DAY), '%Y-%m-%d')
    AND sd.shiftDateAndHour <  DATE_FORMAT(DATE_ADD(@ate, INTERVAL 2 DAY), '%Y-%m-%d')
    AND sd.modeloId <> 3
),
tm AS (
  SELECT DISTINCT SUBSTRING_INDEX(t.Sender,' ',1) COLLATE utf8mb4_unicode_ci AS code,
         t.Creator COLLATE utf8mb4_unicode_ci AS pagina,
         DATE_SUB(t.Date_Hour, INTERVAL MINUTE(t.Date_Hour) MINUTE) AS dh
  FROM `5c_wfm_db_mis`.`tab_message_interval` t
  WHERE t.Sent_Date BETWEEN DATE_SUB(@de, INTERVAL 1 DAY) AND DATE_ADD(@ate, INTERVAL 2 DAY)
    AND t.Sender NOT LIKE 'AI %'
),
own AS (
  SELECT h.codigo, h.slot_inicio, COUNT(DISTINCT h.dh) n
  FROM hrs h JOIN tm ON tm.code = h.code AND tm.dh = h.dh
  GROUP BY h.codigo, h.slot_inicio
),
ownpg AS (
  SELECT h.codigo, h.slot_inicio, COUNT(DISTINCT h.dh) n
  FROM hrs h
  JOIN pg ON pg.codigo = h.codigo COLLATE utf8mb4_unicode_ci AND pg.slot_inicio = h.slot_inicio
  JOIN tm ON tm.code = h.code AND tm.dh = h.dh AND tm.pagina = pg.pagina
  GROUP BY h.codigo, h.slot_inicio
),
oth AS (
  SELECT h.codigo, h.slot_inicio, COUNT(DISTINCT h.dh) n
  FROM hrs h
  JOIN pg ON pg.codigo = h.codigo COLLATE utf8mb4_unicode_ci AND pg.slot_inicio = h.slot_inicio
  JOIN tm ON tm.pagina = pg.pagina AND tm.dh = h.dh AND tm.code <> h.code
  GROUP BY h.codigo, h.slot_inicio
),
npg AS (
  SELECT af.codigo, af.slot_inicio, COUNT(pg.pagina) n
  FROM af LEFT JOIN pg ON pg.codigo = af.codigo COLLATE utf8mb4_unicode_ci AND pg.slot_inicio = af.slot_inicio
  GROUP BY af.codigo, af.slot_inicio
)
SELECT af.turno,
       COUNT(*)                                                    AS slots_af,
       SUM(COALESCE(o.n,0) > 0)                                    AS rep_mandou_msg_no_slot,
       SUM(LEAST(COALESCE(o.n,0),4))                               AS horas_rep_no_slot,
       SUM(COALESCE(op.n,0) > 0)                                   AS rep_na_pagina_escalada,
       SUM(COALESCE(o.n,0) > 0 AND COALESCE(op.n,0) = 0)           AS rep_em_outra_pagina,
       SUM(COALESCE(x.n,0) > 0)                                    AS outro_rep_cobriu_pagina,
       SUM(COALESCE(x.n,0) >= 4)                                   AS outro_cobriu_4h,
       SUM(COALESCE(o.n,0) = 0 AND COALESCE(x.n,0) = 0)            AS descoberto_de_fato,
       ROUND(SUM(COALESCE(o.n,0) = 0 AND COALESCE(x.n,0) = 0) / COUNT(*), 3) AS peso_sugerido_q6,
       SUM(np.n = 0)                                               AS slots_sem_pagina_na_escala,
       SUM(np.n > 0 AND COALESCE(o.n,0) = 0 AND COALESCE(x.n,0) = 0) AS com_pagina_descoberta
FROM af
LEFT JOIN own   o  ON o.codigo  = af.codigo AND o.slot_inicio  = af.slot_inicio
LEFT JOIN ownpg op ON op.codigo = af.codigo AND op.slot_inicio = af.slot_inicio
LEFT JOIN oth   x  ON x.codigo  = af.codigo AND x.slot_inicio  = af.slot_inicio
JOIN npg np        ON np.codigo = af.codigo AND np.slot_inicio = af.slot_inicio
GROUP BY af.turno WITH ROLLUP;

-- ---------------------------------------------------------------------
-- Q7. Sinal de mudança tardia na escala (H3)
--   updated_at / imported_at medem o ETL (regrava a linha ao marcar clockIn
--   e faltou), não a publicação; servem só para comparar AF com ADH/ABS.
-- ---------------------------------------------------------------------
SELECT z.classificacao, z.momento_alteracao, COUNT(*) AS slots
FROM (
  SELECT a.classificacao,
         IF(MAX(sd.updated_at) IS NULL,
            IF(COUNT(sd.id) = 0, '0 sem linha na escala', '1 nunca alterada'),
            IF(MAX(sd.updated_at) < DATE_SUB(a.slot_inicio, INTERVAL 72 HOUR), '2 estavel (> 3 dias antes)',
               IF(MAX(sd.updated_at) < DATE_SUB(a.slot_inicio, INTERVAL 24 HOUR), '3 alterada 1-3 dias antes',
                  IF(MAX(sd.updated_at) < a.slot_inicio, '4 alterada < 24h antes', '5 alterada depois do inicio')))) AS momento_alteracao
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  LEFT JOIN `5c_schedule`.`scheduleday` sd
    ON sd.codigo = a.codigo COLLATE utf8mb4_unicode_ci
   AND sd.shiftDateAndHour = DATE_FORMAT(a.slot_inicio, '%Y-%m-%dT%H:%i:%s')
  WHERE a.dia BETWEEN @de AND @ate
  GROUP BY a.codigo, a.slot_inicio, a.classificacao
) z
GROUP BY 1, 2
ORDER BY 1, 2;

-- Q7c. Turnos removidos na origem (sync_removed_rows, dados desde 03/10)
WITH r AS (
  SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.codigo')) COLLATE utf8mb4_unicode_ci AS codigo,
         LEFT(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.shiftDateAndHour')),10) COLLATE utf8mb4_unicode_ci AS dia,
         JSON_EXTRACT(row_json, '$.modeloId') = 3 AS placeholder
  FROM `5c_schedule`.`sync_removed_rows`
  WHERE table_name = 'scheduleday'
),
rd AS (SELECT codigo, dia, MIN(placeholder) AS so_placeholder FROM r GROUP BY codigo, dia)
SELECT a.classificacao, COUNT(*) AS slots,
       SUM(rd.codigo IS NOT NULL) AS slots_com_turno_removido_no_dia,
       SUM(rd.codigo IS NOT NULL AND rd.so_placeholder = 0) AS com_remocao_de_pagina_real,
       ROUND(100 * SUM(rd.codigo IS NOT NULL) / COUNT(*), 1) AS pct
FROM `5c_wfm_db_mis`.`abs_adh_af` a
LEFT JOIN rd ON rd.codigo = a.codigo COLLATE utf8mb4_unicode_ci AND rd.dia = DATE_FORMAT(a.dia, '%Y-%m-%d')
WHERE a.dia BETWEEN GREATEST(@de, '2026-10-03') AND @ate
GROUP BY a.classificacao
ORDER BY a.classificacao;

-- ---------------------------------------------------------------------
-- Q8. Recorrência por página escalada e por time (time/party do vw_pagina;
--     scheduleday.teamName vem vazio). Slot com mais de uma página conta
--     em cada uma.
-- ---------------------------------------------------------------------
SELECT COALESCE(vp.teamName, '(sem time)') AS time_,
       COALESCE(sd.modeloNome, '(só placeholder 5C Schedule)') AS pagina,
       COUNT(DISTINCT a.codigo, a.slot_inicio) AS slots_af,
       COUNT(DISTINCT a.codigo)                AS reps,
       COUNT(DISTINCT a.dia)                   AS dias
FROM `5c_wfm_db_mis`.`abs_adh_af` a
LEFT JOIN `5c_schedule`.`scheduleday` sd
  ON sd.codigo = a.codigo COLLATE utf8mb4_unicode_ci
 AND sd.shiftDateAndHour = DATE_FORMAT(a.slot_inicio, '%Y-%m-%dT%H:%i:%s')
 AND sd.modeloId <> 3
LEFT JOIN (SELECT name COLLATE utf8mb4_unicode_ci AS name, MAX(teamName) AS teamName
           FROM `5c_bot`.`vw_pagina` GROUP BY name) vp
  ON vp.name = sd.modeloNome COLLATE utf8mb4_unicode_ci
WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
GROUP BY 1, 2
ORDER BY slots_af DESC
LIMIT 40;

-- ---------------------------------------------------------------------
-- Q9. Persistência: reps com AF em dias seguidos
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
SELECT codigo, DATE_FORMAT(MIN(dia),'%Y-%m-%d') AS inicio, DATE_FORMAT(MAX(dia),'%Y-%m-%d') AS fim,
       COUNT(*) AS dias_seguidos_af
FROM seq
GROUP BY codigo, grp
HAVING COUNT(*) >= 3
ORDER BY dias_seguidos_af DESC, codigo
LIMIT 40;

-- ---------------------------------------------------------------------
-- Q10. Categoria de cada slot AF (partição; ordem de prioridade):
--   1 AF real ............ >= 3 h de msg fora de qualquer janela escalada
--   2 trabalhou no slot .. o próprio rep mandou msg dentro do slot
--   3 escala dobrada ..... escalado em 2+ turnos no dia, trabalhou o outro
--   4 falta parcial ...... trabalhou outro slot do mesmo turno
--   5 falta do turno ..... nada no dia do turno
-- Q10b. Peso por turno: AF real = 0; trabalhou no slot = 1 − horas/4;
--   dobra = 0 (cenário recomendado) ou 1 (cenário conservador); 4 e 5 = 1.
-- ---------------------------------------------------------------------
WITH sl AS (
  SELECT a.codigo, a.code_infloww COLLATE utf8mb4_unicode_ci AS code, a.slot_inicio,
         ELT(FIELD(a.hora,5,9,13,17,21,1),'A','A','C','C','D','D') AS turno,
         DATE(DATE_SUB(a.slot_inicio, INTERVAL 5 HOUR)) AS td
  FROM `5c_wfm_db_mis`.`abs_adh_af` a
  WHERE a.dia BETWEEN @de AND @ate AND a.classificacao = 'AF'
),
tt AS (
  SELECT d.codigo, d.td,
         COUNT(DISTINCT ELT(FIELD(a.hora,5,9,13,17,21,1),'A','A','C','C','D','D')) AS n_turnos
  FROM (SELECT DISTINCT codigo, td FROM sl) d
  JOIN `5c_wfm_db_mis`.`abs_adh_af` a
    ON a.codigo = d.codigo
   AND a.slot_inicio >= DATE_ADD(TIMESTAMP(d.td), INTERVAL 5 HOUR)
   AND a.slot_inicio <  DATE_ADD(TIMESTAMP(d.td), INTERVAL 29 HOUR)
  GROUP BY d.codigo, d.td
),
msg AS (
  SELECT DISTINCT SUBSTRING_INDEX(t.Sender,' ',1) COLLATE utf8mb4_unicode_ci AS code,
         DATE_SUB(t.Date_Hour, INTERVAL MINUTE(t.Date_Hour) MINUTE) AS dh
  FROM `5c_wfm_db_mis`.`tab_message_interval` t
  WHERE t.Sent_Date BETWEEN DATE_SUB(@de, INTERVAL 1 DAY) AND DATE_ADD(@ate, INTERVAL 2 DAY)
    AND t.Sender NOT LIKE 'AI %'
),
h AS (
  SELECT sl.codigo, sl.slot_inicio,
         (m.dh >= sl.slot_inicio AND m.dh < DATE_ADD(sl.slot_inicio, INTERVAL 4 HOUR)) AS in_slot,
         EXISTS (SELECT 1 FROM `5c_wfm_db_mis`.`abs_adh_af` e
                 WHERE e.codigo = sl.codigo
                   AND e.slot_inicio > DATE_SUB(m.dh, INTERVAL 4 HOUR) AND e.slot_inicio <= m.dh) AS in_esc,
         EXISTS (SELECT 1 FROM `5c_wfm_db_mis`.`abs_adh_af` e
                 WHERE e.codigo = sl.codigo
                   AND e.slot_inicio > DATE_SUB(m.dh, INTERVAL 4 HOUR) AND e.slot_inicio <= m.dh
                   AND ELT(FIELD(e.hora,5,9,13,17,21,1),'A','A','C','C','D','D') <> sl.turno) AS in_outro_turno
  FROM sl
  JOIN msg m ON m.code = sl.code
            AND m.dh >= DATE_ADD(TIMESTAMP(sl.td), INTERVAL 5 HOUR)
            AND m.dh <  DATE_ADD(TIMESTAMP(sl.td), INTERVAL 29 HOUR)
),
s AS (
  SELECT sl.codigo, sl.slot_inicio, sl.turno, MAX(tt.n_turnos) AS n_turnos,
         COALESCE(SUM(h.in_slot),0)         AS n_slot,
         COALESCE(SUM(h.in_esc = 0),0)      AS n_fora,
         COALESCE(SUM(h.in_outro_turno),0)  AS n_outro_turno,
         COALESCE(SUM(h.in_esc = 1 AND h.in_slot = 0),0) AS n_outro_esc
  FROM sl
  JOIN tt ON tt.codigo = sl.codigo AND tt.td = sl.td
  LEFT JOIN h ON h.codigo = sl.codigo AND h.slot_inicio = sl.slot_inicio
  GROUP BY sl.codigo, sl.slot_inicio, sl.turno
),
c AS (
  SELECT s.*,
         IF(s.n_fora >= 3, '1 AF real (>=3h fora da escala)',
         IF(s.n_slot > 0, '2 trabalhou no proprio slot',
         IF(s.n_turnos > 1 AND s.n_outro_turno >= 2, '3 escala dobrada: trabalhou o outro turno',
         IF(s.n_outro_esc > 0, '4 falta parcial no proprio turno', '5 falta do turno inteiro')))) AS cat,
         IF(s.n_fora >= 3, 0, IF(s.n_slot > 0, 1 - LEAST(s.n_slot,4)/4,
            IF(s.n_turnos > 1 AND s.n_outro_turno >= 2, 0, 1))) AS w_dobra0,
         IF(s.n_fora >= 3, 0, IF(s.n_slot > 0, 1 - LEAST(s.n_slot,4)/4, 1)) AS w_dobra1
  FROM s
)
SELECT c.cat AS categoria,
       SUM(c.turno='A') AS a, SUM(c.turno='C') AS c_, SUM(c.turno='D') AS d,
       COUNT(*) AS total, ROUND(AVG(c.n_slot),2) AS horas_medias_no_slot
FROM c
GROUP BY c.cat
ORDER BY c.cat;
-- Q10b: trocar o SELECT final acima por:
-- SELECT COALESCE(c.turno,'GLOBAL') AS turno, COUNT(*) AS slots_af,
--        ROUND(SUM(c.w_dobra0),1) AS ausencia_equiv_dobra0, ROUND(AVG(c.w_dobra0),3) AS peso_dobra0,
--        ROUND(SUM(c.w_dobra1),1) AS ausencia_equiv_dobra1, ROUND(AVG(c.w_dobra1),3) AS peso_dobra1
-- FROM c GROUP BY c.turno WITH ROLLUP;
