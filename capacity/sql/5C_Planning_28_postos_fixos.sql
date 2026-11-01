-- =====================================================================
-- 5C | Forecast de WFM — 28. Postos fixos: o Capacity monta exatamente a distribuição combinada
-- Schema: 5c_planning        01/11/2026        Rodar depois do ES_64 do 5C Escalas.
--
-- Decisão do usuário em 01/11: o Capacity tem que fazer EXATAMENTE a distribuição de postos
-- combinada (ES_61 a ES_64): páginas < US$ 16 mil em postos de 3 ou mais, < US$ 32 mil em postos de
-- 2 ou mais, mesma agência junta (Independent e Lolly Agency = 5C Company), Savannah com a Ivy Vale
-- (Fanvue), OFTV < US$ 8 mil de apoio, C1 ≈ 60% e C2 ≈ 40% do faturamento de cada unidade com o
-- mesmo número de postos (a C1 pode ter 1 a mais), e posto = 1 pessoa, pela maior necessidade do
-- dia (a página acima de 35 fãs/h em qualquer turno ocupa 2 postos em todos os turnos).
--   1. Tabela fc_posto_fixo: carteira, grupo (posto), páginas e quantas pessoas o grupo ocupa.
--   2. sp_fc_postos_carteira (o do script 23) monta primeiro os grupos fixos da carteira, exatamente
--      como estão; só a página que não está na tabela (página nova, por exemplo) segue a regra antiga.
--      Página fixa que mudar de carteira em fc_pagina_carteira também volta para a regra antiga.
--   3. A estrutura nova passa a valer no cálculo de 01/11 (para o "Gerar" de hoje montar a semana de 02/11).
--   4. Grava a distribuição de 01/11 e recalcula.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- Desfazer: UPDATE fc_posto_fixo SET vigente_ate = <dia> WHERE vigente_ate IS NULL; e recalcular
-- (sem linhas vigentes o procedimento faz o mesmo que o do script 23).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Tabela
CREATE TABLE IF NOT EXISTS fc_posto_fixo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  carteira VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  grupo SMALLINT NOT NULL COMMENT 'posto dentro da carteira (1 = maior faturamento)',
  seq TINYINT NOT NULL COMMENT 'ordem da página no grupo (a 1ª não apoio é a principal)',
  pagina VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  pessoas TINYINT NOT NULL DEFAULT 1 COMMENT 'postos (pessoas) que o grupo ocupa em todos os turnos',
  apoio TINYINT NOT NULL DEFAULT 0 COMMENT '1 = OFTV desconsiderada, entra sem carga',
  agencia VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  vigente_desde DATE NOT NULL,
  vigente_ate DATE NULL,
  obs VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fixo_cart (carteira, vigente_desde),
  KEY ix_fixo_pag (pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Procedimento
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_fc_postos_carteira $$
CREATE PROCEDURE sp_fc_postos_carteira(IN p_data DATE, IN p_cart VARCHAR(8), IN p_un TINYINT, IN p_tur CHAR(1))
BEGIN
  DECLARE v_lp, v_comp, v_lk, v_ls, v_teto, v_s1, v_s2, v_alivio DECIMAL(10,4);
  DECLARE v_kmin, v_afmin, v_maxp, v_maxd INT;
  DECLARE v_fim INT DEFAULT 0;
  DECLARE v_pag VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_mod VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_regra VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_F, v_O, v_cap DECIMAL(10,4);
  DECLARE v_k, v_i INT;
  DECLARE v_n INT DEFAULT 0;
  DECLARE v_excl, v_cls TINYINT;
  DECLARE v_ag INT;
  DECLARE v_ref DATE;
  DECLARE c_pag CURSOR FOR
    SELECT pagina, modelo, regra, GREATEST(fans_h, 1), COALESCE(classe_receita, 0), agencia
    FROM fc_pagina_carga
    WHERE data_calculo = p_data AND carteira = p_cart AND turno = p_tur AND ocupa_posto = 1
      -- página com posto fixo (fc_posto_fixo, script 28) já foi montada antes e fica de fora
      AND NOT EXISTS (SELECT 1 FROM fc_posto_fixo x
                      WHERE x.carteira = p_cart AND x.pagina = fc_pagina_carga.pagina
                        AND x.vigente_desde <= v_ref AND (x.vigente_ate IS NULL OR x.vigente_ate > v_ref))
    ORDER BY ordem, pagina;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

  SELECT COALESCE(MAX(IF(chave = 'limite_padrao', valor, NULL)), 35),
         COALESCE(MAX(IF(chave = 'limite_compartilhada', valor, NULL)), 28),
         COALESCE(MAX(IF(chave = 'limite_skylar', valor, NULL)), 40),
         COALESCE(MAX(IF(chave = 'limite_sla80', valor, NULL)), 20),
         COALESCE(MAX(IF(chave = 'teto_apoio', valor, NULL)), 40),
         COALESCE(MAX(IF(chave = 'desconto_mesma_modelo', valor, NULL)), 0.10),
         COALESCE(MAX(IF(chave = 'desconto_modelo_diferente', valor, NULL)), 0.20),
         COALESCE(MAX(IF(chave = 'alivio_mass_ppv', valor, NULL)), 0),
         CAST(COALESCE(MAX(IF(chave = 'skylar_minimo', valor, NULL)), 4) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'assistente_fixo_minimo', valor, NULL)), 2) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'max_paginas_rep', valor, NULL)), 3) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'max_paginas_rep_modelos_diferentes', valor, NULL)), 2) AS SIGNED)
    INTO v_lp, v_comp, v_lk, v_ls, v_teto, v_s1, v_s2, v_alivio, v_kmin, v_afmin, v_maxp, v_maxd
  FROM fc_parametros;
  -- a Mass PPV (.21/.22) passa para os supervisores: o rep atende um pouco mais de fãs por hora
  SET v_lp = v_lp * (1 + v_alivio), v_comp = v_comp * (1 + v_alivio);

  TRUNCATE TABLE fc_wrk_pc_item;
  TRUNCATE TABLE fc_wrk_pc_bin;
  TRUNCATE TABLE fc_wrk_pc_bin_item;
  TRUNCATE TABLE fc_wrk_pc_forc;

  -- 0. Postos fixos (script 28, decisão de 01/11): a distribuição combinada com o Planejamento vale
  --    exatamente como está em fc_posto_fixo. Cada grupo vira "pessoas" postos iguais (o 1º titular, ou
  --    double se tem mais de uma página; os outros assistentes), com a carga dividida entre eles. A OFTV
  --    desconsiderada entra de apoio, sem carga. Só a página que não está na tabela segue a regra abaixo.
  SELECT GREATEST(p_data, COALESCE(MIN(vigente_desde), p_data)) INTO v_ref FROM fc_pagina_carteira;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo;
  CREATE TEMPORARY TABLE tmp_fc_fixo AS
  SELECT x.grupo, x.seq, x.pagina, x.pessoas,
         IF(x.apoio = 1 OR pc.desconsiderada = 1, 1, 0) AS apoio,
         IF(x.apoio = 1 OR pc.desconsiderada = 1, 0, pc.fans_h) AS fans
  FROM fc_posto_fixo x
  JOIN fc_pagina_carga pc ON pc.data_calculo = p_data AND pc.carteira = p_cart AND pc.turno = p_tur AND pc.pagina = x.pagina
  WHERE x.carteira = p_cart AND x.vigente_desde <= v_ref AND (x.vigente_ate IS NULL OR x.vigente_ate > v_ref);
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo_g;
  CREATE TEMPORARY TABLE tmp_fc_fixo_g AS
  SELECT f.grupo, MAX(f.pessoas) AS k, SUM(f.fans) AS carga, GREATEST(SUM(1 - f.apoio), 1) AS n,
         SUBSTRING_INDEX(GROUP_CONCAT(IF(f.apoio = 0, f.pagina, NULL) ORDER BY f.seq SEPARATOR '|'), '|', 1) AS primeira
  FROM tmp_fc_fixo f GROUP BY f.grupo;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo_p;
  CREATE TEMPORARY TABLE tmp_fc_fixo_p AS
  SELECT g.grupo, i.i, ROW_NUMBER() OVER (ORDER BY g.grupo, i.i) AS posto, g.k, g.carga, g.n, g.primeira
  FROM tmp_fc_fixo_g g
  JOIN (SELECT 1 AS i UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) i ON i.i <= g.k;
  INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                         carga, capacidade, no_teto, exclusiva, excedente, forcado)
  SELECT p_data, p_cart, p_tur, p.posto, p_un,
         IF(p.i = 1, IF(p.n = 1, 'titular', 'double'), 'assistente'), p.primeira, p.n,
         ROUND(p.carga / p.k, 1), v_lp, ROUND(p.carga / p.k, 1) > v_lp, 0, NULL, IF(p.n > 1, 1, 0)
  FROM tmp_fc_fixo_p p;
  INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
  SELECT p_data, p_cart, p_tur, p.posto, f.pagina, ROUND(f.fans / p.k, 1), f.apoio, IF(f.apoio = 1, 0, 1 / p.n)
  FROM tmp_fc_fixo_p p JOIN tmp_fc_fixo f ON f.grupo = p.grupo;
  SELECT COALESCE(MAX(posto), 0) INTO v_n FROM tmp_fc_fixo_p;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo_g;
  DROP TEMPORARY TABLE IF EXISTS tmp_fc_fixo_p;

  -- 1. Páginas com posto próprio; as pequenas (e os excedentes) vão para fc_wrk_pc_item;
  --    as da regra de faturamento vão para fc_wrk_pc_forc
  OPEN c_pag;
  pags: LOOP
    FETCH c_pag INTO v_pag, v_mod, v_regra, v_F, v_cls, v_ag;
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
                               carga, capacidade, no_teto, exclusiva, excedente, forcado)
        VALUES (p_data, p_cart, p_tur, v_n, p_un, IF(v_i = 1, 'titular', 'assistente'), v_pag, 1,
                ROUND(v_F / v_k, 1), v_cap, 0, v_excl, NULL, 0);
        INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
        VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_F / v_k, 1), 0, 1);
        SET v_i = v_i + 1;
      END WHILE;

    ELSEIF v_cls > 0 THEN
      -- faturamento do mês anterior abaixo de 32k (2 por rep) ou de 16k (3 por rep), mesmo acima da capacidade
      INSERT INTO fc_wrk_pc_forc (pagina, modelo, agencia, f, classe) VALUES (v_pag, v_mod, v_ag, ROUND(v_F, 1), v_cls);

    ELSEIF v_F > v_teto THEN
      -- titular fica com 35; o excedente vira apoio ou assistente(s)
      SET v_O = v_F - v_lp;
      SET v_n = v_n + 1;
      INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                             carga, capacidade, no_teto, exclusiva, excedente, forcado)
      VALUES (p_data, p_cart, p_tur, v_n, p_un, 'titular', v_pag, 1, v_lp, v_lp, 0, 0, ROUND(v_O, 1), 0);
      INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
      VALUES (p_data, p_cart, p_tur, v_n, v_pag, v_lp, 0, 1);
      IF v_O > v_comp THEN
        SET v_k = CEIL(v_O / v_lp), v_i = 1;
        WHILE v_i <= v_k DO
          SET v_n = v_n + 1;
          INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                                 carga, capacidade, no_teto, exclusiva, excedente, forcado)
          VALUES (p_data, p_cart, p_tur, v_n, p_un, 'assistente', v_pag, 1, ROUND(v_O / v_k, 1), v_lp, 0, 0, NULL, 0);
          INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
          VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_O / v_k, 1), 0, 1);
          SET v_i = v_i + 1;
        END WHILE;
      ELSE
        INSERT INTO fc_wrk_pc_item (pagina, modelo, agencia, f, apoio) VALUES (v_pag, v_mod, v_ag, ROUND(v_O, 1), 1);
      END IF;

    ELSEIF v_F > v_comp OR v_regra = 'EXCLUSIVA' THEN
      -- rep próprio (no teto entre 35 e 40)
      SET v_n = v_n + 1;
      INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                             carga, capacidade, no_teto, exclusiva, excedente, forcado)
      VALUES (p_data, p_cart, p_tur, v_n, p_un, 'titular', v_pag, 1, ROUND(v_F, 1), v_lp,
              v_F > v_lp, v_regra = 'EXCLUSIVA', NULL, 0);
      INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
      VALUES (p_data, p_cart, p_tur, v_n, v_pag, ROUND(v_F, 1), 0, 1);

    ELSE
      INSERT INTO fc_wrk_pc_item (pagina, modelo, agencia, f, apoio) VALUES (v_pag, v_mod, v_ag, ROUND(v_F, 1), 0);
    END IF;
  END LOOP;
  CLOSE c_pag;

  -- 2. Regra de faturamento (decisão de 31/10): abaixo de 16k, grupos de 3; de 16k a 32k, grupos de 2.
  --    Os grupos são montados antes e não recebem páginas de fora da regra. Em cada grupo, primeiro a mesma
  --    modelo, depois a mesma agência do Infloww, depois o grupo mais leve (a carga fica dividida por igual).
  --    As vagas dos grupos de 3 são completadas com páginas de 16k a 32k (as mais leves). As que ficam sem par
  --    voltam para o agrupamento normal (com o limite de carga).
  BEGIN
    DECLARE v_fim3 INT DEFAULT 0;
    DECLARE v_nb, v_bin, v_q, v_id INT;
    DECLARE v_fp VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_fm VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_fa INT;
    DECLARE v_ff DECIMAL(6,1);
    DECLARE c_f3 CURSOR FOR SELECT id, pagina, modelo, agencia, f FROM fc_wrk_pc_forc WHERE classe = 3 ORDER BY f DESC, pagina;
    DECLARE c_f2 CURSOR FOR SELECT id, pagina, modelo, agencia, f FROM fc_wrk_pc_forc WHERE classe = 2 AND usado = 0 ORDER BY f DESC, pagina;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim3 = 1;

    SET v_nb = 0;
    -- 2.1 grupos de 3
    SELECT CEIL(COUNT(*) / 3) INTO v_q FROM fc_wrk_pc_forc WHERE classe = 3;
    WHILE v_q > 0 DO
      SET v_nb = v_nb + 1, v_q = v_q - 1;
      INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira, forcado, classe) VALUES (v_nb, 0, 0, 0, NULL, '', 1, 3);
    END WHILE;
    OPEN c_f3;
    f3: LOOP
      FETCH c_f3 INTO v_id, v_fp, v_fm, v_fa, v_ff;
      IF v_fim3 = 1 THEN LEAVE f3; END IF;
      SELECT b.bin INTO v_bin FROM fc_wrk_pc_bin b
      WHERE b.forcado = 1 AND b.classe = 3 AND b.n < 3
      ORDER BY EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.modelo = v_fm) DESC,
               (v_fa IS NOT NULL AND EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.agencia = v_fa)) DESC,
               b.soma, b.bin
      LIMIT 1;
      INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio)
      SELECT v_bin, n + 1, v_fp, v_fm, v_fa, v_ff, 0 FROM fc_wrk_pc_bin WHERE bin = v_bin;
      UPDATE fc_wrk_pc_bin SET primeira = IF(n = 0, v_fp, primeira), modelo_unico = IF(n = 0, v_fm, IF(modelo_unico = v_fm, v_fm, NULL)),
                               n = n + 1, soma = soma + v_ff WHERE bin = v_bin;
      UPDATE fc_wrk_pc_forc SET usado = 1 WHERE id = v_id;
    END LOOP;
    CLOSE c_f3;
    SET v_fim3 = 0;

    -- 2.2 vagas dos grupos de 3 com as páginas de 16k a 32k mais leves
    vagas: WHILE EXISTS (SELECT 1 FROM fc_wrk_pc_bin WHERE forcado = 1 AND classe = 3 AND n < 3)
             AND EXISTS (SELECT 1 FROM fc_wrk_pc_forc WHERE classe = 2 AND usado = 0) DO
      SELECT bin INTO v_bin FROM fc_wrk_pc_bin WHERE forcado = 1 AND classe = 3 AND n < 3 ORDER BY soma, bin LIMIT 1;
      SELECT g.id, g.pagina, g.modelo, g.agencia, g.f INTO v_id, v_fp, v_fm, v_fa, v_ff
      FROM fc_wrk_pc_forc g
      WHERE g.classe = 2 AND g.usado = 0
      ORDER BY EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = v_bin AND x.modelo = g.modelo) DESC,
               (g.agencia IS NOT NULL AND EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = v_bin AND x.agencia = g.agencia)) DESC,
               g.f, g.pagina
      LIMIT 1;
      INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio)
      SELECT v_bin, n + 1, v_fp, v_fm, v_fa, v_ff, 0 FROM fc_wrk_pc_bin WHERE bin = v_bin;
      UPDATE fc_wrk_pc_bin SET modelo_unico = IF(modelo_unico = v_fm, v_fm, NULL), n = n + 1, soma = soma + v_ff WHERE bin = v_bin;
      UPDATE fc_wrk_pc_forc SET usado = 1 WHERE id = v_id;
    END WHILE vagas;

    -- 2.3 grupos de 2 com as páginas de 16k a 32k que sobraram
    SELECT CEIL(COUNT(*) / 2) INTO v_q FROM fc_wrk_pc_forc WHERE classe = 2 AND usado = 0;
    WHILE v_q > 0 DO
      SET v_nb = v_nb + 1, v_q = v_q - 1;
      INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira, forcado, classe) VALUES (v_nb, 0, 0, 0, NULL, '', 1, 2);
    END WHILE;
    OPEN c_f2;
    f2: LOOP
      FETCH c_f2 INTO v_id, v_fp, v_fm, v_fa, v_ff;
      IF v_fim3 = 1 THEN LEAVE f2; END IF;
      SELECT b.bin INTO v_bin FROM fc_wrk_pc_bin b
      WHERE b.forcado = 1 AND b.classe = 2 AND b.n < 2
      ORDER BY EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.modelo = v_fm) DESC,
               (v_fa IS NOT NULL AND EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.agencia = v_fa)) DESC,
               b.soma, b.bin
      LIMIT 1;
      INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio)
      SELECT v_bin, n + 1, v_fp, v_fm, v_fa, v_ff, 0 FROM fc_wrk_pc_bin WHERE bin = v_bin;
      UPDATE fc_wrk_pc_bin SET primeira = IF(n = 0, v_fp, primeira), modelo_unico = IF(n = 0, v_fm, IF(modelo_unico = v_fm, v_fm, NULL)),
                               n = n + 1, soma = soma + v_ff WHERE bin = v_bin;
      UPDATE fc_wrk_pc_forc SET usado = 1 WHERE id = v_id;
    END LOOP;
    CLOSE c_f2;
    SET v_fim3 = 0;

    -- 2.4 as que ficaram sozinhas voltam para o agrupamento normal; grupos vazios saem
    INSERT INTO fc_wrk_pc_item (pagina, modelo, agencia, f, apoio)
    SELECT i.pagina, i.modelo, i.agencia, i.f, 0
    FROM fc_wrk_pc_bin b JOIN fc_wrk_pc_bin_item i ON i.bin = b.bin
    WHERE b.forcado = 1 AND b.n = 1
    ORDER BY b.bin;
    DELETE i FROM fc_wrk_pc_bin_item i JOIN fc_wrk_pc_bin b ON b.bin = i.bin WHERE b.forcado = 1 AND b.n = 1;
    DELETE FROM fc_wrk_pc_bin WHERE forcado = 1 AND n <= 1;
  END;

  -- 3. Agrupamento das outras páginas pequenas, em duas fases (decisão de 28/10):
  --    3a. primeiro só páginas da mesma modelo (até max_paginas_rep, capacidade 35 × (1 − 10% × (n − 1)));
  --    3b. as que ficaram sozinhas são combinadas entre modelos (até max_paginas_rep_modelos_diferentes),
  --        preferindo a mesma modelo e depois a mesma agência do Infloww (decisão de 31/10).
  --    Em cada fase: da maior para a menor; o grupo que cabe e fica mais justo.
  BEGIN
    DECLARE v_fim2 INT DEFAULT 0;
    DECLARE v_id, v_bin INT;
    DECLARE v_nb INT;
    DECLARE v_ip VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_im VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_iag INT;
    DECLARE v_if DECIMAL(6,1);
    DECLARE v_ia TINYINT;
    DECLARE c_it CURSOR FOR SELECT id, pagina, modelo, agencia, f, apoio FROM fc_wrk_pc_item ORDER BY f DESC, id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim2 = 1;

    SELECT COALESCE(MAX(bin), 0) INTO v_nb FROM fc_wrk_pc_bin;

    -- 3a. Mesma modelo
    OPEN c_it;
    its: LOOP
      FETCH c_it INTO v_id, v_ip, v_im, v_iag, v_if, v_ia;
      IF v_fim2 = 1 THEN LEAVE its; END IF;

      SET v_bin = NULL;
      SELECT b.bin INTO v_bin
      FROM fc_wrk_pc_bin b
      WHERE b.forcado = 0
        AND b.modelo_unico = v_im
        AND b.n < v_maxp
        AND NOT (v_ia = 1 AND b.apoio = 1)
        AND b.soma + v_if <= v_lp * (1 - v_s1 * b.n)
      ORDER BY v_lp * (1 - v_s1 * b.n) - (b.soma + v_if), b.bin
      LIMIT 1;
      SET v_fim2 = 0;   -- a busca sem resultado não é o fim do cursor

      IF v_bin IS NULL THEN
        SET v_nb = v_nb + 1;
        INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira, forcado, classe) VALUES (v_nb, 1, v_if, v_ia, v_im, v_ip, 0, 0);
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio) VALUES (v_nb, 1, v_ip, v_im, v_iag, v_if, v_ia);
      ELSE
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio)
        SELECT v_bin, n + 1, v_ip, v_im, v_iag, v_if, v_ia FROM fc_wrk_pc_bin WHERE bin = v_bin;
        UPDATE fc_wrk_pc_bin SET n = n + 1, soma = soma + v_if, apoio = GREATEST(apoio, v_ia) WHERE bin = v_bin;
      END IF;
    END LOOP;
    CLOSE c_it;

    -- as páginas que ficaram sozinhas saem dos grupos e vão para a fase 3b
    TRUNCATE TABLE fc_wrk_pc_solo;
    INSERT INTO fc_wrk_pc_solo (ordem, pagina, modelo, agencia, f, apoio)
    SELECT b.bin, i.pagina, i.modelo, i.agencia, i.f, i.apoio
    FROM fc_wrk_pc_bin b JOIN fc_wrk_pc_bin_item i ON i.bin = b.bin
    WHERE b.forcado = 0 AND b.n = 1;
    DELETE i FROM fc_wrk_pc_bin_item i JOIN fc_wrk_pc_bin b ON b.bin = i.bin WHERE b.forcado = 0 AND b.n = 1;
    DELETE FROM fc_wrk_pc_bin WHERE forcado = 0 AND n = 1;
  END;

  BEGIN
    DECLARE v_fim2 INT DEFAULT 0;
    DECLARE v_bin INT;
    DECLARE v_nb, v_nb1 INT;
    DECLARE v_ip VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_im VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_iag INT;
    DECLARE v_if DECIMAL(6,1);
    DECLARE v_ia TINYINT;
    DECLARE c_so CURSOR FOR SELECT pagina, modelo, agencia, f, apoio FROM fc_wrk_pc_solo ORDER BY f DESC, ordem;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim2 = 1;

    SELECT COALESCE(MAX(bin), 0) INTO v_nb FROM fc_wrk_pc_bin;
    SET v_nb = GREATEST(v_nb, (SELECT COALESCE(MAX(ordem), 0) FROM fc_wrk_pc_solo)), v_nb1 = v_nb;

    -- 3b. Modelos diferentes: só entre os grupos novos desta fase
    OPEN c_so;
    sos: LOOP
      FETCH c_so INTO v_ip, v_im, v_iag, v_if, v_ia;
      IF v_fim2 = 1 THEN LEAVE sos; END IF;

      SET v_bin = NULL;
      SELECT b.bin INTO v_bin
      FROM fc_wrk_pc_bin b
      WHERE b.bin > v_nb1
        AND b.n < IF(b.modelo_unico = v_im, v_maxp, v_maxd)
        AND NOT (v_ia = 1 AND b.apoio = 1)
        AND b.soma + v_if <= v_lp * (1 - IF(b.modelo_unico = v_im, v_s1, v_s2) * b.n)
      ORDER BY EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.modelo = v_im) DESC,
               (v_iag IS NOT NULL AND EXISTS (SELECT 1 FROM fc_wrk_pc_bin_item x WHERE x.bin = b.bin AND x.agencia = v_iag)) DESC,
               v_lp * (1 - IF(b.modelo_unico = v_im, v_s1, v_s2) * b.n) - (b.soma + v_if),
               b.bin
      LIMIT 1;
      SET v_fim2 = 0;

      IF v_bin IS NULL THEN
        SET v_nb = v_nb + 1;
        INSERT INTO fc_wrk_pc_bin (bin, n, soma, apoio, modelo_unico, primeira, forcado, classe) VALUES (v_nb, 1, v_if, v_ia, v_im, v_ip, 0, 0);
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio) VALUES (v_nb, 1, v_ip, v_im, v_iag, v_if, v_ia);
      ELSE
        INSERT INTO fc_wrk_pc_bin_item (bin, seq, pagina, modelo, agencia, f, apoio)
        SELECT v_bin, n + 1, v_ip, v_im, v_iag, v_if, v_ia FROM fc_wrk_pc_bin WHERE bin = v_bin;
        UPDATE fc_wrk_pc_bin
           SET n = n + 1, soma = soma + v_if, apoio = GREATEST(apoio, v_ia),
               modelo_unico = IF(modelo_unico = v_im, v_im, NULL)
         WHERE bin = v_bin;
      END IF;
    END LOOP;
    CLOSE c_so;
  END;

  -- 4. Cada grupo vira um posto (numerados em sequência depois dos postos próprios)
  INSERT INTO fc_postos (data_calculo, carteira, turno, posto, unidade, tipo, pagina_principal, n_paginas,
                         carga, capacidade, no_teto, exclusiva, excedente, forcado)
  SELECT p_data, p_cart, p_tur, v_n + r.rn, p_un,
         CASE WHEN b.apoio = 1 THEN IF(b.n = 1, 'assistente', 'apoio') ELSE IF(b.n = 1, 'titular', 'double') END,
         b.primeira, b.n, b.soma,
         ROUND(v_lp * (1 - IF(b.modelo_unico IS NOT NULL, v_s1, v_s2) * (b.n - 1)), 1), 0, 0, NULL, b.forcado
  FROM fc_wrk_pc_bin b
  JOIN (SELECT bin, ROW_NUMBER() OVER (ORDER BY bin) AS rn FROM fc_wrk_pc_bin) r ON r.bin = b.bin;

  INSERT INTO fc_postos_pagina (data_calculo, carteira, turno, posto, pagina, fans_h, apoio, fracao)
  SELECT p_data, p_cart, p_tur, v_n + r.rn, i.pagina, i.f, i.apoio, 1 / b.n
  FROM fc_wrk_pc_bin_item i
  JOIN fc_wrk_pc_bin b ON b.bin = i.bin
  JOIN (SELECT bin, ROW_NUMBER() OVER (ORDER BY bin) AS rn FROM fc_wrk_pc_bin) r ON r.bin = b.bin;
