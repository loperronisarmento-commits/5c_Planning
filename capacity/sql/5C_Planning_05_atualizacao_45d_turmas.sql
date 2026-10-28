-- =====================================================================
-- 5C | Forecast de WFM — 05. Atualização de 19/10/2026 (rodar uma vez, no schema já instalado)
--  1) Desligado também quem está no cadastro, mas sem escala há 45+ dias
--     (afastados: registrar em fc_desligamento_motivo com tipo AFASTADO, que sai da contagem)
--  2) Turmas: 02/11 = 9 C + 6 D (pedido feito) e plano de 19/11, 03/12, 17/12 e 05/01
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

ALTER TABLE fc_desligamentos MODIFY origem ENUM('HEADCOUNT','ESCALA','SEM_ESCALA_CADASTRO') NOT NULL
  COMMENT 'HEADCOUNT = saiu do cadastro; ESCALA = sem escala há 14+ dias e fora do cadastro; SEM_ESCALA_CADASTRO = no cadastro, sem escala há 45+ dias';
ALTER TABLE fc_desligamento_motivo MODIFY tipo ENUM('VOLUNTARIO','INVOLUNTARIO','AFASTADO','NAO_DESLIGADO') NOT NULL
  COMMENT 'AFASTADO / NAO_DESLIGADO tiram a pessoa da contagem de desligamentos';

INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('dias_sem_escala_cadastro', 45, 'Dias sem escala para considerar desligado quem ainda está no cadastro (afastados: corrigir em fc_desligamento_motivo)')
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

DELIMITER ;

-- recalcula hoje com a regra nova
CALL sp_fc_rodar_dia();

SELECT origem, COUNT(*) AS pessoas, MIN(data_saida) AS primeira, MAX(data_saida) AS ultima
FROM fc_desligamentos GROUP BY origem;
SELECT code, nome, data_saida, turno FROM fc_desligamentos WHERE origem = 'SEM_ESCALA_CADASTRO' ORDER BY data_saida DESC;
SELECT mes, SUM(to_a) AS a, SUM(to_c) AS c, SUM(to_d) AS d, SUM(to_total) AS total
FROM (SELECT DATE_FORMAT(inicio_semana,'%Y-%m') AS mes, to_a, to_c, to_d, to_total
      FROM fc_to_semanal WHERE inicio_semana >= '2026-04-01') z
GROUP BY mes ORDER BY mes;
SELECT * FROM vw_fc_3_prev_to_atual LIMIT 12;
