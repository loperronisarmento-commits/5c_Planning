-- =====================================================================
-- 5C | Forecast de WFM — 21. Plano oficial com teto de 10 por turno (30 por turma) e troca de 5 reps do C para o A
-- Schema: 5c_planning        30/10/2026        Rodar depois do 20.
--
-- Decisão do usuário em 30/10 (repassada pela sessão Simulations): "Teto de 30 pessoas, sendo 10 por turno",
-- a partir da turma de 07/12, e 5 reps passam do C para o A.
--   1. Parâmetros: teto_turma = 30 e teto_turma_turno = 10 (eram 20 por turma, sem teto por turno).
--   2. fc_plano_troca_turno: a troca C → A entra na simulação na data @data_troca. Se a data não for
--      27/10, troque abaixo. Quando o cadastro da estrutura (fc_estrutura_rep) mostrar a troca, marque
--      ativo = 0, senão ela conta duas vezes.
--   3. sp_fc_plano_simular e sp_fc_plano_sugerido com o teto por turno e as trocas, e sp_fc_versao_fechar
--      comparando com a última versão de data igual ou anterior (iguais aos do 19 novo).
--   4. Refaz o plano sugerido da última versão do capacity e adota como plano oficial: as turmas planejadas
--      de fc_contratacoes (07/12 a 28/06) passam a ser as do sugerido. As confirmadas de 02/11 e 16/11 ficam.
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

SET @data_troca = '2026-10-27';

-- 1. Parâmetros
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('teto_turma',       30, 'Máximo de pessoas por turma no plano sugerido (capacidade do TRN)'),
 ('teto_turma_turno', 10, 'Máximo de pessoas por turno em cada turma do plano sugerido')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- 2. Troca de turno
