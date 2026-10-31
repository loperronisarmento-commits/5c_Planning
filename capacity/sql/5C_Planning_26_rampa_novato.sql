-- =====================================================================
-- 5C | Forecast de WFM — 26. Rampa do novato no plano de turmas (50% nos primeiros 30 dias de operação)
-- Schema: 5c_planning        31/10/2026        Rodar depois do 25.
--
-- Pedido do usuário em 31/10, repassado pela sessão Simulations (mesma regra da página Simulações 5C):
--   nos primeiros rampa_novato_dias (30) de operação, cada novato rende rampa_novato_rend (0,50) de um rep.
--   O quadro e as saídas não mudam; muda a falta: precisa − (quadro − Σ novatos na rampa × (1 − rendimento)).
--   No modelo semanal: as semanas 1 a 4 depois do início da operação (7 a 28 dias) contam pela metade.
--   O 50% é o meio da faixa de 30–70% de um turno regular dos níveis 0 e 1 (estudo de 27/10).
-- O que muda:
--   - parâmetros rampa_novato_dias e rampa_novato_rend (rampa_novato_rend = 1 desliga a rampa);
--   - fc_plano_projecao ganha o quadro que rende (rende_oficial e rende_sugerido);
--   - sp_fc_plano_simular e sp_fc_plano_sugerido dimensionam cada turma pela falta com a rampa, e a data em que
--     a falta fecha passa a usar o quadro que rende;
--   - vw_fc_17_projecao_quadro mostra a falta pelo quadro que rende.
-- Não muda o plano oficial (fc_contratacoes): só refaz o sugerido da última versão para comparar.
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- No Navicat: "Nova consulta" e executar tudo (o DELIMITER é aceito).
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Parâmetros
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('rampa_novato_dias', 30,   'Dias de operação em que o novato ainda está na rampa (o plano conta as semanas 1 a 4)'),
 ('rampa_novato_rend', 0.50, 'Quanto o novato na rampa rende de um rep (1 = sem rampa)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- 2. Colunas novas na projeção (só se ainda não existem)
SET @tem_rende = (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fc_plano_projecao' AND COLUMN_NAME = 'rende_oficial');
SET @sql_26 = IF(@tem_rende = 0,
  'ALTER TABLE fc_plano_projecao ADD COLUMN rende_oficial DECIMAL(7,2) NULL COMMENT ''quadro que rende: novatos na rampa contam rampa_novato_rend'', ADD COLUMN rende_sugerido DECIMAL(7,2) NULL',
  'SELECT 1');
PREPARE st FROM @sql_26;
EXECUTE st;
DEALLOCATE PREPARE st;

DELIMITER $$

-- 3. Simulação e plano sugerido com a rampa (iguais ao 19)
DROP PROCEDURE IF EXISTS sp_fc_plano_simular $$
CREATE PROCEDURE sp_fc_plano_simular(IN p_ini DATE, IN p_ate DATE, IN p_sug TINYINT, IN p_versao INT,
                                     IN p_h0a DOUBLE, IN p_h0c DOUBLE, IN p_h0d DOUBLE,
                                     IN p_ta DOUBLE, IN p_tc DOUBLE, IN p_td DOUBLE,
                                     IN p_n14 DOUBLE, IN p_n513 DOUBLE, IN p_rdias INT, IN p_rend DOUBLE,
                                     OUT o_a DOUBLE, OUT o_c DOUBLE, OUT o_d DOUBLE)
BEGIN
  DECLARE v_ws DATE DEFAULT p_ini;
  DECLARE v_ha, v_hc, v_hd DOUBLE;
  DECLARE v_ea, v_ec, v_ed, v_xa, v_xc, v_xd DOUBLE;
  DECLARE v_ma, v_mc, v_md DOUBLE;
  DECLARE v_ra, v_rc, v_rd DOUBLE;
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

    -- rampa do novato (script 26): turmas com 7 a p_rdias dias de operação rendem p_rend de um rep
    SELECT COALESCE(SUM(IF(opera < v_ws AND DATEDIFF(v_ws, opera) <= p_rdias, IF(p_sug = 1, sg_a, of_a) * (1 - p_rend), 0)), 0),
           COALESCE(SUM(IF(opera < v_ws AND DATEDIFF(v_ws, opera) <= p_rdias, IF(p_sug = 1, sg_c, of_c) * (1 - p_rend), 0)), 0),
           COALESCE(SUM(IF(opera < v_ws AND DATEDIFF(v_ws, opera) <= p_rdias, IF(p_sug = 1, sg_d, of_d) * (1 - p_rend), 0)), 0)
      INTO v_ra, v_rc, v_rd
    FROM fc_wrk_plano;

    IF p_versao > 0 THEN
      IF p_sug = 0 THEN
        INSERT INTO fc_plano_projecao (versao, inicio_semana, turno, alvo, hc_oficial, rende_oficial)
        VALUES (p_versao, v_ws, 'A', 0, v_ha, v_ha - v_ra), (p_versao, v_ws, 'C', 0, v_hc, v_hc - v_rc),
               (p_versao, v_ws, 'D', 0, v_hd, v_hd - v_rd);
      ELSE
        UPDATE fc_plano_projecao
           SET hc_sugerido = CASE turno WHEN 'A' THEN v_ha WHEN 'C' THEN v_hc ELSE v_hd END,
               rende_sugerido = CASE turno WHEN 'A' THEN v_ha - v_ra WHEN 'C' THEN v_hc - v_rc ELSE v_hd - v_rd END
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

  -- devolve o quadro que rende na data final (quadro menos a rampa das turmas recentes)
  SELECT COALESCE(SUM(IF(opera < p_ate AND DATEDIFF(p_ate, opera) <= p_rdias, IF(p_sug = 1, sg_a, of_a) * (1 - p_rend), 0)), 0),
         COALESCE(SUM(IF(opera < p_ate AND DATEDIFF(p_ate, opera) <= p_rdias, IF(p_sug = 1, sg_c, of_c) * (1 - p_rend), 0)), 0),
         COALESCE(SUM(IF(opera < p_ate AND DATEDIFF(p_ate, opera) <= p_rdias, IF(p_sug = 1, sg_d, of_d) * (1 - p_rend), 0)), 0)
    INTO v_ra, v_rc, v_rd
  FROM fc_wrk_plano;
  SET o_a = v_ha - v_ra, o_c = v_hc - v_rc, o_d = v_hd - v_rd;
END $$

DROP PROCEDURE IF EXISTS sp_fc_plano_sugerido $$
CREATE PROCEDURE sp_fc_plano_sugerido(IN p_v INT)
BEGIN
  DECLARE v_data, v_ini, v_nxt, v_op, v_fim_h DATE;
  DECLARE v_tur VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE v_jan, v_hor, v_teto, v_tt, v_rdias, v_fim INT DEFAULT 0;
  DECLARE v_rend DOUBLE;
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
         COALESCE(MAX(IF(chave = 'falta_fechada', valor, NULL)), 5),
         CAST(COALESCE(MAX(IF(chave = 'rampa_novato_dias', valor, NULL)), 0) AS SIGNED),
         COALESCE(MAX(IF(chave = 'rampa_novato_rend', valor, NULL)), 1)
    INTO v_jan, v_hor, v_teto, v_tt, v_n14, v_n513, v_des, v_fechada, v_rdias, v_rend
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

    CALL sp_fc_plano_simular(v_ini, v_nxt, 1, 0, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513, v_rdias, v_rend,
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
  CALL sp_fc_plano_simular(v_ini, v_fim_h, 0, p_v, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513, v_rdias, v_rend,
                           v_ha, v_hc, v_hd);
  CALL sp_fc_plano_simular(v_ini, v_fim_h, 1, p_v, v_h0a, v_h0c, v_h0d, v_ta, v_tc, v_td, v_n14, v_n513, v_rdias, v_rend,
                           v_ha, v_hc, v_hd);
  UPDATE fc_plano_projecao SET alvo = CASE turno WHEN 'A' THEN v_aa WHEN 'C' THEN v_ac ELSE v_ad END
   WHERE versao = p_v;

  UPDATE fc_capacity_versao v
     SET v.contratar_oficial  = (SELECT COALESCE(SUM(of_a + of_c + of_d), 0) FROM fc_wrk_plano WHERE planejada = 1),
         v.contratar_sugerido = (SELECT COALESCE(SUM(sg_a + sg_c + sg_d), 0) FROM fc_wrk_plano WHERE planejada = 1),
         v.fecha_oficial  = (SELECT MIN(z.inicio_semana) FROM (SELECT inicio_semana, SUM(alvo - COALESCE(rende_oficial, hc_oficial)) AS f
                              FROM fc_plano_projecao WHERE versao = p_v GROUP BY inicio_semana) z WHERE z.f <= v_fechada),
         v.fecha_sugerido = (SELECT MIN(z.inicio_semana) FROM (SELECT inicio_semana, SUM(alvo - COALESCE(rende_sugerido, hc_sugerido)) AS f
                              FROM fc_plano_projecao WHERE versao = p_v GROUP BY inicio_semana) z WHERE z.f <= v_fechada)
   WHERE v.versao = p_v;
  UPDATE fc_capacity_versao SET dif_plano = contratar_sugerido - contratar_oficial WHERE versao = p_v;
END $$

DELIMITER ;

-- 4. Visão da projeção com a falta pelo quadro que rende
CREATE OR REPLACE VIEW vw_fc_17_projecao_quadro AS
SELECT p.inicio_semana AS `Semana`, SUM(p.alvo) AS `Precisa`,
       -- a falta usa o quadro que rende (com a rampa do novato, script 26)
       ROUND(SUM(p.hc_oficial), 1) AS `Quadro (oficial)`, ROUND(SUM(COALESCE(p.rende_oficial, p.hc_oficial)), 1) AS `Rende (oficial)`,
       ROUND(SUM(p.alvo - COALESCE(p.rende_oficial, p.hc_oficial)), 1) AS `Falta (oficial)`,
       ROUND(SUM(p.hc_sugerido), 1) AS `Quadro (sugerido)`,
       ROUND(SUM(p.alvo - COALESCE(p.rende_sugerido, p.hc_sugerido)), 1) AS `Falta (sugerido)`,
       ROUND(SUM(IF(p.turno = 'A', p.alvo - COALESCE(p.rende_oficial, p.hc_oficial), 0)), 1) AS `Falta A (oficial)`,
       ROUND(SUM(IF(p.turno = 'C', p.alvo - COALESCE(p.rende_oficial, p.hc_oficial), 0)), 1) AS `Falta C (oficial)`,
       ROUND(SUM(IF(p.turno = 'D', p.alvo - COALESCE(p.rende_oficial, p.hc_oficial), 0)), 1) AS `Falta D (oficial)`,
       p.versao AS `Versão`
FROM fc_plano_projecao p
WHERE p.versao = (SELECT MAX(versao) FROM fc_capacity_versao)
GROUP BY p.versao, p.inicio_semana
ORDER BY p.inicio_semana;

-- 5. Refaz o plano sugerido da última versão com a rampa (o oficial não muda)
SET @v = (SELECT MAX(versao) FROM fc_capacity_versao);
CALL sp_fc_plano_sugerido(@v);
UPDATE fc_capacity_versao
   SET motivo = LEFT(CONCAT_WS(' · ', motivo, 'Plano sugerido refeito com a rampa do novato de 50% em 30 dias (script 26)'), 2000)
 WHERE versao = @v;

-- ---------- Conferência (rodando em 31/10, versão 17) ----------
-- 6.1 Resumo: esperado oficial 211 e sugerido 212 nas turmas que o plano refaz (sem 02/11, 16/11 e 08/02, que são
--     confirmadas ou fixa); a falta fecha em 12/04/2027 nos dois (sem a rampa, 15/03/2027)
SELECT versao AS `Versão`, precisa AS `Precisa`, contratar_oficial AS `Oficial`, contratar_sugerido AS `Sugerido`,
       dif_plano AS `Diferença`, fecha_oficial AS `Fecha (oficial)`, fecha_sugerido AS `Fecha (sugerido)`, alerta AS `Alerta`
FROM fc_capacity_versao WHERE versao = @v;

-- 6.2 Turma a turma. Esperado no sugerido, A/C/D:
--     07/12 10/10/10 · 11/01 10/10/10 · 25/01 8/10/10 · 08/02 10/10/10 (fixa) · 22/02 3/10/10 · 08/03 0/10/10 ·
--     22/03 0/7/10 · 05/04 1/2/4 · 19/04 3/1/1 · 03/05 3/4/3 · 17/05 2/5/5 · 31/05 1/5/5 · 14/06 2/3/5 · 28/06 2/3/4
SELECT * FROM vw_fc_16_plano_oficial_vs_sugerido;

-- 6.3 Quadro e quadro que rende em 01/03/2027 no oficial (esperado A 108,79/98,79 · C 99,58/89,58 · D 97,58/87,58)
SELECT turno AS `Turno`, hc_oficial AS `Quadro`, rende_oficial AS `Rende`
FROM fc_plano_projecao WHERE versao = @v AND inicio_semana = '2027-03-01'
ORDER BY FIELD(turno, 'A', 'C', 'D');

-- 6.4 Projeção semanal (falta pelo quadro que rende)
SELECT * FROM vw_fc_17_projecao_quadro;

-- ---------------------------------------------------------------------
-- 7. Desligar a rampa (só se precisar): rendimento 1 e refazer o sugerido
-- ---------------------------------------------------------------------
-- UPDATE fc_parametros SET valor = 1 WHERE chave = 'rampa_novato_rend';
-- CALL sp_fc_plano_sugerido((SELECT MAX(versao) FROM fc_capacity_versao));
