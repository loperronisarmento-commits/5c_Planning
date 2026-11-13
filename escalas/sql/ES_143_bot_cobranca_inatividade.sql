SET NAMES utf8mb4;
-- =====================================================================
-- ES_143_bot_cobranca_inatividade.sql  (11/11/2026, pedido do usuário)
--
-- Relatório "Bot de cobrança" (5C Escalas 1.29.0, seção Análise): o bot de cobrança dos SRs entrou em
-- 06/10/2026. Para comparar antes e depois, a inatividade é reconstruída das mensagens com a mesma
-- regra do bot (5c_bot.vw_alerta_inatividade): página escalada sem mensagem de funcionário por
-- 15 min ou mais. Os alertas do próprio bot (5c_bot.alerta_historico) começam em 02/10 e mudam de
-- patamar em 02/11, por isso não servem para o "antes".
--
-- Regra da reconstrução (por semana, segunda a domingo):
--   - bloco = página × bloco de 4 h do 5C Schedule (scheduleday, faltou = 0, titular ou assistente);
--   - mensagens = minutos com mensagem enviada por funcionário (5c_wfm_db.msgdashboard,
--     SOURCE = 'Employee', STATUS = 'sent'), pelo nome da página no msgdashboard (5c_bot.page_alias);
--   - lacunas = diferença entre os pontos (início do bloco, minutos com mensagem, fim do bloco);
--     episódio = lacuna de 15 min ou mais; bloco sem nenhuma mensagem fica à parte (lacuna de 240).
--   - por SR: os episódios do bloco contam para cada SR escalado nele.
--
-- 1. Tabelas esc_bot_inativ_semana (semana × turno) e esc_bot_inativ_rep (semana × SR).
-- 2. Procedimento esc_sp_bot_inatividade(segunda): recalcula uma semana (~1 min).
-- 3. Carga das semanas de 31/08 a 02/11 (as fechadas). A tela calcula sozinha as semanas que
--    fecharem depois (uma por acesso).
-- Desfazer: DROP PROCEDURE esc_sp_bot_inatividade; DROP TABLE esc_bot_inativ_rep, esc_bot_inativ_semana.
-- Rodar pelo mysql.exe com o 5c.cnf (DELIMITER e CALL).
-- =====================================================================

USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_bot_inativ_semana (
  semana               DATE      NOT NULL COMMENT 'segunda-feira',
  turno                CHAR(1)   NOT NULL COMMENT 'A (blocos de 5 e 9 h), C (13 e 17 h), D (21 e 1 h)',
  blocos               INT       NOT NULL COMMENT 'página × bloco de 4 h escalado',
  episodios            INT       NOT NULL COMMENT 'lacunas de 15 min ou mais (com e sem mensagem no bloco)',
  min_inativos         INT       NOT NULL,
  blocos_com_inativ    INT       NOT NULL,
  episodios_30         INT       NOT NULL COMMENT 'lacunas de 30 a 239 min (o bloco teve mensagem)',
  blocos_sem_msg       INT       NOT NULL COMMENT 'bloco inteiro sem mensagem',
  episodios_com_msg    INT       NOT NULL COMMENT 'lacunas de 15 a 239 min (o bloco teve mensagem)',
  min_inativos_com_msg INT       NOT NULL,
  calculado_em         DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (semana, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inatividade semanal reconstruída (regra do bot de cobrança)';

CREATE TABLE IF NOT EXISTS esc_bot_inativ_rep (
  semana               DATE        NOT NULL,
  rep_code             VARCHAR(30) NOT NULL COMMENT 'scheduleday.codigo',
  blocos               INT         NOT NULL,
  episodios_com_msg    INT         NOT NULL,
  min_inativos_com_msg INT         NOT NULL,
  blocos_sem_msg       INT         NOT NULL,
  PRIMARY KEY (semana, rep_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inatividade semanal por SR (blocos em que estava escalado)';

DELIMITER $$
DROP PROCEDURE IF EXISTS esc_sp_bot_inatividade $$
CREATE PROCEDURE esc_sp_bot_inatividade(IN p_de DATE)
BEGIN
  DECLARE v_ate DATE;
  SET p_de = p_de - INTERVAL WEEKDAY(p_de) DAY, v_ate = p_de + INTERVAL 7 DAY;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_rep;
  CREATE TEMPORARY TABLE tmp_bi_rep (
    pid INT NOT NULL, ini DATETIME NOT NULL, rep VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL,
    creator VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY (pid, ini, rep));
  INSERT IGNORE INTO tmp_bi_rep (pid, ini, rep, creator)
  SELECT s.modeloId, STR_TO_DATE(s.shiftDateAndHour, '%Y-%m-%dT%H:%i:%s'), UPPER(s.codigo), a.valor
  FROM `5c_schedule`.scheduleday s
  JOIN `5c_bot`.page_alias a ON a.page_id = s.modeloId AND a.sistema = 'msgdashboard' AND a.valor <> ''
  WHERE s.shiftDateAndHour >= DATE_FORMAT(p_de, '%Y-%m-%d') COLLATE utf8mb4_unicode_ci
    AND s.shiftDateAndHour < DATE_FORMAT(v_ate, '%Y-%m-%d') COLLATE utf8mb4_unicode_ci
    AND s.faltou = 0 AND s.codigo IS NOT NULL AND s.codigo <> '';

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_blk;
  CREATE TEMPORARY TABLE tmp_bi_blk (
    pid INT NOT NULL, ini DATETIME NOT NULL, creator VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY (pid, ini));
  INSERT IGNORE INTO tmp_bi_blk (pid, ini, creator) SELECT pid, ini, creator FROM tmp_bi_rep;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_msg;
  CREATE TEMPORARY TABLE tmp_bi_msg (
    creator VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL, mi DATETIME NOT NULL, PRIMARY KEY (creator, mi));
  INSERT IGNORE INTO tmp_bi_msg (creator, mi)
  SELECT m.CREATOR, DATE_FORMAT(m.DATE_TIME, '%Y-%m-%d %H:%i:00')
  FROM `5c_wfm_db`.msgdashboard m
  WHERE m.DATE_TIME >= p_de AND m.DATE_TIME < v_ate + INTERVAL 4 HOUR
    AND m.SOURCE = 'Employee' AND m.STATUS = 'sent' AND m.CREATOR IS NOT NULL;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_pts;
  CREATE TEMPORARY TABLE tmp_bi_pts (pid INT NOT NULL, ini DATETIME NOT NULL, t DATETIME NOT NULL, KEY (pid, ini, t));
  INSERT INTO tmp_bi_pts (pid, ini, t)
  SELECT b.pid, b.ini, m.mi FROM tmp_bi_blk b
  JOIN tmp_bi_msg m ON m.creator = b.creator AND m.mi >= b.ini AND m.mi < b.ini + INTERVAL 4 HOUR;
  INSERT INTO tmp_bi_pts (pid, ini, t) SELECT pid, ini, ini FROM tmp_bi_blk;
  INSERT INTO tmp_bi_pts (pid, ini, t) SELECT pid, ini, ini + INTERVAL 4 HOUR FROM tmp_bi_blk;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_gap;
  CREATE TEMPORARY TABLE tmp_bi_gap (pid INT NOT NULL, ini DATETIME NOT NULL, g INT NULL, KEY (pid, ini));
  INSERT INTO tmp_bi_gap (pid, ini, g)
  SELECT pid, ini, TIMESTAMPDIFF(MINUTE, LAG(t) OVER (PARTITION BY pid, ini ORDER BY t), t) FROM tmp_bi_pts;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_bloco;
  CREATE TEMPORARY TABLE tmp_bi_bloco (
    pid INT NOT NULL, ini DATETIME NOT NULL, epi INT NOT NULL, mins INT NOT NULL, epi30 INT NOT NULL,
    sem_msg TINYINT NOT NULL, epi_msg INT NOT NULL, mins_msg INT NOT NULL, PRIMARY KEY (pid, ini));
  INSERT INTO tmp_bi_bloco
  SELECT pid, ini, SUM(g >= 15), SUM(IF(g >= 15, g, 0)), SUM(g >= 30 AND g < 240), MAX(g >= 240),
         SUM(g >= 15 AND g < 240), SUM(IF(g >= 15 AND g < 240, g, 0))
  FROM tmp_bi_gap WHERE g IS NOT NULL GROUP BY pid, ini;

  DELETE FROM esc_bot_inativ_semana WHERE semana = p_de;
  INSERT INTO esc_bot_inativ_semana (semana, turno, blocos, episodios, min_inativos, blocos_com_inativ, episodios_30,
                                     blocos_sem_msg, episodios_com_msg, min_inativos_com_msg)
  SELECT p_de, CASE WHEN HOUR(ini) IN (5, 9) THEN 'A' WHEN HOUR(ini) IN (13, 17) THEN 'C' ELSE 'D' END AS tu,
         COUNT(*), SUM(epi), SUM(mins), SUM(epi > 0), SUM(epi30), SUM(sem_msg), SUM(epi_msg), SUM(mins_msg)
  FROM tmp_bi_bloco GROUP BY tu;

  DELETE FROM esc_bot_inativ_rep WHERE semana = p_de;
  INSERT INTO esc_bot_inativ_rep (semana, rep_code, blocos, episodios_com_msg, min_inativos_com_msg, blocos_sem_msg)
  SELECT p_de, r.rep, COUNT(*), SUM(b.epi_msg), SUM(b.mins_msg), SUM(b.sem_msg)
  FROM tmp_bi_rep r JOIN tmp_bi_bloco b ON b.pid = r.pid AND b.ini = r.ini
  GROUP BY r.rep;

  DROP TEMPORARY TABLE IF EXISTS tmp_bi_rep;
  DROP TEMPORARY TABLE IF EXISTS tmp_bi_blk;
  DROP TEMPORARY TABLE IF EXISTS tmp_bi_msg;
  DROP TEMPORARY TABLE IF EXISTS tmp_bi_pts;
  DROP TEMPORARY TABLE IF EXISTS tmp_bi_gap;
  DROP TEMPORARY TABLE IF EXISTS tmp_bi_bloco;
END $$
DELIMITER ;

-- 3. Carga das semanas fechadas
SELECT 'antes' AS momento, COUNT(*) AS linhas FROM esc_bot_inativ_semana;
CALL esc_sp_bot_inatividade('2026-08-31');
CALL esc_sp_bot_inatividade('2026-09-07');
CALL esc_sp_bot_inatividade('2026-09-14');
CALL esc_sp_bot_inatividade('2026-09-21');
CALL esc_sp_bot_inatividade('2026-09-28');
CALL esc_sp_bot_inatividade('2026-10-05');
CALL esc_sp_bot_inatividade('2026-10-12');
CALL esc_sp_bot_inatividade('2026-10-19');
CALL esc_sp_bot_inatividade('2026-10-26');
CALL esc_sp_bot_inatividade('2026-11-02');
SELECT 'depois' AS momento, semana, SUM(blocos) AS blocos, SUM(episodios_com_msg) AS episodios, SUM(min_inativos_com_msg) AS minutos,
       SUM(blocos_sem_msg) AS sem_msg, COUNT(*) AS turnos
  FROM esc_bot_inativ_semana GROUP BY semana ORDER BY semana;
SELECT 'depois' AS momento, COUNT(*) AS linhas_rep, COUNT(DISTINCT rep_code) AS reps FROM esc_bot_inativ_rep;
