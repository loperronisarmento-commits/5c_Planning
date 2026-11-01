-- =====================================================================
-- 5C | Forecast de WFM — 27. Regra de faturamento pela modelo, 30 dias, e páginas desconsideradas
-- Schema: 5c_planning        01/11/2026        Rodar depois do ES_57 do 5C Escalas.
--
-- Decisões do usuário em 01/11:
--   1. A regra de faturamento (postos de 3 e de 2 páginas por rep) usa o faturamento dos últimos
--      30 dias da MODELO (todas as páginas dela somadas: VIP, Free, OFTV...), não mais o mês fechado
--      anterior de cada página. Abaixo de receita_triple (16k) → 3 por rep; abaixo de receita_double
--      (32k) → 2 por rep. Continua só para páginas PADRAO.
--   2. Faturamento de 30 dias da página (fc_pagina_venda_dia: fechado + pendente, sem estorno):
--        - 30 dias de venda → o total;
--        - menos dias (página nova) → a média dos dias que ela tem × 30 (fat_base = EMULADO);
--        - nenhuma venda → o meio da faixa do tier da página em 5c_crm.tiers (TIER); página com
--          cara de OFTV sem venda fica sem faturamento (SEM).
--   3. Página desconsiderada (fc_pagina_carga.desconsiderada = 1): não ocupa posto e não entra no
--      pico/buffer. Vale a chave da tela Páginas › Configuração das páginas do 5C Escalas
--      (5c_escalas.esc_pagina_config): DESCONSIDERAR sempre, NORMAL nunca; AUTO = cara de OFTV
--      (OFTV no nome ou código terminado em t) com menos de v4_trivial_receita_min (US$ 8 mil)
--      em 30 dias. No 5C Escalas a página fica de apoio no posto da mesma modelo.
--   Antes deste script, o sp_fc_postos no banco era igual ao do script 23 (conferido em 01/11).
-- No fim, recalcula hoje (versão nova) e mostra o que mudou.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------------------------------------------------------------------
-- 1. Colunas novas em fc_pagina_carga (só se ainda não existem)
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_tmp_27_coluna $$
CREATE PROCEDURE sp_tmp_27_coluna(IN p_tab VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tab AND COLUMN_NAME = p_col) THEN
    SET @sql_27 = CONCAT('ALTER TABLE `', p_tab, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE st FROM @sql_27;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END $$
DELIMITER ;

CALL sp_tmp_27_coluna('fc_pagina_carga', 'fat_30d', 'DECIMAL(14,2) NULL COMMENT ''faturamento de 30 dias da página (emulado ou pelo tier, ver fat_base)''');
CALL sp_tmp_27_coluna('fc_pagina_carga', 'fat_dias', 'SMALLINT NOT NULL DEFAULT 0 COMMENT ''dias de venda nos 30 dias''');
CALL sp_tmp_27_coluna('fc_pagina_carga', 'fat_base', 'VARCHAR(10) NULL COMMENT ''30D · EMULADO (menos de 30 dias) · TIER (sem venda) · SEM''');
CALL sp_tmp_27_coluna('fc_pagina_carga', 'fat_modelo_30d', 'DECIMAL(14,2) NULL COMMENT ''faturamento de 30 dias da modelo (soma das páginas): define classe_receita''');
CALL sp_tmp_27_coluna('fc_pagina_carga', 'desconsiderada', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''1 = sem SLA: não ocupa posto nem entra no pico (5c_escalas.esc_pagina_config)''');
DROP PROCEDURE IF EXISTS sp_tmp_27_coluna;

-- ---------------------------------------------------------------------
-- 2. sp_fc_postos: o do script 23, com a regra de faturamento nova e as páginas desconsideradas
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_fc_postos $$
CREATE PROCEDURE sp_fc_postos(IN p_data DATE)
BEGIN
  DECLARE v_jan, v_jb, v_jp, v_nuc, v_nfolga, v_bmin INT;
  DECLARE v_def, v_min, v_fator, v_paf, v_pp, v_tp, v_lp, v_vol, v_alivio, v_r3, v_r2 DECIMAL(12,4);
  DECLARE v_triv DECIMAL(12,2);
  DECLARE v_mes DATE;
  DECLARE v_ref, v_ref_rep, v_foto DATE;
  DECLARE v_fim INT DEFAULT 0;
  DECLARE v_cart VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_un TINYINT;
  DECLARE c_cart CURSOR FOR SELECT carteira, unidade FROM fc_carteira WHERE ativo = 1 ORDER BY unidade, codigo;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

  SELECT CAST(COALESCE(MAX(IF(chave = 'janela_postos_dias', valor, NULL)), 14) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'janela_buffer_dias', valor, NULL)), 56) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'janela_pico_dias', valor, NULL)), 28) AS SIGNED),
         COALESCE(MAX(IF(chave = 'fans_sem_dado', valor, NULL)), 10),
         COALESCE(MAX(IF(chave = 'carga_minima_posto', valor, NULL)), 0.5),
         COALESCE(MAX(IF(chave = 'fator_pessoas_posto', valor, NULL)), 1.5),
         COALESCE(MAX(IF(chave = 'peso_af_buffer', valor, NULL)), 1),
         COALESCE(MAX(IF(chave = 'percentil_pico', valor, NULL)), 0.5),
         COALESCE(MAX(IF(chave = 'teto_pico_fans_rep', valor, NULL)), 40),
         COALESCE(MAX(IF(chave = 'limite_padrao', valor, NULL)), 35),
         CAST(COALESCE(MAX(IF(chave = 'nucleos_por_turno', valor, NULL)), 3) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'nucleos_folga_dia', valor, NULL)), 1) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'buffer_minimo_nucleo', valor, NULL)), 2) AS SIGNED),
         COALESCE(MAX(IF(chave = 'fator_volume', valor, NULL)), 1),
         COALESCE(MAX(IF(chave = 'alivio_mass_ppv', valor, NULL)), 0),
         COALESCE(MAX(IF(chave = 'receita_triple', valor, NULL)), 16000),
         COALESCE(MAX(IF(chave = 'receita_double', valor, NULL)), 32000)
    INTO v_jan, v_jb, v_jp, v_def, v_min, v_fator, v_paf, v_pp, v_tp, v_lp, v_nuc, v_nfolga, v_bmin, v_vol,
         v_alivio, v_r3, v_r2
  FROM fc_parametros;
  SET v_lp = v_lp * (1 + v_alivio);
  -- página com cara de OFTV abaixo disto em 30 dias é desconsiderada (parâmetro do 5C Escalas, ES_57)
  SET v_triv = COALESCE((SELECT CAST(valor AS DECIMAL(12,2)) FROM `5c_escalas`.esc_parametro WHERE chave = 'v4_trivial_receita_min'), 8000);

  -- faturamento por página e dia (agg_statements_classification), para a regra de faturamento e a versão
  CALL sp_fc_vendas_atualizar(p_data);
  SET v_mes = p_data - INTERVAL (DAYOFMONTH(p_data) - 1) DAY;   -- 1º dia do mês da data; vale o mês anterior

  -- estrutura vigente na data (antes do início, a primeira cadastrada)
  SELECT GREATEST(p_data, COALESCE(MIN(vigente_desde), p_data)) INTO v_ref FROM fc_pagina_carteira;
  SELECT GREATEST(p_data, COALESCE(MIN(vigente_desde), p_data)) INTO v_ref_rep FROM fc_estrutura_rep;
  -- foto do cadastro de páginas na data (ou a mais recente antes dela)
  SELECT MAX(dia) INTO v_foto FROM fc_pagina_status_diario WHERE dia <= p_data;

  DELETE FROM fc_pagina_carga WHERE data_calculo = p_data OR data_calculo < p_data - INTERVAL 180 DAY;
  DELETE FROM fc_postos WHERE data_calculo = p_data OR data_calculo < p_data - INTERVAL 180 DAY;
  DELETE FROM fc_postos_pagina WHERE data_calculo = p_data OR data_calculo < p_data - INTERVAL 180 DAY;
  DELETE FROM fc_necessidade_carteira WHERE data_calculo = p_data;
  DELETE FROM fc_necessidade_estrutura WHERE data_calculo = p_data;
  DELETE FROM fc_buffer_turno WHERE data_calculo = p_data;

  -- 1. Carga de cada página da estrutura em cada turno
  INSERT INTO fc_pagina_carga (data_calculo, pagina, turno, carteira, modelo, regra, ordem, fans_h, origem, ocupa_posto)
  SELECT p_data, z.pagina, t.turno, z.carteira, z.modelo, z.regra, z.ordem,
         CASE WHEN z.tem_chat = 1
              THEN GREATEST(CASE t.turno WHEN 'A' THEN z.t2 WHEN 'C' THEN z.t4 ELSE z.t6 END,
                            CASE t.turno WHEN 'A' THEN z.t3 WHEN 'C' THEN z.t5 ELSE z.t1 END)
              ELSE COALESCE(z.fas_h_estimado, v_def) END,
         CASE WHEN z.tem_chat = 1 THEN 'CHAT' WHEN z.fas_h_estimado IS NOT NULL THEN 'ESTIMADA' ELSE 'SEM_DADO' END,
         0
  FROM (SELECT pc.pagina, pc.carteira, pc.modelo, pc.regra, pc.ordem, pc.fas_h_estimado,
               IF(f.pagina IS NULL, 0, 1) AS tem_chat,
               COALESCE(f.t1, 0) AS t1, COALESCE(f.t2, 0) AS t2, COALESCE(f.t3, 0) AS t3,
               COALESCE(f.t4, 0) AS t4, COALESCE(f.t5, 0) AS t5, COALESCE(f.t6, 0) AS t6
        FROM fc_pagina_carteira pc
        JOIN fc_carteira c ON c.carteira = pc.carteira AND c.ativo = 1
        LEFT JOIN (SELECT h.pagina,
                          SUM(IF(h.bloco = 'T1', h.fans, 0)) / 4 / v_jan AS t1,
                          SUM(IF(h.bloco = 'T2', h.fans, 0)) / 4 / v_jan AS t2,
                          SUM(IF(h.bloco = 'T3', h.fans, 0)) / 4 / v_jan AS t3,
                          SUM(IF(h.bloco = 'T4', h.fans, 0)) / 4 / v_jan AS t4,
                          SUM(IF(h.bloco = 'T5', h.fans, 0)) / 4 / v_jan AS t5,
                          SUM(IF(h.bloco = 'T6', h.fans, 0)) / 4 / v_jan AS t6
                   FROM fc_fans_pagina_hora h
                   WHERE h.dia_turno >= p_data - INTERVAL v_jan DAY AND h.dia_turno < p_data
                   GROUP BY h.pagina) f ON f.pagina = pc.pagina
        WHERE pc.vigente_desde <= v_ref AND (pc.vigente_ate IS NULL OR pc.vigente_ate > v_ref)
          AND COALESCE((SELECT MAX(s.ativo) FROM fc_pagina_status_diario s
                        WHERE s.dia = v_foto AND s.pagina = pc.pagina), 1) = 1) z
  CROSS JOIN (SELECT 'A' AS turno UNION ALL SELECT 'C' UNION ALL SELECT 'D') t;

  UPDATE fc_pagina_carga SET ocupa_posto = (fans_h >= v_min) WHERE data_calculo = p_data;
  -- páginas sem escala (fc_pagina_sem_escala, nome exato ou com %): ficam na carga, mas não ocupam posto
  UPDATE fc_pagina_carga pc SET pc.ocupa_posto = 0
   WHERE pc.data_calculo = p_data
     AND EXISTS (SELECT 1 FROM fc_pagina_sem_escala x WHERE pc.pagina LIKE x.padrao);

  -- faturamento de 30 dias de cada página (fc_pagina_venda_dia: fechado + pendente, sem estorno), 01/11:
  --   30 dias de venda → o total; menos dias (página nova) → a média dos dias que ela tem × 30 (EMULADO);
  --   nenhuma venda → o meio da faixa do tier da página (5c_crm.tiers), menos na de cara de OFTV (SEM)
  UPDATE fc_pagina_carga pc
  LEFT JOIN (SELECT REPLACE(pagina, '  ', ' ') AS k, SUM(bruto) AS b,
                    LEAST(30, GREATEST(1, DATEDIFF(p_data, MIN(dia)))) AS d
             FROM fc_pagina_venda_dia
             WHERE dia >= p_data - INTERVAL 30 DAY AND dia < p_data
             GROUP BY REPLACE(pagina, '  ', ' ')) f ON f.k = REPLACE(pc.pagina, '  ', ' ')
  LEFT JOIN (SELECT REPLACE(name, '  ', ' ') AS k, MAX(tier) AS tier
             FROM `5c_pages`.pages WHERE active = 1
             GROUP BY REPLACE(name, '  ', ' ')) g ON g.k = REPLACE(pc.pagina, '  ', ' ')
  LEFT JOIN `5c_crm`.tiers t ON t.letter = g.tier
     SET pc.fat_dias = COALESCE(f.d, 0),
         pc.fat_30d = CASE WHEN f.b IS NOT NULL THEN f.b * 30 / f.d
                           WHEN pc.pagina REGEXP 'oftv|(^|[^[:alnum:]])[0-9]+t([^[:alnum:]]|$)' THEN NULL
                           WHEN t.letter IS NOT NULL THEN (t.min_value + COALESCE(t.max_value, t.min_value)) / 2 END,
         pc.fat_base = CASE WHEN f.b IS NOT NULL THEN IF(f.d < 30, 'EMULADO', '30D')
                            WHEN pc.pagina REGEXP 'oftv|(^|[^[:alnum:]])[0-9]+t([^[:alnum:]]|$)' THEN 'SEM'
                            WHEN t.letter IS NOT NULL THEN 'TIER' ELSE 'SEM' END
   WHERE pc.data_calculo = p_data;

  -- página desconsiderada (sem SLA, não ocupa posto nem entra no pico), pela chave do 5C Escalas
  -- (esc_pagina_config): DESCONSIDERAR sempre, NORMAL nunca; AUTO = cara de OFTV e menos de v_triv em 30 dias
  UPDATE fc_pagina_carga pc
  LEFT JOIN `5c_escalas`.esc_pagina_config x ON REPLACE(x.pagina, '  ', ' ') = REPLACE(pc.pagina, '  ', ' ')
     SET pc.desconsiderada = CASE COALESCE(x.sla_modo, 'AUTO')
                               WHEN 'DESCONSIDERAR' THEN 1
                               WHEN 'NORMAL' THEN 0
                               ELSE IF(pc.pagina REGEXP 'oftv|(^|[^[:alnum:]])[0-9]+t([^[:alnum:]]|$)'
                                       AND COALESCE(pc.fat_30d, 0) < v_triv, 1, 0) END
   WHERE pc.data_calculo = p_data;
  UPDATE fc_pagina_carga SET ocupa_posto = 0 WHERE data_calculo = p_data AND desconsiderada = 1;

  -- regra de faturamento (01/11): pelo faturamento de 30 dias da MODELO (todas as páginas dela somadas);
  -- só páginas PADRAO. Abaixo de receita_triple → classe 3 (3 por rep) · abaixo de receita_double → classe 2.
  -- fat_mes continua com o mês fechado anterior, só como informação.
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_modelo_30d;
  CREATE TEMPORARY TABLE tmp_fc_modelo_30d (PRIMARY KEY (modelo)) AS
  SELECT u.modelo, SUM(u.fat) AS fat
  FROM (SELECT pagina, COALESCE(modelo, pagina) AS modelo, MAX(fat_30d) AS fat
        FROM fc_pagina_carga WHERE data_calculo = p_data
        GROUP BY pagina, COALESCE(modelo, pagina)) u
  GROUP BY u.modelo;

  UPDATE fc_pagina_carga pc
  LEFT JOIN tmp_fc_modelo_30d mm ON mm.modelo = COALESCE(pc.modelo, pc.pagina)
  LEFT JOIN (SELECT pagina, SUM(bruto) AS b FROM fc_pagina_venda_dia
             WHERE dia >= v_mes - INTERVAL 1 MONTH AND dia < v_mes GROUP BY pagina) f ON f.pagina = pc.pagina
  LEFT JOIN fc_pagina_infloww i ON i.pagina = pc.pagina
     SET pc.fat_mes = f.b,
         pc.fat_modelo_30d = mm.fat,
         pc.agencia = i.agency_id,
         pc.classe_receita = CASE WHEN pc.regra <> 'PADRAO' OR mm.fat IS NULL THEN 0
                                  WHEN mm.fat < v_r3 THEN 3 WHEN mm.fat < v_r2 THEN 2 ELSE 0 END
   WHERE pc.data_calculo = p_data;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_modelo_30d;

  -- 2. Postos por carteira e turno
  OPEN c_cart;
  carts: LOOP
    FETCH c_cart INTO v_cart, v_un;
    IF v_fim = 1 THEN LEAVE carts; END IF;
    CALL sp_fc_postos_carteira(p_data, v_cart, v_un, 'A');
    CALL sp_fc_postos_carteira(p_data, v_cart, v_un, 'C');
    CALL sp_fc_postos_carteira(p_data, v_cart, v_un, 'D');
  END LOOP;
  CLOSE c_cart;

  -- 3. Necessidade por carteira e turno
  INSERT INTO fc_necessidade_carteira (data_calculo, carteira, turno, postos, titulares, tem)
  SELECT p_data, c.carteira, t.turno, COALESCE(p.n, 0), CEIL(COALESCE(p.n, 0) * v_fator), COALESCE(r.n, 0)
  FROM fc_carteira c
  CROSS JOIN (SELECT 'A' AS turno UNION ALL SELECT 'C' UNION ALL SELECT 'D') t
  LEFT JOIN (SELECT carteira, turno, COUNT(*) AS n FROM fc_postos
             WHERE data_calculo = p_data GROUP BY carteira, turno) p
         ON p.carteira = c.carteira AND p.turno = t.turno
  LEFT JOIN (SELECT carteira, turno, COUNT(*) AS n FROM fc_estrutura_rep
             WHERE ficticio = 0 AND vigente_desde <= v_ref_rep AND (vigente_ate IS NULL OR vigente_ate > v_ref_rep)
             GROUP BY carteira, turno) r
         ON r.carteira = c.carteira AND r.turno = t.turno
  WHERE c.ativo = 1;

  -- 4. Taxa de falta por turno: absenteísmo e falha administrativa (abs_adh_af, janela_buffer_dias).
  --    A AF_DOBRA não entra: a dobra é decisão de escala, não falta (com os buffers ela some).
  TRUNCATE TABLE fc_wrk_buf_taxa;
  INSERT INTO fc_wrk_buf_taxa (turno, taxa_abs, taxa_af)
  SELECT z.tu, SUM(z.classificacao = 'ABS') / COUNT(*),
         SUM(z.classificacao = 'AF' AND IFNULL(z.motivo_classificacao, '') <> 'AF_DOBRA') / COUNT(*)
  FROM (SELECT CASE WHEN a.hora IN (5, 9) THEN 'A' WHEN a.hora IN (13, 17) THEN 'C' ELSE 'D' END AS tu,
               a.classificacao, a.motivo_classificacao,
               IF(a.hora = 1, a.dia - INTERVAL 1 DAY, a.dia) AS td
        FROM `5c_wfm_db_mis`.abs_adh_af a
        WHERE a.dia >= p_data - INTERVAL (v_jb + 1) DAY AND a.dia < p_data) z
  WHERE z.td >= p_data - INTERVAL v_jb DAY AND z.td < p_data
  GROUP BY z.tu;

  -- 5. Aumento de fluxo (pico): em cada hora, fãs de cada página acima da capacidade dos postos dela
  --    (com a tolerância até teto_pico_fans_rep por rep), somados na unidade; o pior horário de cada
  --    dia, em reps. Os fãs da janela são multiplicados pelo fator_volume (sazonalidade prevista).
  TRUNCATE TABLE fc_wrk_pico_dia;
  INSERT INTO fc_wrk_pico_dia (unidade, turno, dia, mx)
  SELECT y.unidade, y.turno, y.dia_turno, MAX(y.exc) / v_lp
  FROM (SELECT c.unidade, h.turno, h.dia_turno, h.hora,
               SUM(GREATEST(h.fans * v_vol - COALESCE(cp.cap, 0) * v_tp / v_lp, 0)) AS exc
        FROM fc_pagina_carga pc
        JOIN fc_carteira c ON c.carteira = pc.carteira
        JOIN fc_fans_pagina_hora h ON h.pagina = pc.pagina AND h.turno = pc.turno
             AND h.dia_turno >= p_data - INTERVAL v_jp DAY AND h.dia_turno < p_data
        -- nos postos da regra de faturamento a carga acima da capacidade é aceita (não vira buffer)
        LEFT JOIN (SELECT pp.pagina, pp.turno,
                          SUM(IF(p.forcado = 1, GREATEST(p.capacidade, p.carga), p.capacidade) * pp.fans_h / NULLIF(p.carga, 0)) AS cap
                   FROM fc_postos_pagina pp
                   JOIN fc_postos p ON p.data_calculo = pp.data_calculo AND p.carteira = pp.carteira
                                   AND p.turno = pp.turno AND p.posto = pp.posto
                   WHERE pp.data_calculo = p_data
                   GROUP BY pp.pagina, pp.turno) cp
               ON cp.pagina = pc.pagina AND cp.turno = pc.turno
        WHERE pc.data_calculo = p_data AND pc.desconsiderada = 0
        GROUP BY c.unidade, h.turno, h.dia_turno, h.hora) y
  GROUP BY y.unidade, y.turno, y.dia_turno;

  -- percentil_pico dos picos diários (interpolado, como o percentil do Excel/numpy)
  TRUNCATE TABLE fc_wrk_pico;
  INSERT INTO fc_wrk_pico (unidade, turno, pico, dias)
  SELECT w.unidade, w.turno, w.v1 + (w.k - FLOOR(w.k)) * (COALESCE(w.v2, w.v1) - w.v1), w.n
  FROM (SELECT z.unidade, z.turno, z.n, z.k,
               MAX(IF(z.rn = FLOOR(z.k) + 1, z.mx, NULL)) AS v1,
               MAX(IF(z.rn = FLOOR(z.k) + 2, z.mx, NULL)) AS v2
        FROM (SELECT unidade, turno, mx,
                     ROW_NUMBER() OVER (PARTITION BY unidade, turno ORDER BY mx) AS rn,
                     COUNT(*) OVER (PARTITION BY unidade, turno) AS n,
                     (COUNT(*) OVER (PARTITION BY unidade, turno) - 1) * v_pp AS k
              FROM fc_wrk_pico_dia) z
        GROUP BY z.unidade, z.turno, z.n, z.k) w;

  -- 6. Necessidade por unidade e turno: titulares (quadro fixo enxuto) e buffers presentes por dia
  --    presentes = (postos × taxa de falta + pico) ÷ (1 − taxa de falta)   (o buffer também falta)
  INSERT INTO fc_necessidade_estrutura (data_calculo, unidade, turno, postos, titulares, taxa_abs, taxa_af,
                                        faltas_dia, pico_dia, presentes_dia, buffer_minimo, buffer, precisa, tem, saldo)
  SELECT p_data, v.unidade, v.turno, v.postos, v.tit, v.ta, v.tf, ROUND(v.postos * v.tx, 2), ROUND(v.pico, 2),
         (v.postos * v.tx + v.pico) / (1 - v.tx), v_bmin * v_nuc, 0, v.tit, v.tem, v.tem - v.tit
  FROM (SELECT x.unidade, x.turno, x.postos, x.tit,
               COALESCE(bt.taxa_abs, 0) AS ta, COALESCE(bt.taxa_af, 0) AS tf,
               COALESCE(bt.taxa_abs, 0) + v_paf * COALESCE(bt.taxa_af, 0) AS tx,
               COALESCE(pk.pico, 0) AS pico, COALESCE(r.n, 0) AS tem
        FROM (SELECT c.unidade, n.turno, SUM(n.postos) AS postos, CEIL(SUM(n.postos) * v_fator) AS tit
              FROM fc_necessidade_carteira n
              JOIN fc_carteira c ON c.carteira = n.carteira
              WHERE n.data_calculo = p_data
              GROUP BY c.unidade, n.turno) x
        LEFT JOIN fc_wrk_buf_taxa bt ON bt.turno = x.turno
        LEFT JOIN fc_wrk_pico pk ON pk.unidade = x.unidade AND pk.turno = x.turno
        LEFT JOIN (SELECT unidade, turno, COUNT(*) AS n FROM fc_estrutura_rep
                   WHERE ficticio = 0 AND vigente_desde <= v_ref_rep AND (vigente_ate IS NULL OR vigente_ate > v_ref_rep)
                   GROUP BY unidade, turno) r
               ON r.unidade = x.unidade AND r.turno = x.turno) v;

  -- 7. Buffer do turno, contado no total das unidades: ↑ Σ presentes × núcleos ÷ núcleos trabalhando
  --    (o buffer segue a folga do núcleo: 2 de 3 trabalham por dia)
  INSERT INTO fc_buffer_turno (data_calculo, turno, presentes_dia, buffer_global, buffer_minimo, buffer)
  SELECT p_data, turno, SUM(presentes_dia), CEIL(SUM(presentes_dia) * v_nuc / (v_nuc - v_nfolga)), SUM(buffer_minimo), 0
  FROM fc_necessidade_estrutura
  WHERE data_calculo = p_data
  GROUP BY turno;

  -- 8. Distribuição entre as unidades: parte proporcional aos presentes (maiores restos), nunca abaixo
  --    do mínimo. Dentro da unidade, o Planning tool divide entre os núcleos, livremente acima do mínimo.
  TRUNCATE TABLE fc_wrk_buffer_aloc;
  INSERT INTO fc_wrk_buffer_aloc (unidade, turno, buffer)
  SELECT a.unidade, a.turno,
         a.base + IF(a.rk <= GREATEST(a.g - SUM(a.base) OVER (PARTITION BY a.turno), 0), 1, 0)
  FROM (SELECT s.unidade, s.turno, s.g, GREATEST(s.minimo, FLOOR(s.parte)) AS base,
               ROW_NUMBER() OVER (PARTITION BY s.turno ORDER BY s.parte - FLOOR(s.parte) DESC, s.unidade) AS rk
        FROM (SELECT n.unidade, n.turno, n.buffer_minimo AS minimo, b.buffer_global AS g,
                     b.buffer_global * n.presentes_dia / NULLIF(b.presentes_dia, 0) AS parte
              FROM fc_necessidade_estrutura n
              JOIN fc_buffer_turno b ON b.data_calculo = n.data_calculo AND b.turno = n.turno
              WHERE n.data_calculo = p_data) s) a;

  UPDATE fc_necessidade_estrutura n
  JOIN fc_wrk_buffer_aloc w ON w.unidade = n.unidade AND w.turno = n.turno
     SET n.buffer = w.buffer, n.precisa = n.titulares + w.buffer, n.saldo = n.tem - (n.titulares + w.buffer)
   WHERE n.data_calculo = p_data;

  UPDATE fc_buffer_turno b
  JOIN (SELECT turno, SUM(buffer) AS soma FROM fc_wrk_buffer_aloc GROUP BY turno) w ON w.turno = b.turno
     SET b.buffer = w.soma
   WHERE b.data_calculo = p_data;
END $$
DELIMITER ;

-- ---------------------------------------------------------------------
-- 3. Visão (20): faturamento de 30 dias da página e da modelo, e a regra
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_fc_20_paginas_faturamento AS
SELECT pc.pagina AS `Página`, pc.carteira AS `Carteira`, pc.modelo AS `Modelo`, pc.regra AS `Regra`,
       ROUND(MAX(pc.fat_30d)) AS `Faturamento 30 dias`, MAX(pc.fat_base) AS `Base`, MAX(pc.fat_dias) AS `Dias de venda`,
       ROUND(MAX(pc.fat_modelo_30d)) AS `Faturamento da modelo 30 dias`,
       CASE MAX(pc.classe_receita) WHEN 3 THEN '3 por rep' WHEN 2 THEN '2 por rep' ELSE '' END AS `Regra de faturamento`,
       IF(MAX(pc.desconsiderada) = 1, 'sim', '') AS `Desconsiderada`,
       ROUND(MAX(pc.fat_mes)) AS `Faturamento mês anterior`,
       MAX(i.agencia) AS `Agência Infloww`, MAX(pc.data_calculo) AS `Data do cálculo`
FROM fc_pagina_carga pc
LEFT JOIN fc_pagina_infloww i ON i.pagina = pc.pagina
WHERE pc.data_calculo = (SELECT MAX(data_calculo) FROM fc_pagina_carga)
GROUP BY pc.pagina, pc.carteira, pc.modelo, pc.regra
ORDER BY MAX(pc.fat_modelo_30d) DESC, MAX(pc.fat_30d) DESC;

-- ---------------------------------------------------------------------
-- 4. Recalcular hoje com a regra nova (versão nova)
-- ---------------------------------------------------------------------
CALL sp_fc_recalcular_postos(CURDATE(), 'Regra de faturamento pela modelo, 30 dias, e páginas desconsideradas (script 27)');

-- ---------------------------------------------------------------------
-- 5. Conferência
-- ---------------------------------------------------------------------
-- 5.1 A versão nova: o que mudou
SELECT `Versão`, `Precisa`, `Δ precisa`, `Alerta`, `O que mudou` FROM vw_fc_13_capacity_versoes LIMIT 1;
-- 5.2 Páginas por regra (esperado com os dados de 01/11: Aline Novak, Mo Saffari, Yuni Yung e Ellie D fora dos postos de 2 e 3)
SELECT `Regra de faturamento`, COUNT(*) AS `Páginas` FROM vw_fc_20_paginas_faturamento GROUP BY `Regra de faturamento`;
SELECT * FROM vw_fc_20_paginas_faturamento;
-- 5.3 Desconsideradas (esperado: as OFTV abaixo de US$ 8 mil; Lily Lou, Rocket Reyna e Livinia Roberts ficam normais)
SELECT `Página`, `Faturamento 30 dias`, `Base` FROM vw_fc_20_paginas_faturamento WHERE `Desconsiderada` = 'sim';
-- 5.4 Postos com páginas de modelos diferentes e capacidade de 3 páginas
SELECT p.carteira AS `Carteira`, p.turno AS `Turno`, p.posto AS `Posto`, ROUND(p.carga, 1) AS `Carga`, ROUND(p.capacidade, 1) AS `Capacidade`,
       GROUP_CONCAT(pp.pagina ORDER BY pp.pagina SEPARATOR ' + ') AS `Páginas`
FROM fc_postos p
JOIN fc_postos_pagina pp ON pp.data_calculo = p.data_calculo AND pp.carteira = p.carteira AND pp.turno = p.turno AND pp.posto = p.posto
WHERE p.data_calculo = CURDATE() AND p.forcado = 1
GROUP BY p.carteira, p.turno, p.posto, p.carga, p.capacidade
ORDER BY p.carteira, p.turno, p.posto;

-- ---------------------------------------------------------------------
-- 6. Desfazer (só se precisar): rodar de novo o bloco do sp_fc_postos do script 23 e recalcular
--    (as colunas novas ficam, sem uso).
-- ---------------------------------------------------------------------
