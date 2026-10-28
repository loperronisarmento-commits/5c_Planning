-- =====================================================================
-- 5C | Forecast de WFM — 11. Postos por página e necessidade da estrutura em unidades
-- Schema: 5c_planning        Versão 1.1 — 28/10/2026 (agrupa primeiro as páginas da mesma modelo)
--
-- sp_fc_postos(p_data): mesma regra do posts.py de 27/10, por carteira e turno.
--   Carga da página no turno = maior média de bloco de 4 h dos 2 blocos do turno,
--   nos últimos janela_postos_dias dias (fc_fans_pagina_hora). Sem chat na janela:
--   fas_h_estimado da página (ou fans_sem_dado).
--   - Menos de carga_minima_posto fãs/h: não ocupa posto; abaixo de 1, conta como 1.
--   - SKYLAR: máx(skylar_minimo, ↑ carga ÷ limite_skylar) postos.
--   - SLA80 (Bianca): máx(1, ↑ carga ÷ limite_sla80), nunca agrupa.
--   - ASSISTENTE_FIXO: máx(assistente_fixo_minimo, ↑ carga ÷ limite_padrao).
--   - Acima de teto_apoio: titular com 35; o excedente vira apoio (agrupado com páginas
--     pequenas) ou, se passar de limite_compartilhada, assistente(s).
--   - Acima de limite_compartilhada, ou EXCLUSIVA: titular próprio (no teto se > 35).
--   - O resto é agrupado em duas fases (decisão de 28/10: primeiro as páginas da mesma modelo):
--     1) só a mesma modelo, até max_paginas_rep por rep e dentro de 35 × (1 − 10% × (n − 1));
--     2) as que ficaram sozinhas, entre modelos, até max_paginas_rep_modelos_diferentes (2, Política)
--        e dentro de 35 × (1 − 20% × (n − 1)).
--     Em cada fase, da maior para a menor, no grupo que fica mais justo. Um grupo tem no máximo um apoio.
--   Necessidade por unidade e turno:
--   - titulares = ↑ postos × fator_pessoas_posto (1,5: 5x2 rotativo de 21 dias; quadro fixo enxuto);
--   - buffer contado no total do turno: ↑ Σ presentes × 3 ÷ 2 (o buffer segue a folga do núcleo),
--     com presentes = (postos × taxa de falta + pico) ÷ (1 − taxa de falta) em cada unidade; o turno é
--     distribuído entre as unidades pela parte de cada uma, nunca abaixo de buffer_minimo_nucleo × 3, onde
--     taxa de falta = absenteísmo + peso_af_buffer × falha administrativa do turno (abs_adh_af, sem AF_DOBRA) e
--     pico = percentil_pico dos picos diários: no pior horário do dia, os fãs que passam da
--     capacidade dos postos de cada página (tolerância até teto_pico_fans_rep por rep), em reps.
--   "Tem" = reps reais vigentes em fc_estrutura_rep.
--
-- Estrutura usada: a vigente na data; antes do início da primeira (01/11), a primeira.
-- Páginas: só as ativas na foto do cadastro (fc_pagina_status_diario) da data.
--
-- Também recria o sp_fc_rodar_dia com a etapa nova 'postos' no fim (o resto igual).
-- Rodar depois do 10. No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- Tabela de trabalho da fase 2 do agrupamento (páginas que ficaram sozinhas na fase da mesma modelo)
CREATE TABLE IF NOT EXISTS fc_wrk_pc_solo (
  ordem   INT          NOT NULL,
  pagina  VARCHAR(150) NOT NULL,
  modelo  VARCHAR(150) NOT NULL,
  f       DECIMAL(6,1) NOT NULL,
  apoio   TINYINT      NOT NULL,
  PRIMARY KEY (ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- ---------------------------------------------------------------------
-- Postos de uma carteira num turno (chamado pelo sp_fc_postos)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_postos_carteira $$
CREATE PROCEDURE sp_fc_postos_carteira(IN p_data DATE, IN p_cart VARCHAR(8), IN p_un TINYINT, IN p_tur CHAR(1))
BEGIN
  DECLARE v_lp, v_comp, v_lk, v_ls, v_teto, v_s1, v_s2 DECIMAL(10,4);
  DECLARE v_kmin, v_afmin, v_maxp, v_maxd INT;
  DECLARE v_fim INT DEFAULT 0;
  DECLARE v_pag VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_mod VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_regra VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_F, v_O, v_cap DECIMAL(10,4);
  DECLARE v_k, v_i INT;
  DECLARE v_n INT DEFAULT 0;
  DECLARE v_excl TINYINT;
  DECLARE c_pag CURSOR FOR
    SELECT pagina, modelo, regra, GREATEST(fans_h, 1)
    FROM fc_pagina_carga
    WHERE data_calculo = p_data AND carteira = p_cart AND turno = p_tur AND ocupa_posto = 1
    ORDER BY ordem, pagina;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

  SELECT COALESCE(MAX(IF(chave = 'limite_padrao', valor, NULL)), 35),
         COALESCE(MAX(IF(chave = 'limite_compartilhada', valor, NULL)), 28),
         COALESCE(MAX(IF(chave = 'limite_skylar', valor, NULL)), 40),
         COALESCE(MAX(IF(chave = 'limite_sla80', valor, NULL)), 20),
         COALESCE(MAX(IF(chave = 'teto_apoio', valor, NULL)), 40),
         COALESCE(MAX(IF(chave = 'desconto_mesma_modelo', valor, NULL)), 0.10),
         COALESCE(MAX(IF(chave = 'desconto_modelo_diferente', valor, NULL)), 0.20),
         CAST(COALESCE(MAX(IF(chave = 'skylar_minimo', valor, NULL)), 4) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'assistente_fixo_minimo', valor, NULL)), 2) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'max_paginas_rep', valor, NULL)), 3) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'max_paginas_rep_modelos_diferentes', valor, NULL)), 2) AS SIGNED)
    INTO v_lp, v_comp, v_lk, v_ls, v_teto, v_s1, v_s2, v_kmin, v_afmin, v_maxp, v_maxd
  FROM fc_parametros;

  TRUNCATE TABLE fc_wrk_pc_item;
  TRUNCATE TABLE fc_wrk_pc_bin;
  TRUNCATE TABLE fc_wrk_pc_bin_item;

  -- 1. Páginas com posto próprio; as pequenas (e os excedentes) vão para fc_wrk_pc_item
  OPEN c_pag;
  pags: LOOP
    FETCH c_pag INTO v_pag, v_mod, v_regra, v_F;
    IF v_fim = 1 THEN LEAVE pags; END IF;

    SET v_k = 0;
    IF v_regra = 'SKYLAR' THEN
      SET v_k = GREATEST(v_kmin, CEIL(v_F / v_lk)), v_cap = v_lk, v_excl = 0;
    ELSEIF v_regra = 'SLA80' THEN
      SET v_k = GREATEST(1, CEIL(v_F / v_ls)), v_cap = v_ls, v_excl = 1;
    ELSEIF v_regra = 'ASSISTENTE_FIXO' THEN
      SET v_k = GREATEST(v_afmin, CEIL(v_F / v_lp)), v_cap = v_lp, v_excl = 0;
    END IF;

    IF v_k > 0 THEN
      -- titular + assistentes dividindo a carga
      SET v_i = 1;
      WHILE v_i <= v_k DO
        SET v_n = v_n + 1;
        INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                               carga, capacidade, no_teto, exclusiva, excedente)
        VALUES (p_data, p_cart, p_tur, v_n, p_un, IF(v_i = 1, 'titular', 'assistente'), v_pag, 1,
                ROUND(v_F / v_k, 1), v_cap, 0, v_excl, NULL);
        INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
        VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_F / v_k, 1), 0, 1);
        SET v_i = v_i + 1;
      END WHILE;

    ELSEIF v_F > v_teto THEN
      -- titular fica com 35; o excedente vira apoio ou assistente(s)
      SET v_O = v_F - v_lp;
      SET v_n = v_n + 1;
      INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                             carga, capacidade, no_teto, exclusiva, excedente)
      VALUES (p_data, p_cart, p_tur, v_n, p_un, 'titular', v_pag, 1, v_lp, v_lp, 0, 0, ROUND(v_O, 1));
      INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
      VALUES (p_data, p_cart, p_tur, v_n, v_pag, v_lp, 0, 1);
      IF v_O > v_comp THEN
        SET v_k = CEIL(v_O / v_lp), v_i = 1;
        WHILE v_i <= v_k DO
          SET v_n = v_n + 1;
          INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                                 carga, capacidade, no_teto, exclusiva, excedente)
          VALUES (p_data, p_cart, p_tur, v_n, p_un, 'assistente', v_pag, 1, ROUND(v_O / v_k, 1), v_lp, 0, 0, NULL);
          INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
          VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_O / v_k, 1), 0, 1);
          SET v_i = v_i + 1;
        END WHILE;
      ELSE
        INSERT INTO fc_wrk_pc_item (pagina, modelo, f, apoio) VALUES (v_pag, v_mod, ROUND(v_O, 1), 1);
      END IF;

    ELSEIF v_F > v_comp OR v_regra = 'EXCLUSIVA' THEN
      -- rep próprio (no teto entre 35 e 40)
      SET v_n = v_n + 1;
      INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                             carga, capacidade, no_teto, exclusiva, excedente)
      VALUES (p_data, p_cart, p_tur, v_n, p_un, 'titular', v_pag, 1, ROUND(v_F, 1), v_lp,
              v_F > v_lp, v_regra = 'EXCLUSIVA', NULL);
      INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
      VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_F, 1), 0, 1);

    ELSE
      INSERT INTO fc_wrk_pc_item (pagina, modelo, f, apoio) VALUES (v_pag, v_mod, ROUND(v_F, 1), 0);
    END IF;
  END LOOP;
  CLOSE c_pag;

  -- 2. Agrupamento das páginas pequenas, em duas fases (decisão de 28/10):
  --    2a. primeiro só páginas da mesma modelo (até max_paginas_rep, capacidade 35 × (1 − 10% × (n − 1)));
  --    2b. as que ficaram sozinhas são combinadas entre modelos (até max_paginas_rep_modelos_diferentes).
  --    Em cada fase: da maior para a menor; o grupo que cabe e fica mais justo.
  BEGIN
    DECLARE v_fim2 INT DEFAULT 0;
    DECLARE v_id, v_bin INT;
    DECLARE v_nb INT DEFAULT 0;
    DECLARE v_ip VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_im VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_if DECIMAL(6,1);
    DECLARE v_ia TINYINT;
    DECLARE c_it CURSOR FOR SELECT id, pagina, modelo, f, apoio FROM fc_wrk_pc_item ORDER BY f DESC, id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim2 = 1;

    -- 2a. Mesma modelo
    OPEN c_it;
    its: LOOP
      FETCH c_it INTO v_id, v_ip, v_im, v_if, v_ia;
      IF v_fim2 = 1 THEN LEAVE its; END IF;

      SET v_bin = NULL;
      SELECT b.bin INTO v_bin
      FROM fc_wrk_pc_bin b
      WHERE b.modelo_unico = v_im
        AND b.n < v_maxp
        AND NOT (v_ia = 1 AND b.apoio = 1)
        AND b.soma + v_if <= v_lp * (1 - v_s1 * b.n)
      ORDER BY v_lp * (1 - v_s1 * b.n) - (b.soma + v_if), b.bin
      LIMIT 1;
      SET v_fim2 = 0;   -- a busca sem resultado não é o fim do cursor

      IF v_bin IS NULL THEN
        SET v_nb = v_nb + 1;
        INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira) VALUES (v_nb, 1, v_if, v_ia, v_im, v_ip);
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, f, apoio) VALUES (v_nb, 1, v_ip, v_im, v_if, v_ia);
      ELSE
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, f, apoio)
        SELECT v_bin, n + 1, v_ip, v_im, v_if, v_ia FROM fc_wrk_pc_bin WHERE bin = v_bin;
        UPDATE fc_wrk_pc_bin SET n = n + 1, soma = soma + v_if, apoio = GREATEST(apoio, v_ia) WHERE bin = v_bin;
      END IF;
    END LOOP;
    CLOSE c_it;

    -- as páginas que ficaram sozinhas saem dos grupos e vão para a fase 2b
    TRUNCATE TABLE fc_wrk_pc_solo;
    INSERT INTO fc_wrk_pc_solo (ordem, pagina, modelo, f, apoio)
    SELECT b.bin, i.pagina, i.modelo, i.f, i.apoio
    FROM fc_wrk_pc_bin b JOIN fc_wrk_pc_bin_item i ON i.bin = b.bin
    WHERE b.n = 1;
    DELETE i FROM fc_wrk_pc_bin_item i JOIN fc_wrk_pc_bin b ON b.bin = i.bin WHERE b.n = 1;
    DELETE FROM fc_wrk_pc_bin WHERE n = 1;
  END;

  BEGIN
    DECLARE v_fim2 INT DEFAULT 0;
    DECLARE v_bin INT;
    DECLARE v_nb, v_nb1 INT;
    DECLARE v_ip VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_im VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_if DECIMAL(6,1);
    DECLARE v_ia TINYINT;
    DECLARE c_so CURSOR FOR SELECT pagina, modelo, f, apoio FROM fc_wrk_pc_solo ORDER BY f DESC, ordem;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim2 = 1;

    SELECT COALESCE(MAX(bin), 0) INTO v_nb FROM fc_wrk_pc_bin;
    SET v_nb = GREATEST(v_nb, (SELECT COALESCE(MAX(ordem), 0) FROM fc_wrk_pc_solo)), v_nb1 = v_nb;

    -- 2b. Modelos diferentes: só entre os grupos novos desta fase
    OPEN c_so;
    sos: LOOP
      FETCH c_so INTO v_ip, v_im, v_if, v_ia;
      IF v_fim2 = 1 THEN LEAVE sos; END IF;

      SET v_bin = NULL;
      SELECT b.bin INTO v_bin
      FROM fc_wrk_pc_bin b
      WHERE b.bin > v_nb1
        AND b.n < IF(b.modelo_unico = v_im, v_maxp, v_maxd)
        AND NOT (v_ia = 1 AND b.apoio = 1)
        AND b.soma + v_if <= v_lp * (1 - IF(b.modelo_unico = v_im, v_s1, v_s2) * b.n)
      ORDER BY EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.modelo = v_im) DESC,
               v_lp * (1 - IF(b.modelo_unico = v_im, v_s1, v_s2) * b.n) - (b.soma + v_if),
               b.bin
      LIMIT 1;
      SET v_fim2 = 0;

      IF v_bin IS NULL THEN
        SET v_nb = v_nb + 1;
        INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira) VALUES (v_nb, 1, v_if, v_ia, v_im, v_ip);
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, f, apoio) VALUES (v_nb, 1, v_ip, v_im, v_if, v_ia);
      ELSE
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, f, apoio)
        SELECT v_bin, n + 1, v_ip, v_im, v_if, v_ia FROM fc_wrk_pc_bin WHERE bin = v_bin;
        UPDATE fc_wrk_pc_bin
           SET n = n + 1, soma = soma + v_if, apoio = GREATEST(apoio, v_ia),
               modelo_unico = IF(modelo_unico = v_im, v_im, NULL)
         WHERE bin = v_bin;
      END IF;
    END LOOP;
    CLOSE c_so;
  END;

  -- 3. Cada grupo vira um posto (numerados em sequência depois dos postos próprios)
  INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                         carga, capacidade, no_teto, exclusiva, excedente)
  SELECT p_data, p_cart, p_tur, v_n + r.rn, p_un,
         CASE WHEN b.apoio = 1 THEN IF(b.n = 1, 'assistente', 'apoio') ELSE IF(b.n = 1, 'titular', 'double') END,
         b.primeira, b.n, b.soma,
         ROUND(v_lp * (1 - IF(b.modelo_unico IS NOT NULL, v_s1, v_s2) * (b.n - 1)), 1), 0, 0, NULL
  FROM fc_wrk_pc_bin b
  JOIN (SELECT bin, ROW_NUMBER() OVER (ORDER BY bin) AS rn FROM fc_wrk_pc_bin) r ON r.bin = b.bin;

  INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
  SELECT p_data, p_cart, p_tur, v_n + r.rn, i.pagina, i.f, i.apoio, 1 / b.n
  FROM fc_wrk_pc_bin_item i
  JOIN fc_wrk_pc_bin b ON b.bin = i.bin
  JOIN (SELECT bin, ROW_NUMBER() OVER (ORDER BY bin) AS rn FROM fc_wrk_pc_bin) r ON r.bin = b.bin;
