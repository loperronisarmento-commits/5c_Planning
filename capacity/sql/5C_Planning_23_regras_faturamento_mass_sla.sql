-- =====================================================================
-- 5C | Forecast de WFM — 23. Regras de 31/10: Mass PPV com os supervisores, faturamento por página,
--      agência do Infloww, horas fora do SLA e faturamento por unidade em cada versão
-- Schema: 5c_planning        31/10/2026        Rodar depois do 22.
--
-- Decisões do usuário em 31/10:
--   1. Mass PPV (.21 e .22) passa para os supervisores. Medido: o rep gasta cerca de 0,5% do tempo com ela
--      (1.310 Mass montadas pelos reps no Infloww em setembro, uma a cada 11 turnos; e 0,2% dos fãs por hora
--      recebem só PPV .21/.22 no chat). alivio_mass_ppv = 0,005 aumenta limite_padrao e limite_compartilhada.
--   2. Postos de 2 ou 3 páginas: primeiro a mesma modelo, depois a mesma agência do Infloww (5C Company,
--      Fatbear, Verge), em fc_pagina_infloww (preenchida pelo nome; as páginas sem agência podem ser
--      completadas à mão, com origem = 'MANUAL').
--   3. Faturamento bruto do mês fechado anterior (agg_statements_classification, sem reverse):
--      abaixo de receita_triple (16k) → 3 páginas por rep; abaixo de receita_double (32k) → 2 páginas por rep,
--      mesmo acima da capacidade. Só páginas PADRAO e com venda no mês (Bianca SLA80, Mikayla e as de regra
--      própria ficam fora). Esses postos ficam com forcado = 1 e a carga acima da capacidade não vira buffer.
--      Nesses grupos podem ficar 3 modelos diferentes (a regra de faturamento vale mais que o limite de 2).
--      As vagas dos grupos de 3 são completadas com páginas de 16k a 32k; as que ficam sem par voltam para o
--      agrupamento normal.
--   4. SLA justo: nas horas em que os fãs de um posto forçado passam da capacidade, as páginas dele ficam em
--      fc_sla_hora_excluida (dia, hora, página). A conta de SLA do rep não deve contar essas horas.
--   5. Páginas sem escala (fc_pagina_sem_escala, nome exato ou padrão com %): ficam na carga e no
--      faturamento, mas não ocupam posto. Começa com 'zOFTV%' (nenhuma zOFTV tem escala).
--   6. Pedido repassado pela Simulations: cada versão grava o faturamento de 30 dias por carteira e unidade
--      (fc_capacity_versao_faturamento) e fica com alerta quando a diferença entre a maior e a menor unidade
--      passa de alerta_fat_unidades (30%) da média. A troca de páginas entre unidades é do Planning tool.
-- No fim, recalcula 31/10 com as regras novas (versão nova) e confere com o espelho em Python
-- (capacity/simulacao/regras_receita_espelho.py).
-- Para desfazer as regras: bloco no fim (zera os parâmetros e recalcula).
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------------------------------------------------------------------
-- 1. Parâmetros
-- ---------------------------------------------------------------------
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('alivio_mass_ppv',     0.005, 'Parte do tempo do rep que a Mass PPV (.21/.22) ocupava; aumenta limite_padrao e limite_compartilhada'),
 ('receita_triple',      16000, 'Faturamento bruto do mês anterior (USD) abaixo do qual a página trabalha 3 por rep'),
 ('receita_double',      32000, 'Faturamento bruto do mês anterior (USD) abaixo do qual a página trabalha 2 por rep'),
 ('alerta_fat_unidades', 0.30,  'Diferença de faturamento de 30 dias entre a maior e a menor unidade, sobre a média, que dá alerta')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 2. Colunas novas (só se ainda não existem)
