-- =====================================================================
-- 5C | Falhas Administrativas (AF) — atualizar `peso_falha_adm`
-- Rodar no Navicat (servidor .11), schema 5c_planning, como luis.sarmento.
-- Base: AF_02_parecer.md (20/10/2026, janela 25/08–19/10).
--
--   peso_falha_adm      0,53 -> 0,37  (global; fallback se o passo 3 não rodar)
--   peso_falha_adm_A    0,22  (novo, turno A = T2/T3)
--   peso_falha_adm_C    0,24  (novo, turno C = T4/T5)
--   peso_falha_adm_D    0,54  (novo, turno D = T6/T1)
--
-- O peso é lido no `sp_fc_buffer` (chamado pelo `sp_fc_rodar_dia`, que
-- NÃO muda). O passo 3 troca o `sp_fc_buffer` para ler o peso por turno;
-- sem as chaves _A/_C/_D ele usa o global, então é compatível nos dois sentidos.
--
-- Válido enquanto a sp_abs_adh_af_processar continuar com os defeitos
-- D1–D4 do parecer. Quando ela for corrigida, recalibrar (AF real -> ~0).
--
-- Ordem: 0 foto -> 1 backup -> 2 parâmetros (transação) -> 3 procedure
--        -> 4 recalcular e conferir. DESFAZER no fim.
-- =====================================================================
SET NAMES utf8mb4;
SET @lote = NOW();

-- ---------------------------------------------------------------------
-- 0. Fotografia antes
-- ---------------------------------------------------------------------
SELECT * FROM `5c_planning`.`fc_parametros` WHERE chave LIKE 'peso_falha_adm%';

SELECT data_calculo,
       ROUND(AVG(taxa_abs_a) * 100, 2) AS abs_a, ROUND(AVG(taxa_abs_c) * 100, 2) AS abs_c,
       ROUND(AVG(taxa_abs_d) * 100, 2) AS abs_d,
       ROUND(AVG(abs_a), 2) AS buf_abs_a, ROUND(AVG(abs_c), 2) AS buf_abs_c, ROUND(AVG(abs_d), 2) AS buf_abs_d
FROM `5c_planning`.`fc_buffer_diario`
GROUP BY data_calculo
ORDER BY data_calculo DESC
LIMIT 2;
-- Esperado (19/10): 10,42 / 12,00 / 11,02

