-- =====================================================================
-- 5C | Forecast de WFM — 19. Versões do capacity, plano de turmas sugerido e comparação com o oficial
-- Schema: 5c_planning        30/10/2026        Rodar depois do 18.
--
-- Pedido de 28/10, depois da saída da Skylar: o capacity e as contratações não podem depender de
-- recálculo à mão, e o capacity anterior tem que ficar guardado para comparar.
--
-- 1. Versões do capacity. Cada cálculo vira uma versão nova, sem apagar as anteriores:
--    - o resumo (fc_capacity_versao), as unidades × turnos (fc_capacity_versao_unidade) e os postos com as
--      páginas (fc_capacity_versao_posto);
--    - o "O que mudou" sai sozinho da comparação com a versão anterior: páginas que saíram ou entraram,
--      quantas mudaram de postos e a diferença do "precisa".
--    O cálculo diário (sp_fc_rodar_dia, recriado aqui com a etapa 'versao') grava uma versão por dia. Um
--    recálculo à mão usa CALL sp_fc_recalcular_postos(data, 'motivo'), que também grava a versão.
--    Uma página desativada no cadastro sai sozinha no cálculo do dia seguinte e a versão registra a saída.
-- 2. Plano de turmas sugerido (fc_plano_sugerido), refeito em cada versão:
--    - usa o calendário de turmas do plano oficial (fc_contratacoes). As confirmadas ficam como estão; as
--      planejadas são recalculadas, uma a uma e em ordem, para o quadro chegar ao "precisa" de cada turno;
--    - turnover na mesma conta do sp_fc_prev_to: taxa por turno das últimas janela_to_semanas semanas, e
--      novatos com novato_sem_1_4 e novato_sem_5_13 no total (o que passa da taxa do turno);
--    - quadro de partida = "tem" da versão (fc_estrutura_rep); desistência no treinamento = desistencia_trn;
--      no máximo teto_turma por turma (maiores restos entre os turnos).
--    A projeção semanal do quadro com o plano oficial e com o sugerido fica em fc_plano_projecao.
-- 3. O plano oficial continua manual: fc_contratacoes só muda por script. As visões mostram a diferença
--    turma a turma e a versão fica com alerta quando o total das planejadas passa de alerta_dif_plano.
--
-- Visões novas: vw_fc_13_capacity_versoes, vw_fc_14_capacity_mudancas, vw_fc_15_capacity_paginas,
--               vw_fc_16_plano_oficial_vs_sugerido, vw_fc_17_projecao_quadro.
-- Histórico inicial: 28/10 com e sem a Skylar (reconstruídas, só unidades) e os cálculos diários de 29/10 e 30/10.
-- A conferência no fim vale para rodar em 30/10 (as taxas de turnover mudam com o cálculo diário).
-- Pode rodar mais de uma vez: as tabelas e o histórico inicial só são criados na primeira.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------------------------------------------------------------------
-- 1. Parâmetros
-- ---------------------------------------------------------------------
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('teto_turma',        20,   'Máximo de pessoas por turma no plano sugerido (capacidade do TRN)'),
 ('desistencia_trn',   0.06, 'Parte da turma que desiste no treinamento (o plano sugerido contrata a mais)'),
 ('falta_fechada',     5,    'Falta (precisa − quadro projetado) que conta como quadro fechado'),
 ('alerta_dif_turma',  3,    'Diferença por turma entre o plano oficial e o sugerido que pede revisão'),
 ('alerta_dif_plano',  10,   'Diferença no total das turmas planejadas que deixa a versão com alerta')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 2. Tabelas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_capacity_versao (
  versao            INT          NOT NULL AUTO_INCREMENT,
  data_calculo      DATE         NOT NULL,
  gerado_em         DATETIME     NOT NULL,
  origem            ENUM('DIARIO','MANUAL','RECONSTRUIDA') NOT NULL,
  obs               VARCHAR(255) NULL COMMENT 'Motivo informado por quem recalculou',
  versao_anterior   INT          NULL,
  motivo            VARCHAR(2000) NULL COMMENT 'O que mudou em relação à versão anterior (calculado)',
  paginas           SMALLINT     NOT NULL DEFAULT 0,
  postos            SMALLINT     NOT NULL DEFAULT 0,
  titulares         SMALLINT     NOT NULL DEFAULT 0,
  buffer            SMALLINT     NOT NULL DEFAULT 0,
  precisa           SMALLINT     NOT NULL DEFAULT 0,
  tem               SMALLINT     NOT NULL DEFAULT 0,
  saldo             SMALLINT     NOT NULL DEFAULT 0,
  contratar_oficial   SMALLINT   NULL COMMENT 'Turmas planejadas no plano oficial',
  contratar_sugerido  SMALLINT   NULL COMMENT 'As mesmas turmas no plano sugerido',
  dif_plano         SMALLINT     NULL COMMENT 'sugerido − oficial',
  fecha_oficial     DATE         NULL COMMENT 'Semana em que a falta fica ≤ falta_fechada com o plano oficial',
  fecha_sugerido    DATE         NULL,
  alerta            TINYINT      NOT NULL DEFAULT 0,
  PRIMARY KEY (versao),
  KEY ix_data (data_calculo, versao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_capacity_versao_unidade (
  versao         INT          NOT NULL,
  unidade        TINYINT      NOT NULL,
  turno          CHAR(1)      NOT NULL,
  postos         SMALLINT     NOT NULL,
  titulares      SMALLINT     NOT NULL,
  faltas_dia     DECIMAL(6,2) NULL,
  pico_dia       DECIMAL(6,2) NULL,
  presentes_dia  DECIMAL(8,4) NULL,
  buffer         SMALLINT     NOT NULL,
  precisa        SMALLINT     NOT NULL,
  tem            SMALLINT     NOT NULL,
  saldo          SMALLINT     NOT NULL,
  PRIMARY KEY (versao, unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_capacity_versao_posto (
  versao      INT          NOT NULL,
  carteira    VARCHAR(8)   NOT NULL,
  turno       CHAR(1)      NOT NULL,
  posto       SMALLINT     NOT NULL,
  pagina      VARCHAR(150) NOT NULL,
  unidade     TINYINT      NOT NULL,
  tipo        ENUM('titular','assistente','double','apoio') NOT NULL,
  carga       DECIMAL(6,1) NULL,
  capacidade  DECIMAL(5,1) NULL,
  fans_h      DECIMAL(6,1) NULL,
  fracao      DECIMAL(6,4) NOT NULL,
  PRIMARY KEY (versao, carteira, turno, posto, pagina),
  KEY ix_pagina (versao, pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_plano_sugerido (
  versao                INT         NOT NULL,
  turma                 VARCHAR(30) NOT NULL,
  data_inicio_trn       DATE        NOT NULL,
  data_inicio_operacao  DATE        NOT NULL,
  turno                 CHAR(1)     NOT NULL,
  planejada             TINYINT     NOT NULL COMMENT '1 = recalculada; 0 = confirmada (fica como no oficial)',
  qtd_oficial           SMALLINT    NOT NULL,
  qtd_sugerida          SMALLINT    NOT NULL,
  PRIMARY KEY (versao, turma, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_plano_projecao (
  versao         INT          NOT NULL,
  inicio_semana  DATE         NOT NULL,
  turno          CHAR(1)      NOT NULL,
  alvo           SMALLINT     NOT NULL,
  hc_oficial     DECIMAL(7,2) NULL,
  hc_sugerido    DECIMAL(7,2) NULL,
  PRIMARY KEY (versao, inicio_semana, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de trabalho do plano sugerido (uma linha por turma)
CREATE TABLE IF NOT EXISTS fc_wrk_plano (
  turma      VARCHAR(30) NOT NULL,
  ini_trn    DATE        NOT NULL,
  opera      DATE        NOT NULL,
  planejada  TINYINT     NOT NULL,
  of_a SMALLINT NOT NULL, of_c SMALLINT NOT NULL, of_d SMALLINT NOT NULL,
  sg_a SMALLINT NOT NULL, sg_c SMALLINT NOT NULL, sg_d SMALLINT NOT NULL,
  PRIMARY KEY (turma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- ---------------------------------------------------------------------
-- 3. Simulação semanal do quadro (mesma conta do sp_fc_prev_to)
--    p_sug = 0: turmas do plano oficial · 1: do sugerido. p_versao > 0 grava a projeção.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_plano_simular $$
CREATE PROCEDURE sp_fc_plano_simular(IN p_ini DATE, IN p_ate DATE, IN p_sug TINYINT, IN p_versao INT,
                                     IN p_h0a DOUBLE, IN p_h0c DOUBLE, IN p_h0d DOUBLE,
                                     IN p_ta DOUBLE, IN p_tc DOUBLE, IN p_td DOUBLE,
                                     IN p_n14 DOUBLE, IN p_n513 DOUBLE,
                                     OUT o_a DOUBLE, OUT o_c DOUBLE, OUT o_d DOUBLE)
BEGIN
  DECLARE v_ws DATE DEFAULT p_ini;
  DECLARE v_ha, v_hc, v_hd DOUBLE;
  DECLARE v_ea, v_ec, v_ed, v_xa, v_xc, v_xd DOUBLE;
  SET v_ha = p_h0a, v_hc = p_h0c, v_hd = p_h0d;

  WHILE v_ws < p_ate DO
    IF p_versao > 0 THEN
      IF p_sug = 0 THEN
        INSERT INTO fc_plano_projecao (versao, inicio_semana, turno, alvo, hc_oficial)
        VALUES (p_versao, v_ws, 'A', 0, v_ha), (p_versao, v_ws, 'C', 0, v_hc), (p_versao, v_ws, 'D', 0, v_hd);
      ELSE
        UPDATE fc_plano_projecao
           SET hc_sugerido = CASE turno WHEN 'A' THEN v_ha WHEN 'C' THEN v_hc ELSE v_hd END
         WHERE versao = p_versao AND inicio_semana = v_ws;
      END IF;
    END IF;

    -- entradas da semana e saídas extras dos novatos (semanas 1 a 13 depois de entrar em operação)
    SELECT COALESCE(SUM(IF(z.opera >= v_ws AND z.opera < v_ws + INTERVAL 7 DAY, z.qa, 0)), 0),
           COALESCE(SUM(IF(z.opera >= v_ws AND z.opera < v_ws + INTERVAL 7 DAY, z.qc, 0)), 0),
           COALESCE(SUM(IF(z.opera >= v_ws AND z.opera < v_ws + INTERVAL 7 DAY, z.qd, 0)), 0),
           COALESCE(SUM(IF(z.sem BETWEEN 1 AND 13, z.qa * GREATEST(IF(z.sem <= 4, p_n14, p_n513) - p_ta, 0), 0)), 0),
           COALESCE(SUM(IF(z.sem BETWEEN 1 AND 13, z.qc * GREATEST(IF(z.sem <= 4, p_n14, p_n513) - p_tc, 0), 0)), 0),
           COALESCE(SUM(IF(z.sem BETWEEN 1 AND 13, z.qd * GREATEST(IF(z.sem <= 4, p_n14, p_n513) - p_td, 0), 0)), 0)
      INTO v_ea, v_ec, v_ed, v_xa, v_xc, v_xd
    FROM (SELECT opera,
                 IF(opera < v_ws, FLOOR(DATEDIFF(v_ws, opera) / 7) + 1, 0) AS sem,
                 IF(p_sug = 1, sg_a, of_a) AS qa, IF(p_sug = 1, sg_c, of_c) AS qc, IF(p_sug = 1, sg_d, of_d) AS qd
          FROM fc_wrk_plano) z;

    SET v_ha = GREATEST(v_ha - (v_ha * p_ta + v_xa) + v_ea, 0);
    SET v_hc = GREATEST(v_hc - (v_hc * p_tc + v_xc) + v_ec, 0);
    SET v_hd = GREATEST(v_hd - (v_hd * p_td + v_xd) + v_ed, 0);
    SET v_ws = v_ws + INTERVAL 7 DAY;
  END WHILE;

  SET o_a = v_ha, o_c = v_hc, o_d = v_hd;
END $$

-- ---------------------------------------------------------------------
-- 4. Plano sugerido de uma versão
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_plano_sugerido $$
CREATE PROCEDURE sp_fc_plano_sugerido(IN p_v INT)
BEGIN
  DECLARE v_data, v_ini, v_nxt, v_op, v_fim_h DATE;
  DECLARE v_tur VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_jan, v_hor, v_teto, v_fim INT DEFAULT 0;
  DECLARE v_ta, v_tc, v_td, v_n14, v_n513, v_des, v_fechada DOUBLE;
  DECLARE v_aa, v_ac, v_ad, v_h0a, v_h0c, v_h0d DOUBLE;
  DECLARE v_ha, v_hc, v_hd, v_na, v_nc, v_nd, v_tot, v_ra, v_rc, v_rd, v_fa, v_fc, v_fd DOUBLE;
  DECLARE v_qa, v_qc, v_qd, v_rem INT;
  DECLARE c_tur CURSOR FOR SELECT turma, opera FROM fc_wrk_plano WHERE planejada = 1 ORDER BY opera, turma;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fim = 1;

  SELECT data_calculo INTO v_data FROM fc_capacity_versao WHERE versao = p_v;
  SET v_ini = v_data - INTERVAL WEEKDAY(v_data) DAY;

  SELECT CAST(COALESCE(MAX(IF(chave = 'janela_to_semanas', valor, NULL)), 12) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'horizonte_semanas', valor, NULL)), 26) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'teto_turma', valor, NULL)), 20) AS SIGNED),
         COALESCE(MAX(IF(chave = 'novato_sem_1_4', valor, NULL)), 0.03),
         COALESCE(MAX(IF(chave = 'novato_sem_5_13', valor, NULL)), 0.0122),
         COALESCE(MAX(IF(chave = 'desistencia_trn', valor, NULL)), 0.06),
         COALESCE(MAX(IF(chave = 'falta_fechada', valor, NULL)), 5)
    INTO v_jan, v_hor, v_teto, v_n14, v_n513, v_des, v_fechada
  FROM fc_parametros;

  -- taxa de saída por pessoa-semana de cada turno (igual ao sp_fc_prev_to)
  SELECT COALESCE(SUM(t.to_a) / NULLIF(SUM(h.hc_a), 0), 0),
         COALESCE(SUM(t.to_c) / NULLIF(SUM(h.hc_c), 0), 0),
         COALESCE(SUM(t.to_d) / NULLIF(SUM(h.hc_d), 0), 0)
    INTO v_ta, v_tc, v_td
  FROM fc_hc_semanal h
  JOIN fc_to_semanal t ON t.ano_iso = h.ano_iso AND t.semana_iso = h.semana_iso
  WHERE h.inicio_semana >= v_ini - INTERVAL (v_jan * 7) DAY AND h.inicio_semana < v_ini;

  -- alvo (precisa) e quadro de partida (tem) da versão
  SELECT COALESCE(SUM(IF(turno = 'A', precisa, 0)), 0), COALESCE(SUM(IF(turno = 'C', precisa, 0)), 0),
         COALESCE(SUM(IF(turno = 'D', precisa, 0)), 0),
         COALESCE(SUM(IF(turno = 'A', tem, 0)), 0), COALESCE(SUM(IF(turno = 'C', tem, 0)), 0),
         COALESCE(SUM(IF(turno = 'D', tem, 0)), 0)
    INTO v_aa, v_ac, v_ad, v_h0a, v_h0c, v_h0d
  FROM fc_capacity_versao_unidade WHERE versao = p_v;

  -- turmas do plano oficial: as planejadas que começam a partir da data são recalculadas
  TRUNCATE TABLE fc_wrk_plano;
  INSERT INTO fc_wrk_plano (turma, ini_trn, opera, planejada, of_a, of_c, of_d, sg_a, sg_c, sg_d)
  SELECT z.turma, z.ini_trn, z.opera, z.planejada, z.qa, z.qc, z.qd,
         IF(z.planejada = 1, 0, z.qa), IF(z.planejada = 1, 0, z.qc), IF(z.planejada = 1, 0, z.qd)
  FROM (SELECT turma, MIN(data_inicio_trn) AS ini_trn, MIN(data_inicio_operacao) AS opera,
               IF(MIN(status = 'PLANEJADA') = 1 AND MIN(data_inicio_trn) >= v_data, 1, 0) AS planejada,
               SUM(IF(turno = 'A', qtd, 0)) AS qa, SUM(IF(turno = 'C', qtd, 0)) AS qc, SUM(IF(turno = 'D', qtd, 0)) AS qd
        FROM fc_contratacoes
        WHERE status <> 'CANCELADA'
        GROUP BY turma) z;

  -- cada turma planejada, em ordem: o que falta para o alvo quando a turma seguinte entra em operação
  OPEN c_tur;
  turmas: LOOP
    FETCH c_tur INTO v_tur, v_op;
    IF v_fim = 1 THEN LEAVE turmas; END IF;

    SELECT COALESCE(MIN(opera), v_op + INTERVAL 14 DAY) INTO v_nxt
    FROM fc_wrk_plano
    WHERE planejada = 1 AND (opera > v_op OR (opera = v_op AND turma > v_tur));

    CALL sp_fc_plano_simular(v_ini, v_nxt, 1, 0, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513,
                             v_ha, v_hc, v_hd);
    SET v_na = GREATEST(0, (v_aa - v_ha) / (1 - v_des));
    SET v_nc = GREATEST(0, (v_ac - v_hc) / (1 - v_des));
    SET v_nd = GREATEST(0, (v_ad - v_hd) / (1 - v_des));
    SET v_tot = v_na + v_nc + v_nd;

    IF v_tot > v_teto THEN
      -- teto da turma dividido pelos turnos na proporção da falta (maiores restos; empate: A, C, D)
      SET v_ra = v_teto * v_na / v_tot, v_rc = v_teto * v_nc / v_tot, v_rd = v_teto * v_nd / v_tot;
      SET v_qa = FLOOR(v_ra), v_qc = FLOOR(v_rc), v_qd = FLOOR(v_rd);
      SET v_fa = v_ra - v_qa, v_fc = v_rc - v_qc, v_fd = v_rd - v_qd;
      SET v_rem = v_teto - v_qa - v_qc - v_qd;
      WHILE v_rem > 0 DO
        IF v_fa >= v_fc AND v_fa >= v_fd THEN SET v_qa = v_qa + 1, v_fa = -1;
        ELSEIF v_fc >= v_fd THEN SET v_qc = v_qc + 1, v_fc = -1;
        ELSE SET v_qd = v_qd + 1, v_fd = -1;
        END IF;
        SET v_rem = v_rem - 1;
      END WHILE;
    ELSE
      SET v_qa = FLOOR(v_na + 0.5), v_qc = FLOOR(v_nc + 0.5), v_qd = FLOOR(v_nd + 0.5);
    END IF;

    UPDATE fc_wrk_plano SET sg_a = v_qa, sg_c = v_qc, sg_d = v_qd WHERE turma = v_tur;
  END LOOP;
  CLOSE c_tur;

  -- resultado: turmas, projeção semanal (até 13 semanas depois da última turma) e resumo da versão
  DELETE FROM fc_plano_sugerido WHERE versao = p_v;
  INSERT INTO fc_plano_sugerido (versao, turma, data_inicio_trn, data_inicio_operacao, turno, planejada, qtd_oficial, qtd_sugerida)
  SELECT p_v, w.turma, w.ini_trn, w.opera, t.turno, w.planejada,
         CASE t.turno WHEN 'A' THEN w.of_a WHEN 'C' THEN w.of_c ELSE w.of_d END,
         CASE t.turno WHEN 'A' THEN w.sg_a WHEN 'C' THEN w.sg_c ELSE w.sg_d END
  FROM fc_wrk_plano w
  CROSS JOIN (SELECT 'A' AS turno UNION ALL SELECT 'C' UNION ALL SELECT 'D') t;

  SELECT GREATEST(COALESCE(MAX(opera), v_ini) + INTERVAL 91 DAY, v_ini + INTERVAL (v_hor * 7) DAY)
    INTO v_fim_h FROM fc_wrk_plano;
  DELETE FROM fc_plano_projecao WHERE versao = p_v;
  CALL sp_fc_plano_simular(v_ini, v_fim_h, 0, p_v, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513,
                           v_ha, v_hc, v_hd);
  CALL sp_fc_plano_simular(v_ini, v_fim_h, 1, p_v, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513,
                           v_ha, v_hc, v_hd);
  UPDATE fc_plano_projecao SET alvo = CASE turno WHEN 'A' THEN v_aa WHEN 'C' THEN v_ac ELSE v_ad END
   WHERE versao = p_v;

  UPDATE fc_capacity_versao v
     SET v.contratar_oficial  = (SELECT COALESCE(SUM(of_a + of_c + of_d), 0) FROM fc_wrk_plano WHERE planejada = 1),
         v.contratar_sugerido = (SELECT COALESCE(SUM(sg_a + sg_c + sg_d), 0) FROM fc_wrk_plano WHERE planejada = 1),
         v.fecha_oficial  = (SELECT MIN(z.inicio_semana) FROM (SELECT inicio_semana, SUM(alvo - hc_oficial) AS f
                              FROM fc_plano_projecao WHERE versao = p_v GROUP BY inicio_semana) z WHERE z.f <= v_fechada),
         v.fecha_sugerido = (SELECT MIN(z.inicio_semana) FROM (SELECT inicio_semana, SUM(alvo - hc_sugerido) AS f
                              FROM fc_plano_projecao WHERE versao = p_v GROUP BY inicio_semana) z WHERE z.f <= v_fechada)
   WHERE v.versao = p_v;
  UPDATE fc_capacity_versao SET dif_plano = contratar_sugerido - contratar_oficial WHERE versao = p_v;
END $$

-- ---------------------------------------------------------------------
-- 5. O que mudou em relação à versão anterior, plano sugerido e alerta
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_versao_fechar $$
CREATE PROCEDURE sp_fc_versao_fechar(IN p_v INT)
BEGIN
  DECLARE v_prev, v_pa, v_pn, v_mud, v_dif, v_lim, v_of, v_sg INT;
  DECLARE v_saiu, v_entrou VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_mot VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

  SET SESSION group_concat_max_len = 4000;
  SELECT MAX(versao) INTO v_prev FROM fc_capacity_versao WHERE versao < p_v;

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
END $$

-- ---------------------------------------------------------------------
-- 6. Gravar a versão de um cálculo (o resultado de sp_fc_postos na data)
-- ---------------------------------------------------------------------
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

  INSERT INTO fc_capacity_versao_posto (versao, carteira, turno, posto, pagina, unidade, tipo, carga, capacidade, fans_h, fracao)
  SELECT v, pp.carteira, pp.turno, pp.posto, pp.pagina, p.unidade, p.tipo, p.carga, p.capacidade, pp.fans_h, pp.fracao
  FROM fc_postos_pagina pp
  JOIN fc_postos p ON p.data_calculo = pp.data_calculo AND p.carteira = pp.carteira
                  AND p.turno = pp.turno AND p.posto = pp.posto
  WHERE pp.data_calculo = p_data;

  CALL sp_fc_versao_fechar(v);
END $$

-- ---------------------------------------------------------------------
-- 7. Recálculo à mão: postos da data + versão com o motivo (usar no lugar de CALL sp_fc_postos)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_recalcular_postos $$
CREATE PROCEDURE sp_fc_recalcular_postos(IN p_data DATE, IN p_obs VARCHAR(255))
BEGIN
  CALL sp_fc_postos(p_data);
  CALL sp_fc_capacity_versao(p_data, 'MANUAL', p_obs);
END $$

-- ---------------------------------------------------------------------
-- 8. Orquestração diária: igual à do 11, com a etapa 'versao' no fim
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
  UPDATE fc_log_execucao SET etapa = 'versao' WHERE id = v_log;
  CALL sp_fc_capacity_versao(v_d, 'DIARIO', NULL);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

-- ---------------------------------------------------------------------
-- 9. Histórico inicial (só na primeira vez)
--    O cálculo de 28/10 não está mais inteiro nas tabelas de resultado (fc_postos de 28/10 ficou com 37
--    postos e sem necessidade). Por isso as duas versões de 28/10 entram reconstruídas, só com as unidades:
--    com a Skylar (base da semana do script 16) e sem a Skylar (resultado do 18, conferido em 28/10).
--    Depois entram 29/10 e 30/10, dos cálculos diários, com os postos.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_tmp_19_historico $$
CREATE PROCEDURE sp_tmp_19_historico()
BEGIN
  DECLARE v1, v2 INT;
  IF NOT EXISTS (SELECT 1 FROM fc_capacity_versao) THEN
    INSERT INTO fc_capacity_versao (data_calculo, gerado_em, origem, obs)
    VALUES ('2026-10-28', '2026-10-28 10:07:25', 'RECONSTRUIDA', 'Base da semana de 01/11, com a Skylar (script 16)');
    SET v1 = LAST_INSERT_ID();
    INSERT INTO fc_capacity_versao_unidade (versao, unidade, turno, postos, titulares, faltas_dia, pico_dia,
                                            presentes_dia, buffer, precisa, tem, saldo) VALUES
     (v1, 1, 'A', 17, 26, 1.62, 2.18, 4.1895, 6, 32, 25,  -7),
     (v1, 1, 'C', 18, 27, 1.91, 1.96, 4.3263, 7, 34, 20, -14),
     (v1, 1, 'D', 16, 24, 1.85, 1.62, 3.9262, 6, 30, 21,  -9),
     (v1, 2, 'A', 18, 27, 1.71, 2.18, 4.2944, 7, 34, 28,  -6),
     (v1, 2, 'C', 18, 27, 1.91, 2.69, 5.1454, 8, 35, 25, -10),
     (v1, 2, 'D', 20, 30, 2.31, 1.77, 4.6158, 7, 37, 30,  -7),
     (v1, 3, 'A', 21, 32, 2.00, 1.56, 3.9251, 6, 38, 23, -15),
     (v1, 3, 'C', 22, 33, 2.33, 1.75, 4.5654, 7, 40, 24, -16),
     (v1, 3, 'D', 22, 33, 2.54, 2.22, 5.3794, 8, 41, 24, -17);

    INSERT INTO fc_capacity_versao (data_calculo, gerado_em, origem, obs)
    VALUES ('2026-10-28', '2026-10-28 20:50:00', 'RECONSTRUIDA', 'Skylar Mae VIP saiu (script 18)');
    SET v2 = LAST_INSERT_ID();
    INSERT INTO fc_capacity_versao_unidade (versao, unidade, turno, postos, titulares, faltas_dia, pico_dia,
                                            presentes_dia, buffer, precisa, tem, saldo) VALUES
     (v2, 1, 'A', 13, 20, 1.24, 1.82, 3.3724, 6, 26, 25,  -1),
     (v2, 1, 'C', 14, 21, 1.48, 1.35, 3.1729, 6, 27, 20,  -7),
     (v2, 1, 'D', 12, 18, 1.39, 1.62, 3.4038, 6, 24, 21,  -3),
     (v2, 2, 'A', 18, 27, 1.71, 2.18, 4.2944, 6, 33, 28,  -5),
     (v2, 2, 'C', 18, 27, 1.91, 2.69, 5.1454, 7, 34, 25,  -9),
     (v2, 2, 'D', 20, 30, 2.31, 1.77, 4.6158, 7, 37, 30,  -7),
     (v2, 3, 'A', 21, 32, 2.00, 1.56, 3.9251, 6, 38, 23, -15),
     (v2, 3, 'C', 22, 33, 2.33, 1.75, 4.5654, 7, 40, 24, -16),
     (v2, 3, 'D', 22, 33, 2.54, 2.22, 5.3794, 8, 41, 24, -17);

    UPDATE fc_capacity_versao v
    JOIN (SELECT versao, SUM(postos) AS postos, SUM(titulares) AS tit, SUM(buffer) AS buf, SUM(precisa) AS prec,
                 SUM(tem) AS tem, SUM(saldo) AS saldo
          FROM fc_capacity_versao_unidade WHERE versao IN (v1, v2) GROUP BY versao) u ON u.versao = v.versao
       SET v.postos = u.postos, v.titulares = u.tit, v.buffer = u.buf, v.precisa = u.prec, v.tem = u.tem,
           v.saldo = u.saldo;
    CALL sp_fc_versao_fechar(v1);
    CALL sp_fc_versao_fechar(v2);

    -- cálculos diários de 29/10 e 30/10 (ainda nas tabelas de resultado)
    CALL sp_fc_capacity_versao('2026-10-29', 'DIARIO', NULL);
    UPDATE fc_capacity_versao SET gerado_em = '2026-10-29 06:30:00' WHERE data_calculo = '2026-10-29';
    CALL sp_fc_capacity_versao('2026-10-30', 'DIARIO', NULL);
    UPDATE fc_capacity_versao SET gerado_em = '2026-10-30 06:30:00' WHERE data_calculo = '2026-10-30';
  END IF;
END $$

DELIMITER ;

CALL sp_tmp_19_historico();
DROP PROCEDURE IF EXISTS sp_tmp_19_historico;

-- ---------------------------------------------------------------------
-- 10. Visões
-- ---------------------------------------------------------------------
-- (13) Versões do capacity, da mais nova para a mais antiga
CREATE OR REPLACE VIEW vw_fc_13_capacity_versoes AS
SELECT v.versao AS `Versão`, v.data_calculo AS `Data do cálculo`, v.gerado_em AS `Gerada em`, v.origem AS `Origem`,
       v.postos AS `Postos`, v.titulares AS `Titulares`, v.buffer AS `Buffer`, v.precisa AS `Precisa`,
       v.precisa - a.precisa AS `Δ precisa`, v.tem AS `Tem`, v.saldo AS `Saldo`,
       v.contratar_oficial AS `Planejadas (oficial)`, v.contratar_sugerido AS `Planejadas (sugerido)`,
       v.dif_plano AS `Diferença`, v.fecha_oficial AS `Fecha (oficial)`, v.fecha_sugerido AS `Fecha (sugerido)`,
       IF(v.alerta = 1, 'REVISAR PLANO', '') AS `Alerta`, v.obs AS `Observação`, v.motivo AS `O que mudou`
FROM fc_capacity_versao v
LEFT JOIN fc_capacity_versao a ON a.versao = v.versao_anterior
ORDER BY v.versao DESC;

-- (14) Última versão contra a anterior, por unidade e turno
CREATE OR REPLACE VIEW vw_fc_14_capacity_mudancas AS
SELECT CONCAT('U', n.unidade) AS `Unidade`, n.turno AS `Turno`,
       a.postos AS `Postos antes`, n.postos AS `Postos agora`,
       a.titulares AS `Titulares antes`, n.titulares AS `Titulares agora`,
       a.buffer AS `Buffer antes`, n.buffer AS `Buffer agora`,
       a.precisa AS `Precisa antes`, n.precisa AS `Precisa agora`, n.precisa - a.precisa AS `Δ precisa`,
       v.versao_anterior AS `Versão anterior`, v.versao AS `Versão atual`
FROM fc_capacity_versao v
JOIN fc_capacity_versao_unidade n ON n.versao = v.versao
LEFT JOIN fc_capacity_versao_unidade a ON a.versao = v.versao_anterior AND a.unidade = n.unidade AND a.turno = n.turno
WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao)
ORDER BY n.unidade, FIELD(n.turno, 'A', 'C', 'D');

-- (15) Última versão contra a anterior: páginas que mudaram de postos em algum turno
CREATE OR REPLACE VIEW vw_fc_15_capacity_paginas AS
SELECT z.pagina AS `Página`, z.turno AS `Turno`, ROUND(SUM(z.antes), 2) AS `Postos antes`,
       ROUND(SUM(z.agora), 2) AS `Postos agora`, ROUND(SUM(z.agora) - SUM(z.antes), 2) AS `Diferença`
FROM (SELECT p.pagina, p.turno, p.fracao AS antes, 0 AS agora
      FROM fc_capacity_versao_posto p
      WHERE p.versao = (SELECT versao_anterior FROM fc_capacity_versao WHERE versao = (SELECT MAX(versao) FROM fc_capacity_versao))
      UNION ALL
      SELECT p.pagina, p.turno, 0, p.fracao
      FROM fc_capacity_versao_posto p
      WHERE p.versao = (SELECT MAX(versao) FROM fc_capacity_versao)) z
GROUP BY z.pagina, z.turno
HAVING ROUND(SUM(z.agora), 2) <> ROUND(SUM(z.antes), 2)
ORDER BY ABS(SUM(z.agora) - SUM(z.antes)) DESC, z.pagina, z.turno;

-- (16) Plano oficial contra o sugerido da última versão, turma a turma
CREATE OR REPLACE VIEW vw_fc_16_plano_oficial_vs_sugerido AS
SELECT s.turma AS `Turma`, s.data_inicio_operacao AS `Opera em`,
       IF(MAX(s.planejada) = 1, 'Planejada', 'Confirmada') AS `Situação`,
       SUM(IF(s.turno = 'A', s.qtd_oficial, 0)) AS `A oficial`, SUM(IF(s.turno = 'C', s.qtd_oficial, 0)) AS `C oficial`,
       SUM(IF(s.turno = 'D', s.qtd_oficial, 0)) AS `D oficial`, SUM(s.qtd_oficial) AS `Total oficial`,
       SUM(IF(s.turno = 'A', s.qtd_sugerida, 0)) AS `A sugerido`, SUM(IF(s.turno = 'C', s.qtd_sugerida, 0)) AS `C sugerido`,
       SUM(IF(s.turno = 'D', s.qtd_sugerida, 0)) AS `D sugerido`, SUM(s.qtd_sugerida) AS `Total sugerido`,
       SUM(s.qtd_sugerida) - SUM(s.qtd_oficial) AS `Diferença`,
       IF(MAX(s.planejada) = 1 AND ABS(SUM(s.qtd_sugerida) - SUM(s.qtd_oficial)) >=
          (SELECT COALESCE(MAX(valor), 3) FROM fc_parametros WHERE chave = 'alerta_dif_turma'), 'REVISAR', '') AS `Revisar`,
       s.versao AS `Versão`
FROM fc_plano_sugerido s
WHERE s.versao = (SELECT MAX(versao) FROM fc_capacity_versao)
GROUP BY s.versao, s.turma, s.data_inicio_operacao
ORDER BY s.data_inicio_operacao, s.turma;

-- (17) Projeção semanal do quadro da última versão: plano oficial e sugerido contra o "precisa"
CREATE OR REPLACE VIEW vw_fc_17_projecao_quadro AS
SELECT p.inicio_semana AS `Semana`, SUM(p.alvo) AS `Precisa`,
       ROUND(SUM(p.hc_oficial), 1) AS `Quadro (oficial)`, ROUND(SUM(p.alvo - p.hc_oficial), 1) AS `Falta (oficial)`,
       ROUND(SUM(p.hc_sugerido), 1) AS `Quadro (sugerido)`, ROUND(SUM(p.alvo - p.hc_sugerido), 1) AS `Falta (sugerido)`,
       ROUND(SUM(IF(p.turno = 'A', p.alvo - p.hc_oficial, 0)), 1) AS `Falta A (oficial)`,
       ROUND(SUM(IF(p.turno = 'C', p.alvo - p.hc_oficial, 0)), 1) AS `Falta C (oficial)`,
       ROUND(SUM(IF(p.turno = 'D', p.alvo - p.hc_oficial, 0)), 1) AS `Falta D (oficial)`,
       p.versao AS `Versão`
FROM fc_plano_projecao p
WHERE p.versao = (SELECT MAX(versao) FROM fc_capacity_versao)
GROUP BY p.versao, p.inicio_semana
ORDER BY p.inicio_semana;

-- ---------- Conferência (rodando em 30/10) ----------
-- 11.1 Versões: esperado 4 linhas, precisa 321, 300, 294 e 291, todas com alerta "REVISAR PLANO".
--      28/10 sem a Skylar: "Precisa 321 → 300 (−21)". 30/10: páginas que mudaram de 29 para 30/10 e "Precisa 294 → 291 (−3)".
SELECT `Versão`, `Data do cálculo`, `Origem`, `Postos`, `Precisa`, `Δ precisa`,
       `Planejadas (oficial)`, `Planejadas (sugerido)`, `Fecha (oficial)`, `Fecha (sugerido)`, `Alerta`, `O que mudou`
FROM vw_fc_13_capacity_versoes;

-- 11.2 Plano sugerido de cada versão contra o espelho em Python (todas 'ok')
SELECT v.versao AS `Versão`, v.precisa AS `Precisa`, v.contratar_oficial AS `Oficial`, e.of AS `Esp. oficial`,
       v.contratar_sugerido AS `Sugerido`, e.sg AS `Esp. sugerido`, v.fecha_oficial AS `Fecha oficial`,
       e.fo AS `Esp. fecha oficial`, v.fecha_sugerido AS `Fecha sugerido`, e.fs AS `Esp. fecha sugerido`,
       IF(v.contratar_oficial = e.of AND v.contratar_sugerido = e.sg AND v.fecha_oficial = e.fo AND v.fecha_sugerido = e.fs,
          'ok', 'DIFERENTE') AS `Confere`
FROM fc_capacity_versao v
JOIN (SELECT 321 AS prec, 280 AS of, 239 AS sg, DATE '2027-05-10' AS fo, DATE '2027-05-10' AS fs UNION ALL
      SELECT 300, 280, 214, DATE '2027-04-12', DATE '2027-04-12' UNION ALL
      SELECT 294, 280, 207, DATE '2027-03-29', DATE '2027-03-29' UNION ALL
      SELECT 291, 280, 202, DATE '2027-03-29', DATE '2027-03-29') e ON e.prec = v.precisa
ORDER BY v.versao;

-- 11.3 Turmas da última versão (30/10). Esperado no sugerido, A/C/D:
--      07/12 5/8/7 · 11/01 4/8/8 · 25/01 5/8/7 · 08/02 4/8/8 · 22/02 5/8/7 · 08/03 4/8/8 · 22/03 4/6/7 ·
--      05/04 2/4/3 · 19/04 2/3/4 · 03/05 1/4/4 · 17/05 2/4/4 · 31/05 2/4/4 · 14/06 2/3/3 · 28/06 2/4/4
SELECT * FROM vw_fc_16_plano_oficial_vs_sugerido;

-- 11.4 Quadro projetado em 01/03/2027 (esperado: oficial 94,05 / 87,63 / 86,63 · sugerido 88,47 / 91,28 / 88,52)
SELECT turno AS `Turno`, hc_oficial AS `Oficial`, hc_sugerido AS `Sugerido`
FROM fc_plano_projecao
WHERE versao = (SELECT MAX(versao) FROM fc_capacity_versao) AND inicio_semana = '2027-03-01'
ORDER BY FIELD(turno, 'A', 'C', 'D');

-- 11.5 Mudanças da última versão (30/10 contra 29/10), por unidade e por página
SELECT * FROM vw_fc_14_capacity_mudancas;
SELECT * FROM vw_fc_15_capacity_paginas;