END $$

-- ---------------------------------------------------------------------
-- Cálculo do dia: carga das páginas, postos de todas as carteiras e necessidade
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_postos $$
CREATE PROCEDURE sp_fc_postos(IN p_data DATE)
BEGIN
  DECLARE v_jan, v_jb, v_jp, v_nuc, v_nfolga, v_bmin INT;
  DECLARE v_def, v_min, v_fator, v_paf, v_pp, v_tp, v_lp, v_vol DECIMAL(10,4);
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
         COALESCE(MAX(IF(chave = 'fator_volume', valor, NULL)), 1)
    INTO v_jan, v_jb, v_jp, v_def, v_min, v_fator, v_paf, v_pp, v_tp, v_lp, v_nuc, v_nfolga, v_bmin, v_vol
  FROM fc_parametros;

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
        LEFT JOIN (SELECT pp.pagina, pp.turno, SUM(p.capacidade * pp.fans_h / NULLIF(p.carga, 0)) AS cap
                   FROM fc_postos_pagina pp
                   JOIN fc_postos p ON p.data_calculo = pp.data_calculo AND p.carteira = pp.carteira
                                   AND p.turno = pp.turno AND p.posto = pp.posto
                   WHERE pp.data_calculo = p_data
                   GROUP BY pp.pagina, pp.turno) cp
               ON cp.pagina = pc.pagina AND cp.turno = pc.turno
        WHERE pc.data_calculo = p_data
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

-- ---------------------------------------------------------------------
-- Orquestração diária: igual à de 19/10, com a etapa 'postos' no fim
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
  UPDATE fc_log_execucao SET etapa = 'postos' WHERE id = v_log;
  CALL sp_fc_postos(v_d);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

DELIMITER ;
