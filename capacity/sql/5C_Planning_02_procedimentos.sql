-- =====================================================================
-- 5C | Forecast de WFM — 02. Procedimentos e evento diário
-- Schema: 5c_planning        Versão 1.0 — 19/10/2026
-- No Navicat: abrir em "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

DELIMITER $$

-- ---------------------------------------------------------------------
-- 1. Carga de fãs distintos por página e hora (msgdashboard → fc_fans_pagina_hora)
--    p_ini/p_fim = dias do turno (cada dia vai de 05:00 até 04:59 do dia seguinte).
--    ~3 a 5 s por dia. Para recarga histórica, chamar em blocos de 7 dias.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_carga_fans $$
CREATE PROCEDURE sp_fc_carga_fans(IN p_ini DATE, IN p_fim DATE)
BEGIN
  DECLARE v_d DATE DEFAULT p_ini;
  WHILE v_d <= p_fim DO
    DELETE FROM fc_fans_pagina_hora WHERE dia_turno = v_d;
    INSERT INTO fc_fans_pagina_hora (dia_turno, hora, pagina, bloco, turno, fans, reps)
    SELECT v_d, x.h, x.p,
           CASE WHEN x.h BETWEEN 5 AND 8 THEN 'T2' WHEN x.h BETWEEN 9 AND 12 THEN 'T3'
                WHEN x.h BETWEEN 13 AND 16 THEN 'T4' WHEN x.h BETWEEN 17 AND 20 THEN 'T5'
                WHEN x.h >= 21 OR x.h = 0 THEN 'T6' ELSE 'T1' END,
           CASE WHEN x.h BETWEEN 5 AND 12 THEN 'A' WHEN x.h BETWEEN 13 AND 20 THEN 'C' ELSE 'D' END,
           x.f, x.r
    FROM (SELECT HOUR(m.DATE_TIME) AS h, m.CREATOR AS p,
                 COUNT(DISTINCT m.SENT_TO) AS f, COUNT(DISTINCT m.SENDER) AS r
          FROM `5c_wfm_db`.msgdashboard m
          WHERE m.DATE_TIME >= TIMESTAMP(v_d) + INTERVAL 5 HOUR
            AND m.DATE_TIME <  TIMESTAMP(v_d) + INTERVAL 29 HOUR
            AND m.SOURCE = 'Employee' AND m.SENT_TO <> '-' AND m.CREATOR IS NOT NULL
          GROUP BY HOUR(m.DATE_TIME), m.CREATOR) x;
    SET v_d = v_d + INTERVAL 1 DAY;
  END WHILE;
END $$

-- ---------------------------------------------------------------------
-- 2. Tabelas 1 e 2: HC semanal e turnover semanal (reconstrução completa, ~5 s)
-- ---------------------------------------------------------------------
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
                 STR_TO_DATE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(row_json,'$.startContract'))),'%d/%m/%Y') AS adm
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