-- ---------------------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_tmp_23_coluna $$
CREATE PROCEDURE sp_tmp_23_coluna(IN p_tab VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tab AND COLUMN_NAME = p_col) THEN
    SET @sql_23 = CONCAT('ALTER TABLE `', p_tab, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE st FROM @sql_23;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END $$
DELIMITER ;

CALL sp_tmp_23_coluna('fc_pagina_carga', 'classe_receita', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''3 = 3 por rep (<16k) · 2 = 2 por rep (<32k) · 0 = normal''');
CALL sp_tmp_23_coluna('fc_pagina_carga', 'agencia', 'INT NULL COMMENT ''agência do Infloww (fc_pagina_infloww)''');
CALL sp_tmp_23_coluna('fc_pagina_carga', 'fat_mes', 'DECIMAL(12,2) NULL COMMENT ''faturamento bruto do mês fechado anterior''');
CALL sp_tmp_23_coluna('fc_postos', 'forcado', 'TINYINT NOT NULL DEFAULT 0 COMMENT ''1 = posto da regra de faturamento (pode passar da capacidade)''');
CALL sp_tmp_23_coluna('fc_capacity_versao_posto', 'forcado', 'TINYINT NOT NULL DEFAULT 0');
CALL sp_tmp_23_coluna('fc_capacity_versao', 'fat_max_unidade', 'DECIMAL(14,2) NULL COMMENT ''faturamento de 30 dias da maior unidade''');
CALL sp_tmp_23_coluna('fc_capacity_versao', 'fat_min_unidade', 'DECIMAL(14,2) NULL');
CALL sp_tmp_23_coluna('fc_capacity_versao', 'fat_dif_pct', 'DECIMAL(6,4) NULL COMMENT ''(maior − menor) ÷ média das unidades''');
CALL sp_tmp_23_coluna('fc_capacity_versao', 'alerta_fat', 'TINYINT NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS sp_tmp_23_coluna;

-- ---------------------------------------------------------------------
-- 3. Tabelas
-- ---------------------------------------------------------------------
-- Tabelas de trabalho do agrupamento (recriadas com a agência e a regra de faturamento)
DROP TABLE IF EXISTS fc_wrk_pc_item;
CREATE TABLE fc_wrk_pc_item (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  pagina   VARCHAR(150) NOT NULL,
  modelo   VARCHAR(150) NOT NULL,
  agencia  INT          NULL,
  f        DECIMAL(6,1) NOT NULL,
  apoio    TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fc_wrk_pc_bin;
CREATE TABLE fc_wrk_pc_bin (
  bin           INT          NOT NULL PRIMARY KEY,
  n             TINYINT      NOT NULL,
  soma          DECIMAL(7,1) NOT NULL,
  apoio         TINYINT      NOT NULL,
  modelo_unico  VARCHAR(150) NULL COMMENT 'NULL = modelos diferentes no grupo',
  primeira      VARCHAR(150) NOT NULL,
  forcado       TINYINT      NOT NULL DEFAULT 0,
  classe        TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fc_wrk_pc_bin_item;
CREATE TABLE fc_wrk_pc_bin_item (
  bin      INT          NOT NULL,
  seq      TINYINT      NOT NULL,
  pagina   VARCHAR(150) NOT NULL,
  modelo   VARCHAR(150) NOT NULL,
  agencia  INT          NULL,
  f        DECIMAL(6,1) NOT NULL,
  apoio    TINYINT      NOT NULL,
  PRIMARY KEY (bin, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS fc_wrk_pc_solo;
CREATE TABLE fc_wrk_pc_solo (
  ordem    INT          NOT NULL,
  pagina   VARCHAR(150) NOT NULL,
  modelo   VARCHAR(150) NOT NULL,
  agencia  INT          NULL,
  f        DECIMAL(6,1) NOT NULL,
  apoio    TINYINT      NOT NULL,
  PRIMARY KEY (ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_pc_forc (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  pagina   VARCHAR(150) NOT NULL,
  modelo   VARCHAR(150) NOT NULL,
  agencia  INT          NULL,
  f        DECIMAL(6,1) NOT NULL,
  classe   TINYINT      NOT NULL,
  usado    TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Páginas sem escala: nome exato ou padrão do LIKE (ex.: 'zOFTV%')
CREATE TABLE IF NOT EXISTS fc_pagina_sem_escala (
  padrao     VARCHAR(150) NOT NULL PRIMARY KEY,
  motivo     VARCHAR(255) NULL,
  criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fc_pagina_sem_escala (padrao, motivo) VALUES ('zOFTV%', 'Nenhuma zOFTV tem escala (decisão de 31/10)');

-- Agência do Infloww de cada página
CREATE TABLE IF NOT EXISTS fc_pagina_infloww (
  pagina        VARCHAR(150) NOT NULL PRIMARY KEY,
  agency_id     INT          NULL COMMENT '5c_infloww.agencies.id (NULL = sem agência conhecida)',
  agencia       VARCHAR(60)  NULL,
  origem        ENUM('NOME','MANUAL','SEM') NOT NULL COMMENT 'NOME = achada pelo nome no Infloww · MANUAL = informada',
  atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faturamento por página e dia (agg_statements_classification, sem reverse)
CREATE TABLE IF NOT EXISTS fc_pagina_venda_dia (
  dia     DATE          NOT NULL,
  pagina  VARCHAR(150)  NOT NULL COMMENT 'CREATOR_NAME dos statements',
  bruto   DECIMAL(12,2) NOT NULL,
  vendas  DECIMAL(12,2) NOT NULL COMMENT 'mensagens (SR, PPV) e gorjetas (TIP)',
  PRIMARY KEY (dia, pagina),
  KEY ix_pagina (pagina, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faturamento de 30 dias por carteira em cada versão do capacity
CREATE TABLE IF NOT EXISTS fc_capacity_versao_faturamento (
  versao      INT           NOT NULL,
  carteira    VARCHAR(8)    NOT NULL,
  unidade     TINYINT       NOT NULL,
  bruto_30d   DECIMAL(14,2) NOT NULL,
  vendas_30d  DECIMAL(14,2) NOT NULL,
  paginas     SMALLINT      NOT NULL,
  PRIMARY KEY (versao, carteira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horas que não contam no SLA do rep: posto da regra de faturamento acima da capacidade
CREATE TABLE IF NOT EXISTS fc_sla_hora_excluida (
  dia         DATE         NOT NULL COMMENT 'dia do turno (o turno D vai até 05:00 do dia seguinte)',
  hora        TINYINT      NOT NULL,
  pagina      VARCHAR(150) NOT NULL,
  carteira    VARCHAR(8)   NOT NULL,
  turno       CHAR(1)      NOT NULL,
  posto       SMALLINT     NOT NULL,
  versao      INT          NOT NULL COMMENT 'versão do capacity com os postos usados',
  fans_posto  DECIMAL(8,1) NOT NULL COMMENT 'fãs na hora, somando as páginas do posto',
  capacidade  DECIMAL(5,1) NOT NULL,
  PRIMARY KEY (dia, hora, pagina),
  KEY ix_posto (dia, carteira, turno, posto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- agência pelo nome da página no Infloww (só quando o nome está numa agência só); não mexe nas MANUAL
INSERT INTO fc_pagina_infloww (pagina, agency_id, agencia, origem)
SELECT p.pagina, x.ag, a.name, IF(x.ag IS NULL, 'SEM', 'NOME')
FROM (SELECT DISTINCT pagina FROM fc_pagina_carteira) p
LEFT JOIN (SELECT c.name COLLATE utf8mb4_unicode_ci AS nome, MIN(c.agency_id) AS ag
           FROM `5c_infloww`.infloww_all_creators c
           GROUP BY c.name COLLATE utf8mb4_unicode_ci
           HAVING COUNT(DISTINCT c.agency_id) = 1) x ON x.nome = p.pagina
LEFT JOIN `5c_infloww`.agencies a ON a.id = x.ag
ON DUPLICATE KEY UPDATE agency_id = IF(fc_pagina_infloww.origem = 'MANUAL', fc_pagina_infloww.agency_id, VALUES(agency_id)),
                        agencia   = IF(fc_pagina_infloww.origem = 'MANUAL', fc_pagina_infloww.agencia, VALUES(agencia)),
                        origem    = IF(fc_pagina_infloww.origem = 'MANUAL', 'MANUAL', VALUES(origem));

DELIMITER $$

-- ---------------------------------------------------------------------
-- 4. Faturamento por página e dia: carrega os dias que faltam desde o mês anterior e relê os 3 últimos
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_vendas_atualizar $$
CREATE PROCEDURE sp_fc_vendas_atualizar(IN p_data DATE)
BEGIN
  DECLARE v_ini, v_desde DATE;
  SET v_ini = LEAST(p_data - INTERVAL (DAYOFMONTH(p_data) - 1) DAY - INTERVAL 1 MONTH, p_data - INTERVAL 31 DAY);

  -- os 3 últimos dias são sempre relidos (as vendas pendentes ainda mudam)
  DELETE FROM fc_pagina_venda_dia WHERE dia >= p_data - INTERVAL 3 DAY AND dia < p_data;
  SELECT MIN(c.dia) INTO v_desde
  FROM fc_calendario c
  WHERE c.dia >= v_ini AND c.dia < p_data
    AND NOT EXISTS (SELECT 1 FROM fc_pagina_venda_dia v WHERE v.dia = c.dia);

  IF v_desde IS NOT NULL THEN
    DELETE FROM fc_pagina_venda_dia WHERE dia >= v_desde AND dia < p_data;
    INSERT INTO fc_pagina_venda_dia (dia, pagina, bruto, vendas)
    SELECT DATE(s.DATE), s.CREATOR_NAME, SUM(s.AMOUNT), SUM(IF(s.Classification IN ('SR', 'PPV', 'TIP'), s.AMOUNT, 0))
    FROM `5c_wfm_db`.agg_statements_classification s
    WHERE s.DATE >= v_desde AND s.DATE < p_data AND s.STATUS <> 'reverse' AND s.CREATOR_NAME IS NOT NULL
    GROUP BY DATE(s.DATE), s.CREATOR_NAME;
  END IF;

  DELETE FROM fc_pagina_venda_dia WHERE dia < p_data - INTERVAL 400 DAY;
END $$

-- ---------------------------------------------------------------------
-- 5. Horas fora do SLA: postos da regra de faturamento acima da capacidade, hora a hora
--    p_versao NULL = a última versão calculada (não reconstruída) com data igual ou anterior ao dia
--    (o Planning tool pode passar a versão que a escala usa)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_sla_horas $$
CREATE PROCEDURE sp_fc_sla_horas(IN p_dia DATE, IN p_versao INT)
BEGIN
  DECLARE v INT;
  SET v = p_versao;
  IF v IS NULL THEN
    SELECT MAX(versao) INTO v FROM fc_capacity_versao WHERE data_calculo <= p_dia AND origem <> 'RECONSTRUIDA';
  END IF;

  DELETE FROM fc_sla_hora_excluida WHERE dia = p_dia;
  IF v IS NOT NULL THEN
    INSERT INTO fc_sla_hora_excluida (dia, hora, pagina, carteira, turno, posto, versao, fans_posto, capacidade)
    SELECT p_dia, x.hora, vp.pagina, x.carteira, x.turno, x.posto, v, x.fans, x.cap
    FROM (SELECT vp.carteira, vp.turno, vp.posto, h.hora, SUM(h.fans) AS fans, MAX(vp.capacidade) AS cap
          FROM fc_capacity_versao_posto vp
          JOIN fc_fans_pagina_hora h ON h.pagina = vp.pagina AND h.turno = vp.turno AND h.dia_turno = p_dia
          WHERE vp.versao = v AND vp.forcado = 1
          GROUP BY vp.carteira, vp.turno, vp.posto, h.hora
          HAVING SUM(h.fans) > MAX(vp.capacidade)) x
    JOIN fc_capacity_versao_posto vp ON vp.versao = v AND vp.carteira = x.carteira
                                    AND vp.turno = x.turno AND vp.posto = x.posto;
  END IF;

  DELETE FROM fc_sla_hora_excluida WHERE dia < p_dia - INTERVAL 400 DAY;
END $$

-- ---------------------------------------------------------------------
-- 6. Procedimentos do 11 (versão 1.2) e do 19 com as regras novas (iguais aos arquivos)
-- ---------------------------------------------------------------------
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
  DECLARE c_pag CURSOR FOR
    SELECT pagina, modelo, regra, GREATEST(fans_h, 1), COALESCE(classe_receita, 0), agencia
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

DROP PROCEDURE IF EXISTS sp_fc_postos $$
CREATE PROCEDURE sp_fc_postos(IN p_data DATE)
BEGIN
  DECLARE v_jan, v_jb, v_jp, v_nuc, v_nfolga, v_bmin INT;
  DECLARE v_def, v_min, v_fator, v_paf, v_pp, v_tp, v_lp, v_vol, v_alivio, v_r3, v_r2 DECIMAL(12,4);
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

  -- regra de faturamento (31/10): faturamento bruto do mês fechado anterior; só páginas PADRAO com venda no mês
  --   abaixo de receita_triple → classe 3 (3 por rep) · abaixo de receita_double → classe 2 (2 por rep)
  UPDATE fc_pagina_carga pc
  LEFT JOIN (SELECT pagina, SUM(bruto) AS b FROM fc_pagina_venda_dia
             WHERE dia >= v_mes - INTERVAL 1 MONTH AND dia < v_mes GROUP BY pagina) f ON f.pagina = pc.pagina
  LEFT JOIN fc_pagina_infloww i ON i.pagina = pc.pagina
     SET pc.fat_mes = f.b,
         pc.agencia = i.agency_id,
         pc.classe_receita = CASE WHEN pc.regra <> 'PADRAO' OR f.b IS NULL THEN 0
                                  WHEN f.b < v_r3 THEN 3 WHEN f.b < v_r2 THEN 2 ELSE 0 END
   WHERE pc.data_calculo = p_data;

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
  -- etapa do script 19 (versões do capacity e plano sugerido); numa instalação nova, rodar o 19 logo depois
  UPDATE fc_log_execucao SET etapa = 'versao' WHERE id = v_log;
  CALL sp_fc_capacity_versao(v_d, 'DIARIO', NULL);
  -- etapa do script 23: horas fora do SLA nos postos da regra de faturamento (fãs de ontem e anteontem)
  UPDATE fc_log_execucao SET etapa = 'sla' WHERE id = v_log;
  CALL sp_fc_sla_horas(v_d - INTERVAL 2 DAY, NULL);
  CALL sp_fc_sla_horas(v_d - INTERVAL 1 DAY, NULL);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

DROP PROCEDURE IF EXISTS sp_fc_versao_fechar $$
CREATE PROCEDURE sp_fc_versao_fechar(IN p_v INT)
BEGIN
  DECLARE v_prev, v_pa, v_pn, v_mud, v_dif, v_lim, v_of, v_sg INT;
  DECLARE v_fmax, v_fmin, v_fmed, v_flim DECIMAL(14,4);
  DECLARE v_ftxt VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_saiu, v_entrou VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_mot VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

  SET SESSION group_concat_max_len = 4000;
  -- versão anterior: a última gravada antes desta com data de cálculo igual ou anterior
  -- (um recálculo de uma data antiga não é comparado com o cálculo de uma data mais nova)
  SELECT MAX(versao) INTO v_prev FROM fc_capacity_versao
  WHERE versao < p_v AND data_calculo <= (SELECT data_calculo FROM fc_capacity_versao WHERE versao = p_v);

  IF v_prev IS NULL THEN
    SET v_mot = 'Primeira versão';
  ELSE
    SELECT precisa INTO v_pa FROM fc_capacity_versao WHERE versao = v_prev;
    SELECT precisa INTO v_pn FROM fc_capacity_versao WHERE versao = p_v;
    SET v_saiu = NULL, v_entrou = NULL, v_mud = 0;

    -- páginas: só quando as duas versões têm os postos (as reconstruídas têm só as unidades)
    IF EXISTS (SELECT 1 FROM fc_capacity_versao_posto WHERE versao = v_prev)
       AND EXISTS (SELECT 1 FROM fc_capacity_versao_posto WHERE versao = p_v) THEN
    SELECT GROUP_CONCAT(DISTINCT a.pagina ORDER BY a.pagina SEPARATOR ', ') INTO v_saiu
    FROM fc_capacity_versao_posto a
    WHERE a.versao = v_prev
      AND NOT EXISTS (SELECT 1 FROM fc_capacity_versao_posto b WHERE b.versao = p_v AND b.pagina = a.pagina);
    SELECT GROUP_CONCAT(DISTINCT b.pagina ORDER BY b.pagina SEPARATOR ', ') INTO v_entrou
    FROM fc_capacity_versao_posto b
    WHERE b.versao = p_v
      AND NOT EXISTS (SELECT 1 FROM fc_capacity_versao_posto a WHERE a.versao = v_prev AND a.pagina = b.pagina);
    SELECT COUNT(DISTINCT a.pagina) INTO v_mud
    FROM (SELECT pagina, turno, SUM(fracao) AS f FROM fc_capacity_versao_posto WHERE versao = v_prev GROUP BY pagina, turno) a
    JOIN (SELECT pagina, turno, SUM(fracao) AS f FROM fc_capacity_versao_posto WHERE versao = p_v GROUP BY pagina, turno) b
      ON b.pagina = a.pagina AND b.turno = a.turno
    WHERE ROUND(a.f, 2) <> ROUND(b.f, 2);
    END IF;

    SET v_mot = CONCAT_WS(' · ',
      IF(v_saiu IS NULL, NULL, CONCAT('Saíram: ', v_saiu)),
      IF(v_entrou IS NULL, NULL, CONCAT('Entraram: ', v_entrou)),
      IF(v_mud = 0, NULL, CONCAT(v_mud, IF(v_mud = 1, ' página mudou', ' páginas mudaram'), ' de postos')),
      IF(v_pn = v_pa, CONCAT('Precisa igual (', v_pn, ')'),
         CONCAT('Precisa ', v_pa, ' → ', v_pn, ' (', IF(v_pn > v_pa, '+', '−'), ABS(v_pn - v_pa), ')')));
  END IF;

  UPDATE fc_capacity_versao SET versao_anterior = v_prev, motivo = LEFT(v_mot, 2000) WHERE versao = p_v;

  CALL sp_fc_plano_sugerido(p_v);

  SELECT CAST(COALESCE(MAX(valor), 10) AS SIGNED) INTO v_lim FROM fc_parametros WHERE chave = 'alerta_dif_plano';
  SELECT dif_plano, contratar_oficial, contratar_sugerido INTO v_dif, v_of, v_sg FROM fc_capacity_versao WHERE versao = p_v;
  IF ABS(COALESCE(v_dif, 0)) >= v_lim THEN
    UPDATE fc_capacity_versao
       SET alerta = 1,
           motivo = LEFT(CONCAT_WS(' · ', motivo, CONCAT('Revisar plano: turmas planejadas com ', v_of,
                                   ' no oficial e ', v_sg, ' no sugerido')), 2000)
     WHERE versao = p_v;
  END IF;

  -- faturamento de 30 dias por unidade: diferença entre a maior e a menor sobre a média (script 23)
  IF EXISTS (SELECT 1 FROM fc_capacity_versao_faturamento WHERE versao = p_v) THEN
    SELECT MAX(t), MIN(t), AVG(t),
           GROUP_CONCAT(CONCAT('U', unidade, ' ', FORMAT(t / 1000000, 2, 'pt_BR'), ' M') ORDER BY unidade SEPARATOR ' · ')
      INTO v_fmax, v_fmin, v_fmed, v_ftxt
    FROM (SELECT unidade, SUM(bruto_30d) AS t FROM fc_capacity_versao_faturamento WHERE versao = p_v GROUP BY unidade) u;
    SELECT COALESCE(MAX(valor), 0.30) INTO v_flim FROM fc_parametros WHERE chave = 'alerta_fat_unidades';
    UPDATE fc_capacity_versao
       SET fat_max_unidade = v_fmax, fat_min_unidade = v_fmin,
           fat_dif_pct = IF(v_fmed > 0, (v_fmax - v_fmin) / v_fmed, NULL),
           alerta_fat = IF(v_fmed > 0 AND (v_fmax - v_fmin) / v_fmed >= v_flim, 1, 0)
     WHERE versao = p_v;
    IF v_fmed > 0 AND (v_fmax - v_fmin) / v_fmed >= v_flim THEN
      UPDATE fc_capacity_versao
         SET motivo = LEFT(CONCAT_WS(' · ', motivo, CONCAT('Faturamento 30 dias desequilibrado: ', v_ftxt,
                                     ' (diferença ', ROUND(100 * (v_fmax - v_fmin) / v_fmed), '% da média)')), 2000)
       WHERE versao = p_v;
    END IF;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_fc_capacity_versao $$
CREATE PROCEDURE sp_fc_capacity_versao(IN p_data DATE, IN p_origem VARCHAR(12), IN p_obs VARCHAR(255))
main: BEGIN
  DECLARE v INT;
  IF NOT EXISTS (SELECT 1 FROM fc_necessidade_estrutura WHERE data_calculo = p_data) THEN
    LEAVE main;
  END IF;

  INSERT INTO fc_capacity_versao (data_calculo, gerado_em, origem, obs, paginas, postos, titulares, buffer, precisa, tem, saldo)
  SELECT p_data, NOW(), p_origem, p_obs,
         (SELECT COUNT(DISTINCT pagina) FROM fc_postos_pagina WHERE data_calculo = p_data),
         (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = p_data),
         SUM(titulares), SUM(buffer), SUM(precisa), SUM(tem), SUM(saldo)
  FROM fc_necessidade_estrutura WHERE data_calculo = p_data;
  SET v = LAST_INSERT_ID();

  INSERT INTO fc_capacity_versao_unidade (versao, unidade, turno, postos, titulares, faltas_dia, pico_dia,
                                          presentes_dia, buffer, precisa, tem, saldo)
  SELECT v, unidade, turno, postos, titulares, faltas_dia, pico_dia, presentes_dia, buffer, precisa, tem, saldo
  FROM fc_necessidade_estrutura WHERE data_calculo = p_data;

  INSERT INTO fc_capacity_versao_posto (versao, carteira, turno, posto, pagina, unidade, tipo, carga, capacidade, fans_h, fracao, forcado)
  SELECT v, pp.carteira, pp.turno, pp.posto, pp.pagina, p.unidade, p.tipo, p.carga, p.capacidade, pp.fans_h, pp.fracao, p.forcado
  FROM fc_postos_pagina pp
  JOIN fc_postos p ON p.data_calculo = pp.data_calculo AND p.carteira = pp.carteira
                  AND p.turno = pp.turno AND p.posto = pp.posto
  WHERE pp.data_calculo = p_data;

  -- faturamento dos 30 dias antes da data, por carteira (páginas da estrutura na data; script 23)
  INSERT INTO fc_capacity_versao_faturamento (versao, carteira, unidade, bruto_30d, vendas_30d, paginas)
  SELECT v, c.carteira, c.unidade, COALESCE(SUM(f.bruto), 0), COALESCE(SUM(f.vendas), 0), COUNT(*)
  FROM (SELECT DISTINCT pagina, carteira FROM fc_pagina_carga WHERE data_calculo = p_data) pg
  JOIN fc_carteira c ON c.carteira = pg.carteira
  LEFT JOIN (SELECT pagina, SUM(bruto) AS bruto, SUM(vendas) AS vendas FROM fc_pagina_venda_dia
             WHERE dia >= p_data - INTERVAL 30 DAY AND dia < p_data GROUP BY pagina) f ON f.pagina = pg.pagina
  GROUP BY c.carteira, c.unidade;

  CALL sp_fc_versao_fechar(v);
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 7. Visões
-- ---------------------------------------------------------------------
-- (18) Horas fora do SLA por posto (a tabela fc_sla_hora_excluida tem uma linha por página)
CREATE OR REPLACE VIEW vw_fc_18_sla_horas_excluidas AS
SELECT dia AS `Dia`, hora AS `Hora`, carteira AS `Carteira`, turno AS `Turno`, posto AS `Posto`,
       GROUP_CONCAT(pagina ORDER BY pagina SEPARATOR ' + ') AS `Páginas`,
       fans_posto AS `Fãs na hora`, capacidade AS `Capacidade`, versao AS `Versão`
FROM fc_sla_hora_excluida
GROUP BY dia, hora, carteira, turno, posto, fans_posto, capacidade, versao
ORDER BY dia DESC, hora, carteira, turno, posto;

-- (19) Faturamento de 30 dias por unidade e carteira, na última versão que tem faturamento
CREATE OR REPLACE VIEW vw_fc_19_faturamento_unidades AS
SELECT IF(GROUPING(f.unidade), 'Total', CONCAT('U', f.unidade)) AS `Unidade`,
       IF(GROUPING(f.carteira), IF(GROUPING(f.unidade), '', 'Unidade'), f.carteira) AS `Carteira`,
       ROUND(SUM(f.bruto_30d)) AS `Bruto 30 dias`, ROUND(SUM(f.vendas_30d)) AS `Vendas 30 dias`,
       SUM(f.paginas) AS `Páginas`, MAX(f.versao) AS `Versão`
FROM fc_capacity_versao_faturamento f
WHERE f.versao = (SELECT MAX(versao) FROM fc_capacity_versao_faturamento)
GROUP BY f.unidade, f.carteira WITH ROLLUP;

-- (20) Regra de faturamento e agência de cada página, no último cálculo
CREATE OR REPLACE VIEW vw_fc_20_paginas_faturamento AS
SELECT pc.pagina AS `Página`, pc.carteira AS `Carteira`, pc.regra AS `Regra`,
       ROUND(MAX(pc.fat_mes)) AS `Faturamento mês anterior`,
       CASE MAX(pc.classe_receita) WHEN 3 THEN '3 por rep' WHEN 2 THEN '2 por rep' ELSE '' END AS `Regra de faturamento`,
       MAX(i.agencia) AS `Agência Infloww`, MAX(pc.data_calculo) AS `Data do cálculo`
FROM fc_pagina_carga pc
LEFT JOIN fc_pagina_infloww i ON i.pagina = pc.pagina
WHERE pc.data_calculo = (SELECT MAX(data_calculo) FROM fc_pagina_carga)
GROUP BY pc.pagina, pc.carteira, pc.regra
ORDER BY MAX(pc.fat_mes) IS NULL, MAX(pc.fat_mes);

-- ---------------------------------------------------------------------
-- 8. Recalcular 31/10 com as regras novas (versão nova) e as horas fora do SLA de 30/10 com ela
--    (a primeira vez carrega o faturamento por dia desde 01/09: leva alguns segundos)
-- ---------------------------------------------------------------------
CALL sp_fc_recalcular_postos('2026-10-31', 'Regras de 31/10: faturamento, agência do Infloww e alívio da Mass (script 23)');
SET @v23 = (SELECT MAX(versao) FROM fc_capacity_versao);
CALL sp_fc_sla_horas('2026-10-30', @v23);

-- ---------- Conferência ----------
-- 9.1 Postos por unidade e turno contra o espelho (todos 'ok'); antes das regras eram 178 postos e 271 titulares
SELECT CONCAT('U', u.unidade, u.turno) AS `Unidade`, u.postos AS `Postos`, e.postos AS `Esperado`,
       u.titulares AS `Titulares`, u.precisa AS `Precisa`, IF(u.postos = e.postos, 'ok', 'DIFERENTE') AS `Confere`
FROM fc_capacity_versao_unidade u
JOIN (SELECT 1 AS un, 'A' AS t, 21 AS postos UNION ALL SELECT 1, 'C', 22 UNION ALL SELECT 1, 'D', 22 UNION ALL
      SELECT 2, 'A', 18 UNION ALL SELECT 2, 'C', 18 UNION ALL SELECT 2, 'D', 18 UNION ALL
      SELECT 3, 'A', 16 UNION ALL SELECT 3, 'C', 17 UNION ALL SELECT 3, 'D', 17) e ON e.un = u.unidade AND e.t = u.turno
WHERE u.versao = @v23
ORDER BY u.unidade, FIELD(u.turno, 'A', 'C', 'D');

-- 9.2 Totais: esperado 169 postos, 45 da regra de faturamento (6 acima da capacidade), 255 titulares
SELECT COUNT(*) AS `Postos`, SUM(forcado) AS `Da regra de faturamento`,
       SUM(forcado = 1 AND carga > capacidade) AS `Acima da capacidade`,
       (SELECT SUM(titulares) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-31') AS `Titulares`,
       (SELECT SUM(precisa) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-31') AS `Precisa`
FROM fc_postos WHERE data_calculo = '2026-10-31';

-- 9.3 A versão nova: o que mudou, plano de turmas e faturamento por unidade
SELECT `Versão`, `Precisa`, `Δ precisa`, `Planejadas (oficial)`, `Planejadas (sugerido)`, `Alerta`, `O que mudou`
FROM vw_fc_13_capacity_versoes LIMIT 1;
SELECT * FROM vw_fc_19_faturamento_unidades;

-- 9.4 Páginas na regra de faturamento: esperado 20 com 3 por rep (12 ocupam posto) e 28 com 2 por rep
SELECT `Regra de faturamento`, COUNT(*) AS `Páginas` FROM vw_fc_20_paginas_faturamento GROUP BY `Regra de faturamento`;
SELECT * FROM vw_fc_20_paginas_faturamento;

-- 9.5 Páginas sem escala no cálculo novo (esperado: as zOFTV, sem posto)
SELECT DISTINCT pc.pagina AS `Página`, pc.carteira AS `Carteira`, pc.ocupa_posto AS `Ocupa posto`
FROM fc_pagina_carga pc JOIN fc_pagina_sem_escala x ON pc.pagina LIKE x.padrao
WHERE pc.data_calculo = '2026-10-31';

-- 9.6 Agência do Infloww: páginas sem agência (dá para completar com origem = 'MANUAL')
SELECT origem AS `Origem`, COUNT(*) AS `Páginas` FROM fc_pagina_infloww GROUP BY origem;

-- 9.7 Horas fora do SLA em 30/10 com os postos novos (exemplo)
SELECT COUNT(*) AS `Linhas (página × hora)`, COUNT(DISTINCT CONCAT(carteira, turno, posto)) AS `Postos`
FROM fc_sla_hora_excluida WHERE dia = '2026-10-30';
SELECT * FROM vw_fc_18_sla_horas_excluidas WHERE `Dia` = '2026-10-30' LIMIT 20;

-- ---------------------------------------------------------------------
-- 10. Desfazer as regras (só se precisar): zera os parâmetros e recalcula; as tabelas ficam
-- ---------------------------------------------------------------------
-- UPDATE fc_parametros SET valor = 0 WHERE chave IN ('alivio_mass_ppv', 'receita_triple', 'receita_double');
-- CALL sp_fc_recalcular_postos(CURDATE(), 'Regras de 31/10 desligadas');
