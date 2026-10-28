-- =====================================================================
-- 5C | Forecast de WFM — 08. Premissas revistas em 19/10/2026 (18h38)
--  • Limite de 35 fãs/h nas páginas em que o SLA 80% não se paga
--  • Até 3 páginas por rep (C(n) da Política 5.3.1 com C₁ = 35)
--  • Fãs/h = média do bloco de 4 h (não mais a hora mais cheia)
--  • Falha administrativa (AF) pesa 0,53 no absenteísmo (47% dos AF tinham mensagens)
--  • Turmas replanejadas: 19/11 A2 C11 D7 · 03/12 A3 C10 D7 · 17/12 A3 C9 D8 · 05/01 A4 C9 D7
-- Rodar uma vez no 5c_planning já instalado (substitui as turmas dos scripts 05 e 07).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('limite_padrao',        35,   'Fãs/h por rep nas páginas em que o SLA 80% não se paga; acima disso entra assistente (decisão de 19/10)'),
 ('limite_compartilhada', 28,   'Página com até 28 fãs/h (35 × 0,8) pode dividir rep com outras; acima disso tem rep próprio'),
 ('capacidade_grupo',     23,   'Fãs/h médios que 1 rep atende somando até 3 páginas pequenas (C(n) da Política com C₁ = 35)'),
 ('max_paginas_rep',      3,    'Máximo de páginas por rep'),
 ('perfil_pico',          0,    '0 = média do bloco de 4 h (decisão de 19/10); 1 = hora mais cheia do bloco'),
 ('peso_falha_adm',       0.53, 'Fração das falhas administrativas (AF) que é ausência de fato: 47% dos AF tinham mensagens (05–16/10)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

DELETE FROM fc_contratacoes WHERE turma >= '2026-11-02';
INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs) VALUES
 ('2026-11-02','2026-11-02','2026-11-16','C',9,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-02','2026-11-02','2026-11-16','D',6,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-19','2026-11-19','2026-12-03','A',2,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','C',11,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','C',10,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','C',9,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','D',8,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2027-01-05','2027-01-05','2027-01-19','A',4,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','C',9,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','D',7,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)');

DELIMITER $$
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

DELIMITER ;

CALL sp_fc_rodar_dia();

SELECT * FROM fc_log_execucao ORDER BY id DESC LIMIT 1;
SELECT ROUND(AVG(a),1) AS a, ROUND(AVG(c),1) AS c, ROUND(AVG(d),1) AS d
FROM fc_escala_diaria WHERE data_calculo = CURDATE() AND dia < CURDATE() + INTERVAL 7 DAY;
SELECT ROUND(AVG(a),1) AS buffer_a, ROUND(AVG(c),1) AS buffer_c, ROUND(AVG(d),1) AS buffer_d,
       ROUND(AVG(taxa_abs_a)*100,1) AS abs_a, ROUND(AVG(taxa_abs_c)*100,1) AS abs_c, ROUND(AVG(taxa_abs_d)*100,1) AS abs_d
FROM fc_buffer_diario WHERE data_calculo = CURDATE() AND dia < CURDATE() + INTERVAL 7 DAY;