-- ---------------------------------------------------------------------
-- 3. Tabela 3: previsão de turnover por semana (com histórico por data_calculo)
--    prev_to = HC projetado × taxa histórica do turno + saídas extras de novatos das turmas em fc_contratacoes
--    HC projetado(semana+1) = HC projetado − prev_to + entradas da semana
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_prev_to $$
CREATE PROCEDURE sp_fc_prev_to(IN p_data DATE)
BEGIN
  DECLARE v_jan INT DEFAULT 12;
  DECLARE v_h INT DEFAULT 26;
  DECLARE v_n14 DECIMAL(8,5) DEFAULT 0.03;
  DECLARE v_n513 DECIMAL(8,5) DEFAULT 0.0122;
  DECLARE v_seg DATE;
  DECLARE v_ws DATE;
  DECLARE v_k INT DEFAULT 0;
  DECLARE v_ta, v_tc, v_td DECIMAL(8,5) DEFAULT 0;
  DECLARE v_ha, v_hc, v_hd DECIMAL(8,2) DEFAULT 0;
  DECLARE v_pa, v_pc, v_pd DECIMAL(8,3) DEFAULT 0;
  DECLARE v_xa, v_xc, v_xd DECIMAL(8,3) DEFAULT 0;
  DECLARE v_ea, v_ec, v_ed INT DEFAULT 0;

  SELECT CAST(valor AS SIGNED) INTO v_jan  FROM fc_parametros WHERE chave = 'janela_to_semanas';
  SELECT CAST(valor AS SIGNED) INTO v_h    FROM fc_parametros WHERE chave = 'horizonte_semanas';
  SELECT valor INTO v_n14  FROM fc_parametros WHERE chave = 'novato_sem_1_4';
  SELECT valor INTO v_n513 FROM fc_parametros WHERE chave = 'novato_sem_5_13';
  SET v_seg = p_data - INTERVAL WEEKDAY(p_data) DAY;

  SELECT COALESCE(SUM(t.to_a)/NULLIF(SUM(h.hc_a),0),0),
         COALESCE(SUM(t.to_c)/NULLIF(SUM(h.hc_c),0),0),
         COALESCE(SUM(t.to_d)/NULLIF(SUM(h.hc_d),0),0)
    INTO v_ta, v_tc, v_td
  FROM fc_hc_semanal h
  JOIN fc_to_semanal t ON t.ano_iso = h.ano_iso AND t.semana_iso = h.semana_iso
  WHERE h.inicio_semana >= v_seg - INTERVAL (v_jan*7) DAY AND h.inicio_semana < v_seg;

  SELECT COALESCE(MAX(hc_a),0), COALESCE(MAX(hc_c),0), COALESCE(MAX(hc_d),0)
    INTO v_ha, v_hc, v_hd
  FROM fc_hc_semanal WHERE inicio_semana = v_seg - INTERVAL 7 DAY;

  DELETE FROM fc_prev_to_semanal WHERE data_calculo = p_data;

  WHILE v_k < v_h DO
    SET v_ws = v_seg + INTERVAL (v_k*7) DAY;

    SELECT COALESCE(SUM(CASE WHEN turno='A' THEN qtd END),0),
           COALESCE(SUM(CASE WHEN turno='C' THEN qtd END),0),
           COALESCE(SUM(CASE WHEN turno='D' THEN qtd END),0)
      INTO v_ea, v_ec, v_ed
    FROM fc_contratacoes
    WHERE status <> 'CANCELADA'
      AND data_inicio_operacao >= v_ws AND data_inicio_operacao < v_ws + INTERVAL 7 DAY;

    SELECT COALESCE(SUM(CASE WHEN turno='A' THEN qtd * GREATEST(IF(sem<=4, v_n14, v_n513) - v_ta, 0) END),0),
           COALESCE(SUM(CASE WHEN turno='C' THEN qtd * GREATEST(IF(sem<=4, v_n14, v_n513) - v_tc, 0) END),0),
           COALESCE(SUM(CASE WHEN turno='D' THEN qtd * GREATEST(IF(sem<=4, v_n14, v_n513) - v_td, 0) END),0)
      INTO v_xa, v_xc, v_xd
    FROM (SELECT turno, qtd, FLOOR(DATEDIFF(v_ws, data_inicio_operacao)/7) + 1 AS sem
          FROM fc_contratacoes
          WHERE status <> 'CANCELADA' AND data_inicio_operacao < v_ws) z
    WHERE sem BETWEEN 1 AND 13;

    SET v_pa = v_ha * v_ta + v_xa;
    SET v_pc = v_hc * v_tc + v_xc;
    SET v_pd = v_hd * v_td + v_xd;

    INSERT INTO fc_prev_to_semanal (data_calculo, ano_iso, semana_iso, mes, ano, inicio_semana,
         prev_to_a, prev_to_c, prev_to_d, prev_to_total, hc_proj_a, hc_proj_c, hc_proj_d,
         entradas_a, entradas_c, entradas_d, taxa_a, taxa_c, taxa_d)
    SELECT p_data, c.ano_iso, c.semana_iso, c.mes_semana, c.ano_iso, v_ws,
           v_pa, v_pc, v_pd, v_pa + v_pc + v_pd, v_ha, v_hc, v_hd,
           v_ea, v_ec, v_ed, v_ta, v_tc, v_td
    FROM fc_calendario c WHERE c.dia = v_ws;

    SET v_ha = GREATEST(v_ha - v_pa + v_ea, 0);
    SET v_hc = GREATEST(v_hc - v_pc + v_ec, 0);
    SET v_hd = GREATEST(v_hd - v_pd + v_ed, 0);
    SET v_k = v_k + 1;
  END WHILE;
END $$