-- Trocas de turno que o "tem" (fc_estrutura_rep) ainda não mostra: entram na simulação na data informada.
-- Quando o cadastro da estrutura passar a mostrar a troca, marque ativo = 0 (senão ela conta duas vezes).
CREATE TABLE IF NOT EXISTS fc_plano_troca_turno (
  data        DATE         NOT NULL,
  turno_de    CHAR(1)      NOT NULL,
  turno_para  CHAR(1)      NOT NULL,
  qtd         SMALLINT     NOT NULL,
  ativo       TINYINT      NOT NULL DEFAULT 1,
  obs         VARCHAR(255) NULL,
  PRIMARY KEY (data, turno_de, turno_para)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM fc_plano_troca_turno WHERE obs = 'Script 21: 5 reps do C para o A';
INSERT INTO fc_plano_troca_turno (data, turno_de, turno_para, qtd, ativo, obs)
VALUES (@data_troca, 'C', 'A', 5, 1, 'Script 21: 5 reps do C para o A');

DELIMITER $$

-- ---------------------------------------------------------------------
-- 3a. Simulação semanal do quadro (mesma conta do sp_fc_prev_to)
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
  DECLARE v_ma, v_mc, v_md DOUBLE;
  SET v_ha = p_h0a, v_hc = p_h0c, v_hd = p_h0d;

  -- trocas de turno anteriores ao início entram no quadro de partida
  SELECT COALESCE(SUM(IF(turno_para = 'A', qtd, 0) - IF(turno_de = 'A', qtd, 0)), 0),
         COALESCE(SUM(IF(turno_para = 'C', qtd, 0) - IF(turno_de = 'C', qtd, 0)), 0),
         COALESCE(SUM(IF(turno_para = 'D', qtd, 0) - IF(turno_de = 'D', qtd, 0)), 0)
    INTO v_ma, v_mc, v_md
  FROM fc_plano_troca_turno WHERE ativo = 1 AND data < p_ini;
  SET v_ha = v_ha + v_ma, v_hc = v_hc + v_mc, v_hd = v_hd + v_md;

  WHILE v_ws < p_ate DO
    -- trocas de turno da semana
    SELECT COALESCE(SUM(IF(turno_para = 'A', qtd, 0) - IF(turno_de = 'A', qtd, 0)), 0),
           COALESCE(SUM(IF(turno_para = 'C', qtd, 0) - IF(turno_de = 'C', qtd, 0)), 0),
           COALESCE(SUM(IF(turno_para = 'D', qtd, 0) - IF(turno_de = 'D', qtd, 0)), 0)
      INTO v_ma, v_mc, v_md
    FROM fc_plano_troca_turno WHERE ativo = 1 AND data >= v_ws AND data < v_ws + INTERVAL 7 DAY;
    SET v_ha = v_ha + v_ma, v_hc = v_hc + v_mc, v_hd = v_hd + v_md;

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
-- 3b. Plano sugerido de uma versão
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_plano_sugerido $$
CREATE PROCEDURE sp_fc_plano_sugerido(IN p_v INT)
BEGIN
  DECLARE v_data, v_ini, v_nxt, v_op, v_fim_h DATE;
  DECLARE v_tur VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_jan, v_hor, v_teto, v_tt, v_fim INT DEFAULT 0;
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
         CAST(COALESCE(MAX(IF(chave = 'teto_turma', valor, NULL)), 30) AS SIGNED),
         CAST(COALESCE(MAX(IF(chave = 'teto_turma_turno', valor, NULL)), 10) AS SIGNED),
         COALESCE(MAX(IF(chave = 'novato_sem_1_4', valor, NULL)), 0.03),
         COALESCE(MAX(IF(chave = 'novato_sem_5_13', valor, NULL)), 0.0122),
         COALESCE(MAX(IF(chave = 'desistencia_trn', valor, NULL)), 0.06),
         COALESCE(MAX(IF(chave = 'falta_fechada', valor, NULL)), 5)
    INTO v_jan, v_hor, v_teto, v_tt, v_n14, v_n513, v_des, v_fechada
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
    -- falta de cada turno, com a desistência no treinamento, até o teto do turno
    SET v_na = LEAST(GREATEST(0, (v_aa - v_ha) / (1 - v_des)), v_tt);
    SET v_nc = LEAST(GREATEST(0, (v_ac - v_hc) / (1 - v_des)), v_tt);
    SET v_nd = LEAST(GREATEST(0, (v_ad - v_hd) / (1 - v_des)), v_tt);
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
      SET v_qa = LEAST(FLOOR(v_na + 0.5), v_tt), v_qc = LEAST(FLOOR(v_nc + 0.5), v_tt), v_qd = LEAST(FLOOR(v_nd + 0.5), v_tt);
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
-- 3c. O que mudou em relação à versão anterior, plano sugerido e alerta
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_versao_fechar $$
CREATE PROCEDURE sp_fc_versao_fechar(IN p_v INT)
BEGIN
  DECLARE v_prev, v_pa, v_pn, v_mud, v_dif, v_lim, v_of, v_sg INT;
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
END $$

DELIMITER ;

-- 4. Plano sugerido da última versão com as regras novas, adotado como oficial
SET @v = (SELECT MAX(versao) FROM fc_capacity_versao);
CALL sp_fc_plano_sugerido(@v);

CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_plano_21 AS SELECT * FROM fc_contratacoes;

START TRANSACTION;

DELETE FROM fc_contratacoes
 WHERE status = 'PLANEJADA'
   AND turma IN (SELECT turma FROM fc_plano_sugerido WHERE versao = @v AND planejada = 1);

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT s.turma, s.data_inicio_trn, s.data_inicio_operacao, s.turno, s.qtd_sugerida, 'PLANEJADA',
       CONCAT('Plano sugerido da versão ', @v, ' (precisa ', v.precisa, '), teto 10 por turno e troca C→A, adotado em 30/10')
FROM fc_plano_sugerido s
JOIN fc_capacity_versao v ON v.versao = s.versao
WHERE s.versao = @v AND s.planejada = 1;   -- turno com 0 fica com linha 0: o calendário da turma não se perde

COMMIT;

-- previsão de turnover com o plano novo e comparação da versão (oficial = sugerido, sem alerta)
CALL sp_fc_prev_to(CURDATE());
CALL sp_fc_plano_sugerido(@v);
UPDATE fc_capacity_versao
   SET alerta = 0,
       motivo = LEFT(CONCAT_WS(' · ', motivo, 'Plano refeito com teto de 10 por turno e troca de 5 reps C→A, adotado como oficial (script 21)'), 2000)
 WHERE versao = @v;

-- ---------- Conferência (rodando em 30/10, versão 5) ----------
-- 5.1 Turmas: oficial igual ao sugerido, nenhum turno acima de 10 (todas 'ok').
--     Esperado A/C/D: 07/12 10/10/10 · 11/01 7/10/10 · 25/01 2/10/10 · 08/02 2/10/10 · 22/02 2/10/8 · 08/03 2/5/4 ·
--     22/03 2/4/3 · 05/04 2/3/4 · 19/04 2/4/4 · 03/05 2/4/4 · 17/05 1/4/4 · 31/05 2/3/4 · 14/06 2/4/4 · 28/06 2/4/3
SELECT `Turma`, `A oficial`, `C oficial`, `D oficial`, `Total oficial`, `Total sugerido`,
       IF(`Total oficial` = `Total sugerido` AND GREATEST(`A oficial`, `C oficial`, `D oficial`) <= 10, 'ok', 'DIFERENTE') AS `Confere`
FROM vw_fc_16_plano_oficial_vs_sugerido;

-- 5.2 Totais: esperado CONFIRMADA 35 (2 turmas) · PLANEJADA 207 (14 turmas) · total 242
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 5.3 Versão: esperado versão 5, precisa 293, oficial 207, sugerido 207, diferença 0, falta fecha em 15/03/2027
SELECT `Versão`, `Precisa`, `Planejadas (oficial)`, `Planejadas (sugerido)`, `Diferença`,
       `Fecha (oficial)`, `Fecha (sugerido)`, `Alerta`, `O que mudou`
FROM vw_fc_13_capacity_versoes LIMIT 1;

-- 5.4 Quadro projetado em 01/03/2027 com a troca (esperado 94,82 / 94,98 / 97,58)
SELECT turno AS `Turno`, hc_oficial AS `Quadro`
FROM fc_plano_projecao WHERE versao = @v AND inicio_semana = '2027-03-01'
ORDER BY FIELD(turno, 'A', 'C', 'D');

-- ---------------------------------------------------------------------
-- 6. Desfazer (só se precisar): volta o plano do script 20 e o teto de 20 por turma
-- ---------------------------------------------------------------------
-- UPDATE fc_parametros SET valor = 20 WHERE chave = 'teto_turma';
-- UPDATE fc_parametros SET valor = 99 WHERE chave = 'teto_turma_turno';
-- UPDATE fc_plano_troca_turno SET ativo = 0 WHERE obs = 'Script 21: 5 reps do C para o A';
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes WHERE status = 'PLANEJADA';
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
-- SELECT turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
-- FROM fc_contratacoes_bkp_plano_21 WHERE status = 'PLANEJADA';
-- COMMIT;
-- CALL sp_fc_rodar_dia();
