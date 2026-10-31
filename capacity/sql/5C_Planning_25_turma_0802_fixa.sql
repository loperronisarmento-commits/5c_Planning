-- =====================================================================
-- 5C | Forecast de WFM — 25. Turma de 08/02/2027 com 10/10/10, fixa
-- Schema: 5c_planning        31/10/2026        Rodar depois do 24.
--
-- Pedido do usuário em 31/10, repassado pela sessão Simulations: a turma de 08/02/2027 passa de 4/10/10 para
-- 10/10/10 (só o turno A, de 4 para 10). Na simulação da página, que conta o novato rendendo 50% nos primeiros
-- 30 dias, a falta fica abaixo de 5 em 07/04, contra 21/04 com o plano anterior.
-- Para o plano sugerido (refeito em cada versão) não voltar a turma para 4 no A, a turma fica fixa:
--   - coluna nova fc_contratacoes.fixa (1 = quantidade decidida à mão);
--   - o sp_fc_plano_sugerido trata turma fixa como confirmada: não refaz e conta como já decidida;
--   - na vw_fc_16_plano_oficial_vs_sugerido ela aparece como "Fixa".
-- As outras turmas não mudam. Depois recalcula o dia.
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Coluna nova (só se ainda não existe)
SET @tem_fixa = (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_contratacoes' AND COLUMN_NAME = 'fixa');
SET @sql_25 = IF(@tem_fixa = 0,
  'ALTER TABLE fc_contratacoes ADD COLUMN fixa TINYINT NOT NULL DEFAULT 0 COMMENT ''1 = quantidade decidida à mão (o plano sugerido não refaz)''',
  'SELECT 1');
PREPARE st FROM @sql_25;
EXECUTE st;
DEALLOCATE PREPARE st;

-- 2. Cópia de segurança (só na primeira vez)
CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_plano_25 AS SELECT * FROM fc_contratacoes;

-- 3. Turma de 08/02/2027: A 4 → 10, e a turma fica fixa
START TRANSACTION;
UPDATE fc_contratacoes
   SET qtd = 10, obs = 'Ajuste manual de 31/10: 10/10/10 (pedido do usuário pela Simulations)'
 WHERE turma = '2027-02-08' AND turno = 'A' AND status = 'PLANEJADA';
UPDATE fc_contratacoes SET fixa = 1 WHERE turma = '2027-02-08' AND status = 'PLANEJADA';
COMMIT;

DELIMITER $$

-- 4. Plano sugerido: turma fixa é tratada como confirmada (igual ao 19)
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

  -- turmas do plano oficial: as planejadas que começam a partir da data são recalculadas (menos as fixas)
  TRUNCATE TABLE fc_wrk_plano;
  INSERT INTO fc_wrk_plano (turma, ini_trn, opera, planejada, of_a, of_c, of_d, sg_a, sg_c, sg_d)
  SELECT z.turma, z.ini_trn, z.opera, z.planejada, z.qa, z.qc, z.qd,
         IF(z.planejada = 1, 0, z.qa), IF(z.planejada = 1, 0, z.qc), IF(z.planejada = 1, 0, z.qd)
  FROM (SELECT turma, MIN(data_inicio_trn) AS ini_trn, MIN(data_inicio_operacao) AS opera,
               -- fixa = 1 (script 25): turma planejada com quantidade decidida à mão, que o sugerido não refaz
               IF(MIN(status = 'PLANEJADA') = 1 AND MIN(data_inicio_trn) >= v_data AND MAX(fixa) = 0, 1, 0) AS planejada,
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

DELIMITER ;

-- 5. Visão do plano com a situação "Fixa"
CREATE OR REPLACE VIEW vw_fc_16_plano_oficial_vs_sugerido AS
SELECT s.turma AS `Turma`, s.data_inicio_operacao AS `Opera em`,
       CASE WHEN MAX(s.planejada) = 1 THEN 'Planejada'
            WHEN EXISTS (SELECT 1 FROM fc_contratacoes c WHERE c.turma = s.turma AND c.fixa = 1) THEN 'Fixa'
            ELSE 'Confirmada' END AS `Situação`,
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

-- 6. Recalcular o dia (previsão de turnover, capacity e versão nova com o plano)
CALL sp_fc_rodar_dia();

-- ---------- Conferência ----------
-- 7.1 Turma de 08/02/2027: esperado A 10 · C 10 · D 10, fixa nas 3 linhas
SELECT turma AS `Turma`, turno AS `Turno`, qtd AS `Qtd`, status AS `Status`, fixa AS `Fixa`, obs AS `Obs`
FROM fc_contratacoes WHERE turma = '2027-02-08' ORDER BY turno;

-- 7.2 Totais: esperado CONFIRMADA 35 (2 turmas) · PLANEJADA 241 (14 turmas) · total 276
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 7.3 A última execução tem que estar 'OK' na etapa 'fim'
SELECT data_calculo, etapa, inicio, fim, status, mensagem
FROM fc_log_execucao ORDER BY id DESC LIMIT 1;

-- 7.4 Plano oficial contra o sugerido na versão nova: 08/02 aparece como "Fixa".
--     As turmas depois dela podem sair um pouco menores no sugerido, porque o A já tem 6 a mais.
SELECT * FROM vw_fc_16_plano_oficial_vs_sugerido;

-- ---------------------------------------------------------------------
-- 8. Desfazer (só se precisar): volta 08/02 para 4/10/10 e tira a fixação
-- ---------------------------------------------------------------------
-- UPDATE fc_contratacoes SET qtd = 4 WHERE turma = '2027-02-08' AND turno = 'A' AND status = 'PLANEJADA';
-- UPDATE fc_contratacoes SET fixa = 0 WHERE turma = '2027-02-08';
-- CALL sp_fc_rodar_dia();