-- ---------------------------------------------------------------------
-- 4. Tabela 4: pessoas a escalar por dia, sem buffer (com histórico por data_calculo)
--    Regra (decisão de 19/10/2026):
--      reps da página no bloco de 4 h = arredondar para cima (fãs/h na hora mais cheia ÷ limite)
--      limite: SKYLAR 40 (mínimo 4) · SLA80 20 · PADRAO 30
--      página PADRAO com até 24 fãs/h divide rep: até 3 da mesma modelo ou 2 diferentes,
--      com a soma dentro de 24 (Política 5.3.1, C(n) com C₁ = 30)
--      pessoas do turno no dia = maior bloco do turno (regra_bloco = 1) ou média dos 2 blocos
--    Fãs/h: média das últimas 4 ocorrências do mesmo dia da semana (janela_perfil_dias).
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_escala $$
CREATE PROCEDURE sp_fc_escala(IN p_data DATE)
BEGIN
  DECLARE v_lp, v_ls, v_lk, v_kmin, v_comp, v_def DECIMAL(10,4);
  DECLARE v_vol DECIMAL(10,4) DEFAULT 1;
  DECLARE v_grp DECIMAL(10,4) DEFAULT 23;
  DECLARE v_maxp, v_pico INT DEFAULT 3;
  DECLARE v_jan, v_h, v_regra INT;
  SELECT valor INTO v_lp   FROM fc_parametros WHERE chave = 'limite_padrao';
  SELECT valor INTO v_ls   FROM fc_parametros WHERE chave = 'limite_sla80';
  SELECT valor INTO v_lk   FROM fc_parametros WHERE chave = 'limite_skylar';
  SELECT valor INTO v_kmin FROM fc_parametros WHERE chave = 'skylar_minimo';
  SELECT valor INTO v_comp FROM fc_parametros WHERE chave = 'limite_compartilhada';
  SELECT valor INTO v_def  FROM fc_parametros WHERE chave = 'fans_sem_dado';
  SELECT CAST(valor AS SIGNED) INTO v_jan   FROM fc_parametros WHERE chave = 'janela_perfil_dias';
  SELECT CAST(valor AS SIGNED) INTO v_h     FROM fc_parametros WHERE chave = 'horizonte_dias';
  SELECT CAST(valor AS SIGNED) INTO v_regra FROM fc_parametros WHERE chave = 'regra_bloco';
  SELECT COALESCE(MAX(valor),1) INTO v_vol FROM fc_parametros WHERE chave = 'fator_volume';
  SELECT COALESCE(MAX(valor),23) INTO v_grp FROM fc_parametros WHERE chave = 'capacidade_grupo';
  SELECT COALESCE(MAX(CAST(valor AS SIGNED)),3) INTO v_maxp FROM fc_parametros WHERE chave = 'max_paginas_rep';
  SELECT COALESCE(MAX(CAST(valor AS SIGNED)),0) INTO v_pico FROM fc_parametros WHERE chave = 'perfil_pico';

  -- páginas novas que apareceram na escala entram no cadastro do forecast como PADRAO
  INSERT IGNORE INTO fc_pagina_modelo (pagina, modelo)
  SELECT DISTINCT modeloNome, modeloNome FROM `5c_schedule`.scheduleday
  WHERE modeloId <> 3 AND shiftDateAndHour >= DATE_FORMAT(p_data - INTERVAL 7 DAY, '%Y-%m-%dT05');

  -- perfil: fãs/h médios por página, dia da semana e hora → média do bloco de 4 h (perfil_pico = 1: hora mais cheia)
  TRUNCATE TABLE fc_wrk_perfil;
  INSERT INTO fc_wrk_perfil (pagina, dow, bloco, fans_pico)
  SELECT z.pagina, z.dow, z.bloco, IF(v_pico = 1, MAX(z.media), SUM(z.media) / 4)
  FROM (SELECT f.pagina, c.dia_semana AS dow, f.bloco, f.hora, SUM(f.fans) / (v_jan/7) AS media
        FROM fc_fans_pagina_hora f
        JOIN fc_calendario c ON c.dia = f.dia_turno
        WHERE f.dia_turno >= p_data - INTERVAL v_jan DAY AND f.dia_turno < p_data
        GROUP BY f.pagina, c.dia_semana, f.bloco, f.hora) z
  GROUP BY z.pagina, z.dow, z.bloco;

  -- formato do dia (para páginas novas sem histórico)
  TRUNCATE TABLE fc_wrk_fator;
  INSERT INTO fc_wrk_fator (dow, bloco, fator)
  SELECT dow, bloco, s / NULLIF(AVG(s) OVER (PARTITION BY dow),0)
  FROM (SELECT dow, bloco, SUM(fans_pico) AS s FROM fc_wrk_perfil GROUP BY dow, bloco) z;

  -- cobertura: páginas escaladas nos últimos 7 dias, nos blocos escalados ou com fãs
  TRUNCATE TABLE fc_wrk_cobertura;
  INSERT IGNORE INTO fc_wrk_cobertura (pagina, bloco)
  SELECT DISTINCT s.modeloNome,
         CASE SUBSTRING(s.shiftDateAndHour,12,2) WHEN '05' THEN 'T2' WHEN '09' THEN 'T3' WHEN '13' THEN 'T4'
              WHEN '17' THEN 'T5' WHEN '21' THEN 'T6' ELSE 'T1' END
  FROM `5c_schedule`.scheduleday s
  WHERE s.modeloId <> 3
    AND s.shiftDateAndHour >= DATE_FORMAT(p_data - INTERVAL 7 DAY, '%Y-%m-%dT05')
    AND s.shiftDateAndHour <  DATE_FORMAT(p_data, '%Y-%m-%dT05');
  INSERT IGNORE INTO fc_wrk_cobertura (pagina, bloco)
  SELECT DISTINCT p.pagina, p.bloco FROM fc_wrk_perfil p
  WHERE p.fans_pico >= 1 AND p.pagina IN (SELECT DISTINCT pagina FROM fc_wrk_cobertura);

  -- carga por dia × bloco × página
  TRUNCATE TABLE fc_wrk_carga;
  INSERT INTO fc_wrk_carga (dia, bloco, pagina, modelo, categoria, fans, origem)
  SELECT c.dia, cb.bloco, cb.pagina, COALESCE(m.modelo, cb.pagina), COALESCE(m.categoria, 'PADRAO'),
         CASE WHEN pp.pagina IS NULL THEN v_def ELSE GREATEST(COALESCE(p.fans_pico,0) * v_vol, 1) END,
         'HISTORICO'
  FROM fc_calendario c
  CROSS JOIN fc_wrk_cobertura cb
  LEFT JOIN fc_pagina_modelo m ON m.pagina = cb.pagina
  LEFT JOIN fc_wrk_perfil p ON p.pagina = cb.pagina AND p.bloco = cb.bloco AND p.dow = c.dia_semana
  LEFT JOIN (SELECT DISTINCT pagina FROM fc_wrk_perfil) pp ON pp.pagina = cb.pagina
  WHERE c.dia >= p_data AND c.dia < p_data + INTERVAL v_h DAY
    AND COALESCE(m.ativo_forecast, 1) = 1
    AND NOT EXISTS (SELECT 1 FROM fc_pagina_eventos e
                    WHERE e.pagina = cb.pagina AND e.tipo = 'SAIDA'
                      AND e.status IN ('CONFIRMADA','EFETIVADA') AND e.data_evento <= c.dia);

  -- páginas novas confirmadas (sem histórico): fãs/h informados ou receita ÷ 720 × k do tier
  INSERT IGNORE INTO fc_wrk_carga (dia, bloco, pagina, modelo, categoria, fans, origem)
  SELECT c.dia, f.bloco, n.pagina, COALESCE(n.modelo, n.pagina), n.categoria,
         GREATEST(1, COALESCE(n.fas_h_prev, n.receita_mensal_prev / 720 * k.valor, v_def) * COALESCE(f.fator, 1) * v_vol),
         'NOVA'
  FROM fc_paginas_previstas n
  JOIN fc_calendario c ON c.dia >= GREATEST(n.data_prevista, p_data) AND c.dia < p_data + INTERVAL v_h DAY
  JOIN fc_wrk_fator f ON f.dow = c.dia_semana
  LEFT JOIN fc_parametros k ON k.chave = CONCAT('k_', n.tier)
  WHERE n.status IN ('CONFIRMADA','EFETIVADA');

  -- reps dedicados (0 = página pequena, divide rep)
  UPDATE fc_wrk_carga SET reps_ded = CASE
      WHEN categoria = 'SKYLAR' THEN GREATEST(v_kmin, CEIL(fans / v_lk))
      WHEN categoria = 'SLA80'  THEN CEIL(fans / v_ls)
      WHEN fans > v_comp        THEN CEIL(fans / v_lp)
      ELSE 0 END;

  -- reps por bloco = dedicados + páginas pequenas agrupadas (até max_paginas_rep por rep)
  --   grupos = maior entre (páginas pequenas ÷ 3) e (fãs das páginas pequenas ÷ capacidade_grupo)
  --   capacidade_grupo = 23: média de um grupo de até 3 páginas pela Política 5.3.1 com C₁ = 35
  --   (35 × 0,9 = 31,5 · 35 × 0,8 = 28 · 35 × 0,6 = 21), calibrada na alocação exata de 19/10
  TRUNCATE TABLE fc_wrk_bloco;
  INSERT INTO fc_wrk_bloco (dia, bloco, reps)
  SELECT dia, bloco,
         SUM(reps_ded) + GREATEST(CEIL(SUM(reps_ded = 0) / v_maxp), CEIL(SUM(IF(reps_ded = 0, fans, 0)) / v_grp))
  FROM fc_wrk_carga GROUP BY dia, bloco;

  DELETE FROM fc_escala_diaria WHERE data_calculo = p_data;
  INSERT INTO fc_escala_diaria (data_calculo, dia, a, c, d, total, paginas)
  SELECT p_data, z.dia, z.a, z.c, z.d, z.a + z.c + z.d,
         (SELECT COUNT(DISTINCT w.pagina) FROM fc_wrk_carga w WHERE w.dia = z.dia)
  FROM (SELECT dia,
          IF(v_regra = 1, GREATEST(MAX(IF(bloco='T2',reps,0)), MAX(IF(bloco='T3',reps,0))),
                          CEIL((MAX(IF(bloco='T2',reps,0)) + MAX(IF(bloco='T3',reps,0)))/2)) AS a,
          IF(v_regra = 1, GREATEST(MAX(IF(bloco='T4',reps,0)), MAX(IF(bloco='T5',reps,0))),
                          CEIL((MAX(IF(bloco='T4',reps,0)) + MAX(IF(bloco='T5',reps,0)))/2)) AS c,
          IF(v_regra = 1, GREATEST(MAX(IF(bloco='T6',reps,0)), MAX(IF(bloco='T1',reps,0))),
                          CEIL((MAX(IF(bloco='T6',reps,0)) + MAX(IF(bloco='T1',reps,0)))/2)) AS d
        FROM fc_wrk_bloco GROUP BY dia) z;

  -- detalhe dos 7 primeiros dias (auditoria) e limpeza de 90 dias
  DELETE FROM fc_escala_pagina WHERE data_calculo = p_data OR data_calculo < p_data - INTERVAL 90 DAY;
  INSERT INTO fc_escala_pagina (data_calculo, dia, bloco, pagina, modelo, categoria, fans_pico, reps_dedicados, origem)
  SELECT p_data, dia, bloco, pagina, modelo, categoria, fans, reps_ded, origem
  FROM fc_wrk_carga WHERE dia < p_data + INTERVAL 7 DAY;