-- ---------------------------------------------------------------------
-- 1. Backup (nada se perde)
--    1a. parâmetros, por lote
--    1b. corpo atual do sp_fc_buffer (o texto completo também está no DESFAZER)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `5c_planning`.`fc_parametros_bkp_af` (
  `lote`          DATETIME     NOT NULL,
  `chave`         VARCHAR(40)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor`         DECIMAL(12,4) NOT NULL,
  `descricao`     VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `atualizado_em` TIMESTAMP    NULL,
  PRIMARY KEY (`lote`, `chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT = 'Backup de fc_parametros antes de cada mudança de peso_falha_adm (AF_03)';

INSERT INTO `5c_planning`.`fc_parametros_bkp_af` (lote, chave, valor, descricao, atualizado_em)
SELECT @lote, chave, valor, descricao, atualizado_em
FROM `5c_planning`.`fc_parametros`;

CREATE TABLE IF NOT EXISTS `5c_planning`.`fc_rotinas_bkp_af` (
  `lote`        DATETIME    NOT NULL,
  `rotina`      VARCHAR(64) NOT NULL,
  `definicao`   LONGTEXT    NOT NULL,
  PRIMARY KEY (`lote`, `rotina`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT = 'Corpo das procedures antes da troca (AF_03)';

INSERT INTO `5c_planning`.`fc_rotinas_bkp_af` (lote, rotina, definicao)
SELECT @lote, ROUTINE_NAME, ROUTINE_DEFINITION
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = '5c_planning' AND ROUTINE_NAME = 'sp_fc_buffer';

SELECT lote, COUNT(*) AS linhas FROM `5c_planning`.`fc_parametros_bkp_af` GROUP BY lote ORDER BY lote DESC LIMIT 3;
-- Anotar o lote acima para o DESFAZER.

-- ---------------------------------------------------------------------
-- 2. Novos pesos (chave/valor, PK = chave)
-- ---------------------------------------------------------------------
START TRANSACTION;

UPDATE `5c_planning`.`fc_parametros`
   SET valor     = 0.3700,
       descricao = 'Fração das AF que é ausência de fato (global; fallback dos pesos por turno). Parecer AF 20/10: 6% AF real, 29% trabalhou no slot, 34% escala dobrada, 31% falta'
 WHERE chave = 'peso_falha_adm';

INSERT INTO `5c_planning`.`fc_parametros` (chave, valor, descricao) VALUES
  ('peso_falha_adm_A', 0.2200, 'AF no absenteísmo, turno A (T2/T3) — parecer AF 20/10, dobra = 0'),
  ('peso_falha_adm_C', 0.2400, 'AF no absenteísmo, turno C (T4/T5) — parecer AF 20/10, dobra = 0'),
  ('peso_falha_adm_D', 0.5400, 'AF no absenteísmo, turno D (T6/T1) — parecer AF 20/10, dobra = 0 (D = falta do turno inteiro)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- Conferir antes de confirmar: 4 linhas, 0,37 / 0,22 / 0,24 / 0,54
SELECT chave, valor, descricao FROM `5c_planning`.`fc_parametros` WHERE chave LIKE 'peso_falha_adm%' ORDER BY chave;

COMMIT;
-- ROLLBACK;   -- em vez de COMMIT, se algo estiver errado

-- ---------------------------------------------------------------------
-- 3. sp_fc_buffer passa a ler o peso por turno
--    Única mudança: v_paf_a/c/d (fallback = peso_falha_adm) e a expressão
--    de `f` nas duas taxas. Resto idêntico ao original de 19/10.
--    DDL faz COMMIT implícito: rodar este bloco sozinho.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `5c_planning`.`sp_fc_buffer`;

DELIMITER $$
CREATE PROCEDURE `5c_planning`.`sp_fc_buffer`(IN p_data DATE)
BEGIN
  DECLARE v_jan INT DEFAULT 56;
  DECLARE v_prazo, v_pres DECIMAL(10,4);
  DECLARE v_ga, v_gc, v_gd DECIMAL(8,5) DEFAULT 0;
  DECLARE v_paf DECIMAL(6,4) DEFAULT 1;
  DECLARE v_paf_a, v_paf_c, v_paf_d DECIMAL(6,4) DEFAULT 1;   -- AF 20/10: peso por turno
  SELECT CAST(valor AS SIGNED) INTO v_jan FROM fc_parametros WHERE chave = 'janela_abs_dias';
  SELECT COALESCE(MAX(valor),1) INTO v_paf FROM fc_parametros WHERE chave = 'peso_falha_adm';
  SELECT COALESCE(MAX(valor),v_paf) INTO v_paf_a FROM fc_parametros WHERE chave = 'peso_falha_adm_A';
  SELECT COALESCE(MAX(valor),v_paf) INTO v_paf_c FROM fc_parametros WHERE chave = 'peso_falha_adm_C';
  SELECT COALESCE(MAX(valor),v_paf) INTO v_paf_d FROM fc_parametros WHERE chave = 'peso_falha_adm_D';
  SELECT valor INTO v_prazo FROM fc_parametros WHERE chave = 'prazo_reposicao_dias';
  SELECT valor INTO v_pres  FROM fc_parametros WHERE chave = 'presenca_3x1';

  -- taxa geral do turno (fallback)
  SELECT COALESCE(SUM(IF(tu='A', f, 0))/NULLIF(SUM(tu='A'),0),0),
         COALESCE(SUM(IF(tu='C', f, 0))/NULLIF(SUM(tu='C'),0),0),
         COALESCE(SUM(IF(tu='D', f, 0))/NULLIF(SUM(tu='D'),0),0)
    INTO v_ga, v_gc, v_gd
  FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
               (a.classificacao = 'ABS')
             + (a.classificacao = 'AF') * IF(a.hora IN (5,9), v_paf_a, IF(a.hora IN (13,17), v_paf_c, v_paf_d)) AS f,
               IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
        FROM `5c_wfm_db_mis`.abs_adh_af a
        WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
  WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data;

  -- taxa por turno e dia da semana (usa a geral quando há menos de 100 turnos na célula)
  TRUNCATE TABLE fc_wrk_abs_taxa;
  INSERT INTO fc_wrk_abs_taxa (dow, ta, tc, td)
  SELECT c.dia_semana,
         IF(SUM(tu='A') >= 100, SUM(IF(tu='A', f, 0))/SUM(tu='A'), v_ga),
         IF(SUM(tu='C') >= 100, SUM(IF(tu='C', f, 0))/SUM(tu='C'), v_gc),
         IF(SUM(tu='D') >= 100, SUM(IF(tu='D', f, 0))/SUM(tu='D'), v_gd)
  FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
               (a.classificacao = 'ABS')
             + (a.classificacao = 'AF') * IF(a.hora IN (5,9), v_paf_a, IF(a.hora IN (13,17), v_paf_c, v_paf_d)) AS f,
               IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
        FROM `5c_wfm_db_mis`.abs_adh_af a
        WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
  JOIN fc_calendario c ON c.dia = z.td
  WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data
  GROUP BY c.dia_semana;

  DELETE FROM fc_buffer_diario WHERE data_calculo = p_data;
  INSERT INTO fc_buffer_diario (data_calculo, dia, a, c, d, total, abs_a, abs_c, abs_d, to_a, to_c, to_d,
                                taxa_abs_a, taxa_abs_c, taxa_abs_d)
  SELECT p_data, y.dia, y.abs_a + y.to_a, y.abs_c + y.to_c, y.abs_d + y.to_d,
         y.abs_a + y.to_a + y.abs_c + y.to_c + y.abs_d + y.to_d,
         y.abs_a, y.abs_c, y.abs_d, y.to_a, y.to_c, y.to_d, y.ra, y.rc, y.rd
  FROM (SELECT e.dia,
               CEIL(e.a * x.ra / (1 - x.ra)) AS abs_a,
               CEIL(e.c * x.rc / (1 - x.rc)) AS abs_c,
               CEIL(e.d * x.rd / (1 - x.rd)) AS abs_d,
               CEIL(COALESCE(pt.prev_to_a,0) * v_prazo / 7 * v_pres) AS to_a,
               CEIL(COALESCE(pt.prev_to_c,0) * v_prazo / 7 * v_pres) AS to_c,
               CEIL(COALESCE(pt.prev_to_d,0) * v_prazo / 7 * v_pres) AS to_d,
               x.ra, x.rc, x.rd
        FROM fc_escala_diaria e
        JOIN fc_calendario c ON c.dia = e.dia
        JOIN (SELECT k.dia_semana AS dow, COALESCE(t.ta, v_ga) AS ra, COALESCE(t.tc, v_gc) AS rc, COALESCE(t.td, v_gd) AS rd
              FROM (SELECT DISTINCT dia_semana FROM fc_calendario) k
              LEFT JOIN fc_wrk_abs_taxa t ON t.dow = k.dia_semana) x ON x.dow = c.dia_semana
        LEFT JOIN fc_prev_to_semanal pt ON pt.data_calculo = p_data
                                       AND pt.ano_iso = c.ano_iso AND pt.semana_iso = c.semana_iso
        WHERE e.data_calculo = p_data) y;
END$$
DELIMITER ;

SHOW CREATE PROCEDURE `5c_planning`.`sp_fc_buffer`;

-- ---------------------------------------------------------------------
-- 4. Recalcular o forecast do dia e conferir
-- ---------------------------------------------------------------------
CALL `5c_planning`.`sp_fc_rodar_dia`();
SELECT * FROM `5c_planning`.`fc_log_execucao` ORDER BY id DESC LIMIT 3;   -- status = OK

SELECT data_calculo,
       ROUND(AVG(taxa_abs_a) * 100, 2) AS abs_a, ROUND(AVG(taxa_abs_c) * 100, 2) AS abs_c,
       ROUND(AVG(taxa_abs_d) * 100, 2) AS abs_d,
       ROUND(AVG(abs_a), 2) AS buf_abs_a, ROUND(AVG(abs_c), 2) AS buf_abs_c, ROUND(AVG(abs_d), 2) AS buf_abs_d
FROM `5c_planning`.`fc_buffer_diario`
GROUP BY data_calculo
ORDER BY data_calculo DESC
LIMIT 2;
-- Esperado hoje: abs A ~9,2% · C ~10,4% · D ~11,1% (varia um pouco com o
-- dia novo que entra na janela de 56 dias). Se só o passo 2 rodar (sem o 3),
-- o esperado é A ~9,8% · C ~11,1% · D ~9,8% (peso global 0,37).

SELECT * FROM `5c_planning`.`vw_fc_plano_semanal`;

-- =====================================================================
-- DESFAZER
-- =====================================================================
-- (a) Parâmetros: restaura o lote anotado no passo 1 e apaga as chaves novas
-- SET @lote_restaurar = '2026-10-20 00:00:00';   -- <- trocar pelo lote do passo 1
-- START TRANSACTION;
-- DELETE FROM `5c_planning`.`fc_parametros`
--  WHERE chave IN ('peso_falha_adm_A','peso_falha_adm_C','peso_falha_adm_D');
-- UPDATE `5c_planning`.`fc_parametros` p
--   JOIN `5c_planning`.`fc_parametros_bkp_af` b
--     ON b.chave = p.chave AND b.lote = @lote_restaurar
--    SET p.valor = b.valor, p.descricao = b.descricao
--  WHERE p.chave = 'peso_falha_adm';
-- SELECT * FROM `5c_planning`.`fc_parametros` WHERE chave LIKE 'peso_falha_adm%';   -- 0,53
-- COMMIT;
--
-- (b) Procedure: volta o sp_fc_buffer original (19/10). Opcional: a versão
--     nova sem as chaves _A/_C/_D já se comporta igual à original.
-- DROP PROCEDURE IF EXISTS `5c_planning`.`sp_fc_buffer`;
-- DELIMITER $$
-- CREATE PROCEDURE `5c_planning`.`sp_fc_buffer`(IN p_data DATE)
-- BEGIN
--   DECLARE v_jan INT DEFAULT 56;
--   DECLARE v_prazo, v_pres DECIMAL(10,4);
--   DECLARE v_ga, v_gc, v_gd DECIMAL(8,5) DEFAULT 0;
--   DECLARE v_paf DECIMAL(6,4) DEFAULT 1;
--   SELECT CAST(valor AS SIGNED) INTO v_jan FROM fc_parametros WHERE chave = 'janela_abs_dias';
--   SELECT COALESCE(MAX(valor),1) INTO v_paf FROM fc_parametros WHERE chave = 'peso_falha_adm';
--   SELECT valor INTO v_prazo FROM fc_parametros WHERE chave = 'prazo_reposicao_dias';
--   SELECT valor INTO v_pres  FROM fc_parametros WHERE chave = 'presenca_3x1';
--   SELECT COALESCE(SUM(IF(tu='A', f, 0))/NULLIF(SUM(tu='A'),0),0),
--          COALESCE(SUM(IF(tu='C', f, 0))/NULLIF(SUM(tu='C'),0),0),
--          COALESCE(SUM(IF(tu='D', f, 0))/NULLIF(SUM(tu='D'),0),0)
--     INTO v_ga, v_gc, v_gd
--   FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
--                (a.classificacao = 'ABS') + (a.classificacao = 'AF') * v_paf AS f,
--                IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
--         FROM `5c_wfm_db_mis`.abs_adh_af a
--         WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
--   WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data;
--   TRUNCATE TABLE fc_wrk_abs_taxa;
--   INSERT INTO fc_wrk_abs_taxa (dow, ta, tc, td)
--   SELECT c.dia_semana,
--          IF(SUM(tu='A') >= 100, SUM(IF(tu='A', f, 0))/SUM(tu='A'), v_ga),
--          IF(SUM(tu='C') >= 100, SUM(IF(tu='C', f, 0))/SUM(tu='C'), v_gc),
--          IF(SUM(tu='D') >= 100, SUM(IF(tu='D', f, 0))/SUM(tu='D'), v_gd)
--   FROM (SELECT CASE WHEN a.hora IN (5,9) THEN 'A' WHEN a.hora IN (13,17) THEN 'C' ELSE 'D' END AS tu,
--                (a.classificacao = 'ABS') + (a.classificacao = 'AF') * v_paf AS f,
--                IF(a.hora = 1, DATE(a.dia) - INTERVAL 1 DAY, DATE(a.dia)) AS td
--         FROM `5c_wfm_db_mis`.abs_adh_af a
--         WHERE a.dia >= p_data - INTERVAL (v_jan + 1) DAY AND a.dia < p_data) z
--   JOIN fc_calendario c ON c.dia = z.td
--   WHERE z.td >= p_data - INTERVAL v_jan DAY AND z.td < p_data
--   GROUP BY c.dia_semana;
--   DELETE FROM fc_buffer_diario WHERE data_calculo = p_data;
--   INSERT INTO fc_buffer_diario (data_calculo, dia, a, c, d, total, abs_a, abs_c, abs_d, to_a, to_c, to_d,
--                                 taxa_abs_a, taxa_abs_c, taxa_abs_d)
--   SELECT p_data, y.dia, y.abs_a + y.to_a, y.abs_c + y.to_c, y.abs_d + y.to_d,
--          y.abs_a + y.to_a + y.abs_c + y.to_c + y.abs_d + y.to_d,
--          y.abs_a, y.abs_c, y.abs_d, y.to_a, y.to_c, y.to_d, y.ra, y.rc, y.rd
--   FROM (SELECT e.dia,
--                CEIL(e.a * x.ra / (1 - x.ra)) AS abs_a,
--                CEIL(e.c * x.rc / (1 - x.rc)) AS abs_c,
--                CEIL(e.d * x.rd / (1 - x.rd)) AS abs_d,
--                CEIL(COALESCE(pt.prev_to_a,0) * v_prazo / 7 * v_pres) AS to_a,
--                CEIL(COALESCE(pt.prev_to_c,0) * v_prazo / 7 * v_pres) AS to_c,
--                CEIL(COALESCE(pt.prev_to_d,0) * v_prazo / 7 * v_pres) AS to_d,
--                x.ra, x.rc, x.rd
--         FROM fc_escala_diaria e
--         JOIN fc_calendario c ON c.dia = e.dia
--         JOIN (SELECT k.dia_semana AS dow, COALESCE(t.ta, v_ga) AS ra, COALESCE(t.tc, v_gc) AS rc, COALESCE(t.td, v_gd) AS rd
--               FROM (SELECT DISTINCT dia_semana FROM fc_calendario) k
--               LEFT JOIN fc_wrk_abs_taxa t ON t.dow = k.dia_semana) x ON x.dow = c.dia_semana
--         LEFT JOIN fc_prev_to_semanal pt ON pt.data_calculo = p_data
--                                        AND pt.ano_iso = c.ano_iso AND pt.semana_iso = c.semana_iso
--         WHERE e.data_calculo = p_data) y;
-- END$$
-- DELIMITER ;
--
-- (c) Recalcular
-- CALL `5c_planning`.`sp_fc_rodar_dia`();