END $$

DELIMITER ;

-- 3. Vigência: as carteiras novas (ES_51, ES_61, ES_62, ES_64) começavam em 02/11, mas o Capacity
--    usa a estrutura do dia do cálculo, e o "Gerar" do site calcula com a data de hoje e muda a escala
--    a partir de amanhã. Gerando em 01/11 para a semana de 02/11, a estrutura nova tem que valer no
--    cálculo de 01/11: as linhas que começavam em 02/11 passam a começar em 01/11 e as que fechavam
--    em 02/11 fecham em 01/11. A escala de 01/11 já postada não muda (o site só mexe de amanhã em diante).
--    A chave da tabela é página + início, e as linhas antigas também começavam em 01/11: a linha de
--    01/11 recebe a carteira nova (o obs guarda a antiga) e a de 02/11 sai. (Em 01/11 o 1º UPDATE
--    rodou pelo Navicat e o resto pelo MCP.)
UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-01' WHERE vigente_ate = '2026-11-02';
UPDATE fc_pagina_carteira o
  JOIN fc_pagina_carteira n ON n.pagina = o.pagina AND n.vigente_desde = '2026-11-02' AND n.vigente_ate IS NULL
   SET o.vigente_ate = NULL, o.obs = LEFT(CONCAT(n.obs, ' · antes ', o.carteira, ' (script 28)'), 255),
       o.carteira = n.carteira, o.modelo = n.modelo, o.regra = n.regra, o.ordem = n.ordem, o.fas_h_estimado = n.fas_h_estimado
 WHERE o.vigente_desde = '2026-11-01' AND o.vigente_ate = '2026-11-01';