END $$

-- ---------------------------------------------------------------------
-- 5. Tabela 5: buffers por dia (com histórico por data_calculo)
--    absenteísmo: escala × taxa/(1 − taxa), taxa = (ABS + AF) ÷ turnos escalados, por turno e dia da semana
--    desligamento: saídas previstas na semana × prazo de reposição ÷ 7 × presença no 3x1
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_buffer $$
CREATE PROCEDURE sp_fc_buffer(IN p_data DATE)
BEGIN
  DECLARE v_jan INT DEFAULT 56;
  DECLARE v_prazo, v_pres DECIMAL(10,4);
  DECLARE v_ga, v_gc, v_gd DECIMAL(8,5) DEFAULT 0;
  DECLARE v_paf DECIMAL(6,4) DEFAULT 1;
  SELECT CAST(valor AS SIGNED) INTO v_jan FROM fc_parametros WHERE chave = 'janela_abs_dias';
  SELECT COALESCE(MAX(valor),1) INTO v_paf FROM fc_parametros WHERE chave = 'peso_falha_adm';
  SELECT valor INTO v_prazo FROM fc_parametros WHERE chave = 'prazo_reposicao_dias';
  SELECT valor INTO v_pres  FROM fc_parametros WHERE chave = 'presenca_3x1';

  -- taxa geral do turno (fallback)
  SELECT COALESCE(SUM(IF(tu='A', f, 0))/NULLIF(SUM(tu='A'),0),0),
         COALESCE(SUM(IF(tu='C', f, 0))/NULLIF(SUM(tu='C'),0),0),
         COALESCE(SUM(IF(tu='D', f, 0))/NULLIF(SUM(tu='D'),0),0)
    INTO v_ga, v_gc, v_gd
  FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
               (a.classificacao = 'ABS') + (a.classificacao = 'AF') * v_paf AS f,
               IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
        FROM `5c_wfm_db_mis`.abs_adh_af a
        WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
  WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data;

  -- taxa por turno e dia da semana (usa a geral quando há menos de 100 turnos na célula)
  TRUNCATE TABLE fc_wrk_abs_taxa;
  INSERT INTO fc_wrk_abs_taxa (dow, ta, tc, td)
  SELECT c.dia_semana,
         IF(SUM(tu='A') >= 100, SUM(IF(tu='A', f, 0))/SUM(tu='A'), v_ga),
         IF(SUM(tu='C') >= 100, SUM(IF(tu='C', f, 0))/SUM(tu='C'), v_gc),
         IF(SUM(tu='D') >= 100, SUM(IF(tu='D', f, 0))/SUM(tu='D'), v_gd)
  FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
               (a.classificacao = 'ABS') + (a.classificacao = 'AF') * v_paf AS f,
               IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
        FROM `5c_wfm_db_mis`.abs_adh_af a
        WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
  JOIN fc_calendario c ON c.dia = z.td
  WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data
  GROUP BY c.dia_semana;

  DELETE FROM fc_buffer_diario WHERE data_calculo = p_data;
  INSERT INTO fc_buffer_diario (data_calculo, dia, a, c, d, total, abs_a, abs_c, abs_d, to_a, to_c, to_d,
                                taxa_abs_a, taxa_abs_c, taxa_abs_d)
  SELECT p_data, y.dia, y.abs_a + y.to_a, y.abs_c + y.to_c, y.abs_d + y.to_d,
         y.abs_a + y.to_a + y.abs_c + y.to_c + y.abs_d + y.to_d,
         y.abs_a, y.abs_c, y.abs_d, y.to_a, y.to_c, y.to_d, y.ra, y.rc, y.rd
  FROM (SELECT e.dia,
               CEIL(e.a * x.ra / (1 - x.ra)) AS abs_a,
               CEIL(e.c * x.rc / (1 - x.rc)) AS abs_c,
               CEIL(e.d * x.rd / (1 - x.rd)) AS abs_d,
               CEIL(COALESCE(pt.prev_to_a,0) * v_prazo / 7 * v_pres) AS to_a,
               CEIL(COALESCE(pt.prev_to_c,0) * v_prazo / 7 * v_pres) AS to_c,
               CEIL(COALESCE(pt.prev_to_d,0) * v_prazo / 7 * v_pres) AS to_d,
               x.ra, x.rc, x.rd
        FROM fc_escala_diaria e
        JOIN fc_calendario c ON c.dia = e.dia
        JOIN (SELECT k.dia_semana AS dow, COALESCE(t.ta, v_ga) AS ra, COALESCE(t.tc, v_gc) AS rc, COALESCE(t.td, v_gd) AS rd
              FROM (SELECT DISTINCT dia_semana FROM fc_calendario) k
              LEFT JOIN fc_wrk_abs_taxa t ON t.dow = k.dia_semana) x ON x.dow = c.dia_semana
        LEFT JOIN fc_prev_to_semanal pt ON pt.data_calculo = p_data
                                       AND pt.ano_iso = c.ano_iso AND pt.semana_iso = c.semana_iso
        WHERE e.data_calculo = p_data) y;
END $$

-- ---------------------------------------------------------------------
-- 6. Páginas: foto diária do cadastro, saídas automáticas e impacto de entradas/saídas no HC
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_paginas_eventos $$
CREATE PROCEDURE sp_fc_paginas_eventos(IN p_data DATE)
BEGIN
  DECLARE v_ant DATE;
  INSERT IGNORE INTO fc_pagina_status_diario (dia, page_id, pagina, ativo)
  SELECT p_data, id, name, active FROM `5c_pages`.pages;

  SELECT MAX(dia) INTO v_ant FROM fc_pagina_status_diario WHERE dia < p_data;

  -- (a) ficou inativa desde a foto anterior
  INSERT IGNORE INTO fc_pagina_eventos (pagina, page_id, tipo, data_evento, origem, status)
  SELECT h.pagina, h.page_id, 'SAIDA', p_data, 'AUTO', 'EFETIVADA'
  FROM fc_pagina_status_diario h
  JOIN fc_pagina_status_diario o ON o.page_id = h.page_id AND o.dia = v_ant
  WHERE h.dia = p_data AND h.ativo = 0 AND o.ativo = 1;

  -- (b) escalada nos últimos 14 dias, sem escala nos últimos 3 e sem nenhuma página ativa com o mesmo nome
  INSERT IGNORE INTO fc_pagina_eventos (pagina, page_id, tipo, data_evento, origem, status)
  SELECT s.modeloNome, MAX(s.modeloId), 'SAIDA', DATE(MAX(STR_TO_DATE(LEFT(s.shiftDateAndHour,10),'%Y-%m-%d'))) + INTERVAL 1 DAY,
         'AUTO', 'EFETIVADA'
  FROM `5c_schedule`.scheduleday s
  WHERE s.modeloId <> 3
    AND s.shiftDateAndHour >= DATE_FORMAT(p_data - INTERVAL 14 DAY, '%Y-%m-%d')
    AND NOT EXISTS (SELECT 1 FROM `5c_pages`.pages p WHERE p.name = s.modeloNome AND p.active = 1)
    AND EXISTS (SELECT 1 FROM `5c_pages`.pages p WHERE p.name = s.modeloNome AND p.active = 0)
  GROUP BY s.modeloNome
  HAVING MAX(s.shiftDateAndHour) < DATE_FORMAT(p_data - INTERVAL 3 DAY, '%Y-%m-%d');

  -- página prevista que já aparece na escala vira EFETIVADA
  UPDATE fc_paginas_previstas n
  SET n.status = 'EFETIVADA'
  WHERE n.status IN ('PREVISTA','CONFIRMADA')
    AND EXISTS (SELECT 1 FROM `5c_schedule`.scheduleday s
                WHERE s.modeloNome = n.pagina AND s.modeloId <> 3
                  AND s.shiftDateAndHour >= DATE_FORMAT(p_data - INTERVAL 7 DAY, '%Y-%m-%d'));