DELETE FROM fc_pagina_carteira
 WHERE vigente_desde = '2026-11-02' AND vigente_ate IS NULL
   AND (pagina, carteira) IN (SELECT pagina, carteira FROM (SELECT pagina, carteira FROM fc_pagina_carteira WHERE vigente_desde = '2026-11-01' AND vigente_ate IS NULL) x);
UPDATE fc_pagina_carteira SET vigente_desde = '2026-11-01' WHERE vigente_desde = '2026-11-02' AND vigente_ate IS NULL;

-- 4. Distribuição de 01/11 (pode rodar de novo: troca a de 01/11)
DELETE FROM fc_posto_fixo WHERE vigente_desde = '2026-11-01';
INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, obs) VALUES
  ('U1C1', 1, 1, 'Mikayla Free', 2, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 2, 1, 'Mikayla Paid', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 3, 1, 'Rocket Reyna VIP', 2, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 3, 2, 'Rocket Reyna OFTV', 2, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 4, 1, 'Levi Paid', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 4, 2, 'Levi Free', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 4, 3, 'Sumner', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 4, 4, 'Taylor Ballard', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 5, 1, 'Silvia Saige VIP', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 5, 2, 'Silvia Saige Free', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 6, 1, 'Diana Rider', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 6, 2, 'zOFTV Diana Rider', 1, 1, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 7, 1, 'Courtney', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 8, 1, 'Isla', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 8, 2, 'Tiny', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 8, 3, 'Mia Melons', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 9, 1, 'Becky Recme', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 10, 1, 'Coco Bunnie 260m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C1', 10, 2, 'Charlie 306m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 1, 1, 'Sisi Rose', 2, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 2, 1, 'Holly Jane', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 3, 1, 'Rebecca', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 3, 2, 'Rebecca OFTV', 1, 1, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 4, 1, 'Natasha Nice VIP', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 1, 'Thalia 147m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 2, 'Mrs Robinson 303m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 3, 'Jena Wolfy VIP 001m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 4, 'Jena Wolfy Free 001s', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 5, 'Thalia 147t', 1, 1, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 5, 6, 'Jena Wolfy OFTV 001t', 1, 1, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 6, 1, 'Tiff 176m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 7, 1, 'Livinia Roberts OFTV', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 7, 2, 'Charly', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 8, 1, 'Krystal 141m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 8, 2, 'Krystal 141t', 1, 1, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 9, 1, 'Angel', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 10, 1, 'Maya', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 10, 2, 'Blaire', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U1C2', 10, 3, 'Dakota', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 1, 1, 'Nicole Doshi', 2, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 1, 2, 'Nicole OFTV', 2, 1, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 2, 1, 'Midwest Emma Free 221s', 2, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 3, 1, 'Marie Dee VIP', 1, 0, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 4, 1, 'Molly Stewart VIP', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 4, 2, 'zOFTV Molly Stewart', 1, 1, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 5, 1, 'Ariana Marie', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 5, 2, 'Yuni Yung', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 6, 1, 'CJ VIP', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 7, 1, 'Alix Lynx', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 8, 1, 'CJ Miles', 2, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 9, 1, 'Blair VIP 155m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C1', 9, 2, 'Blair OFTV 155t', 1, 1, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 1, 1, 'Brandi Love', 1, 0, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 2, 1, 'Mo Saffari', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 3, 1, 'Brandi Love FREE', 1, 0, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 4, 1, 'Sophie Sierra', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 4, 2, 'Apex VIP', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 4, 3, 'Apex Free', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 5, 1, 'Jay', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 5, 2, 'Sebi Barraza', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 6, 1, 'Sean Adams', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 7, 1, 'Sarah H 329m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 7, 2, 'Victorya 188m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 8, 1, 'Matty Caron', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 9, 1, 'Draya', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 9, 2, 'Keera', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 10, 1, 'Aline Novak', 1, 0, '5C Company · Privacy', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U2C2', 11, 1, 'Ellie D', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 1, 1, 'Cynthia', 2, 0, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 2, 1, 'Elly Clutch 100m', 2, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 3, 1, 'Liv', 2, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 4, 1, 'Britt VIP', 1, 0, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 4, 2, 'Britt OFTV', 1, 1, 'Elysium', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 5, 1, 'Ms Red VIP', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 5, 2, 'Ms Red Free', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 6, 1, 'Janet VIP', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 6, 2, 'Janet Free', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 7, 1, 'Aria', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 7, 2, 'Kaylin', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 7, 3, 'Kas', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 8, 1, 'Bianca', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 8, 2, 'Alexis Clark', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C1', 8, 3, 'Liam', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 1, 1, 'Madison Moore VIP 115m', 2, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 1, 2, 'Madison Moore Free 115s', 2, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 2, 1, 'Ivy Vale', 1, 0, 'Fanvue', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 2, 2, 'Savannah', 1, 0, 'Fanvue', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 3, 1, 'Mario Adrion', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 3, 2, 'Blake', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 3, 3, 'Maddie', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 4, 1, 'Jessie', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 5, 1, 'Lexis Star', 1, 0, 'Timeless', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 6, 1, 'Melody', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 6, 2, 'Abg', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 6, 3, 'Mandi CAT', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 6, 4, 'MandiCat OFTV', 1, 1, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 7, 1, 'Goddess Sylvanas 150m', 1, 0, 'Fatbear', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 8, 1, 'Haven  Free', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 8, 2, 'Aja', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 8, 3, 'Aja Free', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 9, 1, 'Eden The Doll', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 9, 2, 'Katie Flood', 1, 0, 'Verge', '2026-11-01', 'Distribuição de 01/11 (script 28)'),
  ('U3C2', 10, 1, 'Lily Lou OFTV', 1, 0, '5C Company', '2026-11-01', 'Distribuição de 01/11 (script 28)');

-- 5. Recalcula hoje (versão nova)
CALL sp_fc_recalcular_postos(CURDATE(), 'Script 28: postos fixos');

-- 6. Conferência (esperado em cada turno: U1C1 12, U1C2 11, U2C1 12, U2C2 11, U3C1 11, U3C2 11)
SELECT carteira, turno, COUNT(*) AS postos FROM fc_postos WHERE data_calculo = CURDATE() GROUP BY carteira, turno ORDER BY carteira, turno;
-- páginas que ficaram fora da tabela fixa (esperado: nenhuma)
SELECT pc.carteira, pc.pagina FROM fc_pagina_carga pc
 WHERE pc.data_calculo = CURDATE() AND pc.turno = 'A'
   AND NOT EXISTS (SELECT 1 FROM fc_posto_fixo x WHERE x.pagina = pc.pagina AND x.carteira = pc.carteira AND x.vigente_ate IS NULL);
-- página em duas carteiras no dia (esperado: nenhuma)
SELECT pagina, COUNT(*) FROM fc_pagina_carteira
 WHERE vigente_desde <= CURDATE() AND (vigente_ate IS NULL OR vigente_ate > CURDATE()) GROUP BY pagina HAVING COUNT(*) > 1;