END $$

DROP PROCEDURE IF EXISTS sp_fc_impacto_paginas $$
CREATE PROCEDURE sp_fc_impacto_paginas(IN p_data DATE)
BEGIN
  DECLARE v_lp, v_ls, v_lk, v_kmin, v_comp, v_def, v_pres DECIMAL(10,4);
  DECLARE v_grp DECIMAL(10,4) DEFAULT 23;
  DECLARE v_ga, v_gc, v_gd DECIMAL(8,5) DEFAULT 0;
  SELECT valor INTO v_lp   FROM fc_parametros WHERE chave = 'limite_padrao';
  SELECT valor INTO v_ls   FROM fc_parametros WHERE chave = 'limite_sla80';
  SELECT valor INTO v_lk   FROM fc_parametros WHERE chave = 'limite_skylar';
  SELECT valor INTO v_kmin FROM fc_parametros WHERE chave = 'skylar_minimo';
  SELECT valor INTO v_comp FROM fc_parametros WHERE chave = 'limite_compartilhada';
  SELECT valor INTO v_def  FROM fc_parametros WHERE chave = 'fans_sem_dado';
  SELECT valor INTO v_pres FROM fc_parametros WHERE chave = 'presenca_3x1';
  SELECT COALESCE(MAX(valor),23) INTO v_grp FROM fc_parametros WHERE chave = 'capacidade_grupo';
  SELECT AVG(ta), AVG(tc), AVG(td) INTO v_ga, v_gc, v_gd FROM fc_wrk_abs_taxa;
  SET v_ga = COALESCE(v_ga,0); SET v_gc = COALESCE(v_gc,0); SET v_gd = COALESCE(v_gd,0);

  DELETE FROM fc_impacto_paginas WHERE data_calculo = p_data;

  -- SAÍDAS: reps que a página usava no último cálculo em que apareceu (página pequena = 0,5 rep)
  INSERT INTO fc_impacto_paginas (data_calculo, tipo, ref_id, pagina, data_evento, status, probabilidade,
        fans_h_media, reps_h_a, reps_h_c, reps_h_d, hc_a, hc_c, hc_d, hc_total)
  SELECT p_data, 'SAIDA', e.id, e.pagina, e.data_evento, e.status, 1,
         COALESCE(r.fans, r2.fans, 0),
         -COALESCE(r.ra, r2.ra, 0), -COALESCE(r.rc, r2.rc, 0), -COALESCE(r.rd, r2.rd, 0),
         -COALESCE(r.ra, r2.ra, 0)/v_pres/(1-v_ga), -COALESCE(r.rc, r2.rc, 0)/v_pres/(1-v_gc), -COALESCE(r.rd, r2.rd, 0)/v_pres/(1-v_gd),
         -(COALESCE(r.ra, r2.ra, 0)/v_pres/(1-v_ga) + COALESCE(r.rc, r2.rc, 0)/v_pres/(1-v_gc) + COALESCE(r.rd, r2.rd, 0)/v_pres/(1-v_gd))
  FROM fc_pagina_eventos e
  -- sem cálculo anterior com a página: usa os fãs dos 28 dias antes da saída, com a mesma regra
  LEFT JOIN (
    SELECT pagina, data_evento, AVG(fans) AS fans, AVG(ra) AS ra, AVG(rc) AS rc, AVG(rd) AS rd
    FROM (SELECT b.pagina, b.data_evento, b.dia_turno, AVG(b.f) AS fans,
                 GREATEST(MAX(IF(b.bloco='T2', b.r, 0)), MAX(IF(b.bloco='T3', b.r, 0))) AS ra,
                 GREATEST(MAX(IF(b.bloco='T4', b.r, 0)), MAX(IF(b.bloco='T5', b.r, 0))) AS rc,
                 GREATEST(MAX(IF(b.bloco='T6', b.r, 0)), MAX(IF(b.bloco='T1', b.r, 0))) AS rd
          FROM (SELECT ev.pagina, ev.data_evento, f.dia_turno, f.bloco, AVG(f.fans) AS f,
                       CASE WHEN COALESCE(m.categoria,'PADRAO') = 'SKYLAR' THEN GREATEST(v_kmin, CEIL(AVG(f.fans)/v_lk))
                            WHEN COALESCE(m.categoria,'PADRAO') = 'SLA80'  THEN CEIL(AVG(f.fans)/v_ls)
                            WHEN AVG(f.fans) > v_comp THEN CEIL(AVG(f.fans)/v_lp)
                            ELSE GREATEST(AVG(f.fans) / v_grp, 1/3) END AS r
                FROM fc_pagina_eventos ev
                JOIN fc_fans_pagina_hora f ON f.pagina = ev.pagina
                     AND f.dia_turno >= ev.data_evento - INTERVAL 28 DAY AND f.dia_turno < ev.data_evento
                LEFT JOIN fc_pagina_modelo m ON m.pagina = ev.pagina
                WHERE ev.tipo = 'SAIDA'
                GROUP BY ev.pagina, ev.data_evento, f.dia_turno, f.bloco, m.categoria) b
          GROUP BY b.pagina, b.data_evento, b.dia_turno) z
    GROUP BY pagina, data_evento) r2 ON r2.pagina = e.pagina AND r2.data_evento = e.data_evento
  LEFT JOIN (
    SELECT pagina, AVG(fans) AS fans, AVG(ra) AS ra, AVG(rc) AS rc, AVG(rd) AS rd
    FROM (SELECT ep.pagina, ep.dia, AVG(ep.fans_pico) AS fans,
                 GREATEST(MAX(IF(ep.bloco='T2', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0)),
                          MAX(IF(ep.bloco='T3', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0))) AS ra,
                 GREATEST(MAX(IF(ep.bloco='T4', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0)),
                          MAX(IF(ep.bloco='T5', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0))) AS rc,
                 GREATEST(MAX(IF(ep.bloco='T6', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0)),
                          MAX(IF(ep.bloco='T1', IF(ep.reps_dedicados>0, ep.reps_dedicados, GREATEST(ep.fans_pico / v_grp, 1/3)), 0))) AS rd
          FROM fc_escala_pagina ep
          JOIN (SELECT pagina, MAX(data_calculo) AS dc FROM fc_escala_pagina GROUP BY pagina) u
            ON u.pagina = ep.pagina AND u.dc = ep.data_calculo
          GROUP BY ep.pagina, ep.dia) z
    GROUP BY pagina) r ON r.pagina = e.pagina
  WHERE e.tipo = 'SAIDA' AND e.status <> 'CANCELADA' AND e.data_evento >= p_data - INTERVAL 30 DAY;

  -- ENTRADAS: estimativa pela regra da escala, com o formato do dia das páginas atuais
  TRUNCATE TABLE fc_wrk_nova;
  INSERT INTO fc_wrk_nova (id, dow, bloco, fans, reps)
  SELECT x.id, x.dow, x.bloco, x.f,
         CASE WHEN x.categoria = 'SKYLAR' THEN GREATEST(v_kmin, CEIL(x.f / v_lk))
              WHEN x.categoria = 'SLA80'  THEN CEIL(x.f / v_ls)
              WHEN x.f > v_comp           THEN CEIL(x.f / v_lp)
              ELSE GREATEST(x.f / v_grp, 1/3) END
  FROM (SELECT n.id, n.categoria, f.dow, f.bloco,
               GREATEST(1, COALESCE(n.fas_h_prev, n.receita_mensal_prev / 720 * k.valor, v_def) * f.fator) AS f
        FROM fc_paginas_previstas n
        CROSS JOIN fc_wrk_fator f
        LEFT JOIN fc_parametros k ON k.chave = CONCAT('k_', n.tier)
        WHERE n.status IN ('PREVISTA','CONFIRMADA')
           OR (n.status = 'EFETIVADA' AND n.data_prevista >= p_data - INTERVAL 30 DAY)) x;

  INSERT INTO fc_impacto_paginas (data_calculo, tipo, ref_id, pagina, data_evento, status, probabilidade,
        fans_h_media, reps_h_a, reps_h_c, reps_h_d, hc_a, hc_c, hc_d, hc_total)
  SELECT p_data, 'ENTRADA', n.id, n.pagina, n.data_prevista, n.status, n.probabilidade,
         r.fans, r.ra, r.rc, r.rd,
         r.ra/v_pres/(1-v_ga)*n.probabilidade, r.rc/v_pres/(1-v_gc)*n.probabilidade, r.rd/v_pres/(1-v_gd)*n.probabilidade,
         (r.ra/v_pres/(1-v_ga) + r.rc/v_pres/(1-v_gc) + r.rd/v_pres/(1-v_gd)) * n.probabilidade
  FROM fc_paginas_previstas n
  JOIN (SELECT id, AVG(fans) AS fans, AVG(ra) AS ra, AVG(rc) AS rc, AVG(rd) AS rd
        FROM (SELECT id, dow, AVG(fans) AS fans,
                     GREATEST(MAX(IF(bloco='T2',reps,0)), MAX(IF(bloco='T3',reps,0))) AS ra,
                     GREATEST(MAX(IF(bloco='T4',reps,0)), MAX(IF(bloco='T5',reps,0))) AS rc,
                     GREATEST(MAX(IF(bloco='T6',reps,0)), MAX(IF(bloco='T1',reps,0))) AS rd
              FROM fc_wrk_nova GROUP BY id, dow) z
        GROUP BY id) r ON r.id = n.id;
END $$

-- ---------------------------------------------------------------------
-- 7. Orquestração diária (com log em fc_log_execucao)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_rodar_dia $$
CREATE PROCEDURE sp_fc_rodar_dia()
BEGIN
  DECLARE v_d DATE DEFAULT CURDATE();
  DECLARE v_log INT;
  DECLARE v_msg VARCHAR(500);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    UPDATE fc_log_execucao SET fim = NOW(), status = 'ERRO', mensagem = LEFT(v_msg, 500) WHERE id = v_log;
  END;

  INSERT INTO fc_log_execucao (data_calculo, etapa, inicio) VALUES (v_d, 'carga_fans', NOW());
  SET v_log = LAST_INSERT_ID();

  CALL sp_fc_carga_fans(v_d - INTERVAL 2 DAY, v_d - INTERVAL 1 DAY);
  UPDATE fc_log_execucao SET etapa = 'hc_to' WHERE id = v_log;
  CALL sp_fc_hc_to();
  UPDATE fc_log_execucao SET etapa = 'paginas_eventos' WHERE id = v_log;
  CALL sp_fc_paginas_eventos(v_d);
  UPDATE fc_log_execucao SET etapa = 'prev_to' WHERE id = v_log;
  CALL sp_fc_prev_to(v_d);
  UPDATE fc_log_execucao SET etapa = 'escala' WHERE id = v_log;
  CALL sp_fc_escala(v_d);
  UPDATE fc_log_execucao SET etapa = 'buffer' WHERE id = v_log;
  CALL sp_fc_buffer(v_d);
  UPDATE fc_log_execucao SET etapa = 'impacto_paginas' WHERE id = v_log;
  CALL sp_fc_impacto_paginas(v_d);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 8. Evento diário às 06:30 (o turno D do dia anterior termina às 05:00)
-- ---------------------------------------------------------------------
DROP EVENT IF EXISTS ev_fc_forecast_diario;
CREATE EVENT ev_fc_forecast_diario
  ON SCHEDULE EVERY 1 DAY
  STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 6 HOUR + INTERVAL 30 MINUTE)
  ON COMPLETION PRESERVE ENABLE
  COMMENT 'Forecast WFM: HC, turnover, previsão de turnover, escala, buffers e impacto de páginas'
  DO CALL sp_fc_rodar_dia();
