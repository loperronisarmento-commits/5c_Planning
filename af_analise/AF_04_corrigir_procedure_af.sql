-- =====================================================================
-- 5C | Falhas Administrativas (AF) — correção da origem
-- Rodar no Navicat (servidor .11) como luis.sarmento, bloco a bloco.
-- Base: AF_02_parecer.md (20/10/2026), seções 3, 5 e 10.
--
-- O que muda na `5c_wfm_db_mis.sp_abs_adh_af_processar` (passo 3.5):
--   D1  hora "dentro da escala" = qualquer hora da janela [slot, slot + 4 h)
--       (antes: só a hora de início do slot)
--   D2  conta por DIA DO TURNO (05:00 a 05:00), não por dia de calendário
--   D3  limiar volta para 3 h (antes 2 h)
--   +   ESCALA DOBRADA: falta num turno (A/C/D) com >= 2 h trabalhadas em
--       slot escalado de OUTRO turno no mesmo dia do turno -> AF (motivo AF_DOBRA)
--   +   PRESENÇA: slot com faltou = 1 mas >= 2 h de mensagem do próprio rep
--       dentro do slot -> NADH (motivo PRESENTE). `situacao` continua ABS.
--   +   mensagens truncadas na hora cheia (50% vêm em :30); ignora "AI ..."
--   +   coluna nova `motivo_classificacao` (AF_FORA / AF_DOBRA / PRESENTE)
--   D4  placeholder "5C Schedule" CONTINUA contando como escala (é escala
--       real de buffers e reps sem página fixa: ~50% ADH). Ajuste na publicação.
--
-- Resultado esperado (simulação 25/08–19/10, só leitura):
--   AF 1.441 -> ~665 (88 AF_FORA + 577 AF_DOBRA)
--   ABS 2.072 -> ~2.506 · NADH +~342 (PRESENTE)
--   Capacity com peso 0: abs A ~8,9% · C ~9,9% · D ~10,8%
--
-- Este script SUBSTITUI o AF_03: no fim ele zera os pesos (AF passa a
-- ser só falha de planejamento, peso 0 no Capacity). Se o AF_03 já
-- rodou, tudo bem: o UPDATE do passo 7 zera também as chaves _A/_C/_D.
--
-- Ordem: 0 foto -> 1 backup -> 2 coluna -> 3 procedure -> 4 fila
--        -> 5 processar -> 6 conferir -> 7 peso + Capacity. DESFAZER no fim.
-- =====================================================================
SET NAMES utf8mb4;
SET @lote = NOW();

-- ---------------------------------------------------------------------
-- 0. Fotografia antes (guardar o resultado)
-- ---------------------------------------------------------------------
SELECT classificacao, COUNT(*) AS slots
FROM `5c_wfm_db_mis`.`abs_adh_af`
WHERE dia BETWEEN CURDATE() - INTERVAL 56 DAY AND CURDATE() - INTERVAL 1 DAY
GROUP BY classificacao ORDER BY classificacao;

SELECT COUNT(*) AS fila_pendente FROM `5c_wfm_db_mis`.`abs_adh_af_fila`;

-- ---------------------------------------------------------------------
-- 1. Backup (nada se perde)
--    1a. tabela inteira (~115 mil linhas desde 01/01/2026)
--    1b. corpo atual da procedure (o texto completo também está no DESFAZER)
-- ---------------------------------------------------------------------
CREATE TABLE `5c_wfm_db_mis`.`abs_adh_af_bkp_af_20261020` AS
SELECT * FROM `5c_wfm_db_mis`.`abs_adh_af`;

SELECT COUNT(*) AS linhas_backup FROM `5c_wfm_db_mis`.`abs_adh_af_bkp_af_20261020`;

CREATE TABLE IF NOT EXISTS `5c_wfm_db_mis`.`abs_adh_af_rotinas_bkp` (
  `lote`      DATETIME    NOT NULL,
  `rotina`    VARCHAR(64) NOT NULL,
  `definicao` LONGTEXT    NOT NULL,
  PRIMARY KEY (`lote`, `rotina`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT = 'Corpo das procedures do abs_adh_af antes de cada troca';

INSERT INTO `5c_wfm_db_mis`.`abs_adh_af_rotinas_bkp` (lote, rotina, definicao)
SELECT @lote, ROUTINE_NAME, ROUTINE_DEFINITION
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = '5c_wfm_db_mis' AND ROUTINE_NAME = 'sp_abs_adh_af_processar';

-- ---------------------------------------------------------------------
-- 2. Coluna nova (INSTANT, não reescreve a tabela)
-- ---------------------------------------------------------------------
ALTER TABLE `5c_wfm_db_mis`.`abs_adh_af`
  ADD COLUMN `motivo_classificacao` VARCHAR(12) NULL DEFAULT NULL
  COMMENT 'AF_FORA (>=3h fora da escala) | AF_DOBRA (cumpriu outro turno escalado) | PRESENTE (faltou=1 mas trabalhou no slot -> NADH)'
  AFTER `classificacao`;

-- ---------------------------------------------------------------------
-- 3. Procedure nova
--    Pausa o evento de 5 min durante a troca (evita CALL entre DROP e CREATE).
-- ---------------------------------------------------------------------
ALTER EVENT `5c_wfm_db_mis`.`ev_abs_adh_af_processar` DISABLE;

DROP PROCEDURE IF EXISTS `5c_wfm_db_mis`.`sp_abs_adh_af_processar`;

DELIMITER $$
CREATE PROCEDURE `5c_wfm_db_mis`.`sp_abs_adh_af_processar`(IN p_limite INT)
    COMMENT 'Recalcula abs_adh_af somente para os rep-dias pendentes na fila (AF v2, 20/10/2026)'
proc: BEGIN
    -- PARAMETROS DE NEGOCIO -------------------------------------------
    DECLARE v_min_horas_af       INT DEFAULT 3;   -- horas fora de QUALQUER janela escalada no dia do turno
    DECLARE v_min_horas_dobra    INT DEFAULT 2;   -- horas trabalhadas em slot escalado de OUTRO turno (A/C/D) no mesmo dia do turno
    DECLARE v_min_horas_presenca INT DEFAULT 2;   -- horas com mensagem do proprio rep dentro do slot ABS -> NADH
    DECLARE v_ajuste_fuso        INT DEFAULT 0;   -- horas somadas ao horario das mensagens (validado 0 em 20/10/2026)
    DECLARE v_protecao_dias      INT DEFAULT 60;  -- rep-dias mais antigos que isso, sumidos da escala, NAO apagam o historico (expurgo)
    -- CONTROLE --------------------------------------------------------
    DECLARE v_agora   DATETIME(6) DEFAULT NOW(6);
    DECLARE v_qtd     INT DEFAULT 0;
    DECLARE v_dia_min DATE;
    DECLARE v_dia_max DATE;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        DO RELEASE_LOCK('abs_adh_af_processar');
        RESIGNAL;
    END;

    -- Evita duas execucoes simultaneas (evento + chamada manual)
    IF GET_LOCK('abs_adh_af_processar', 0) = 0 THEN
        LEAVE proc;
    END IF;

    -- 3.1 Lote: rep-dias marcados ate agora ------------------------------
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_lote;
    CREATE TEMPORARY TABLE tmp_abs_lote (
        codigo VARCHAR(30) NOT NULL,
        dia    DATE        NOT NULL,
        acao   ENUM('RECALC','IGNORAR') NOT NULL DEFAULT 'RECALC',
        PRIMARY KEY (codigo, dia)
    ) ENGINE = MEMORY;

    INSERT INTO tmp_abs_lote (codigo, dia)
    SELECT f.codigo, f.dia
    FROM `5c_wfm_db_mis`.`abs_adh_af_fila` f
    WHERE f.marcado_em <= v_agora
    ORDER BY f.marcado_em
    LIMIT p_limite;

    SELECT COUNT(*), MIN(dia), MAX(dia) INTO v_qtd, v_dia_min, v_dia_max FROM tmp_abs_lote;
    IF v_qtd = 0 THEN
        DO RELEASE_LOCK('abs_adh_af_processar');
        LEAVE proc;
    END IF;

    -- Protecao do historico: dia antigo que sumiu da escala = expurgo, nao correcao
    UPDATE tmp_abs_lote l
    SET l.acao = 'IGNORAR'
    WHERE l.dia < CURDATE() - INTERVAL v_protecao_dias DAY
      AND NOT EXISTS (
          SELECT 1
          FROM `5c_schedule`.`scheduleday` sd
          WHERE sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
            AND sd.shiftDateAndHour >= CONCAT(l.dia, 'T00:00:00')
            AND sd.shiftDateAndHour <  CONCAT(l.dia + INTERVAL 1 DAY, 'T00:00:00')
      );

    -- 3.2 Mapeamento codigo -> CODE_INFLOWW (1 vez por codigo do lote) ---
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_map;
    CREATE TEMPORARY TABLE tmp_abs_map (
        codigo       VARCHAR(30) NOT NULL PRIMARY KEY,
        code_infloww VARCHAR(50) NULL
    ) ENGINE = MEMORY;

    INSERT INTO tmp_abs_map (codigo, code_infloww)
    SELECT
        c.codigo,
        COALESCE(
            (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
              WHERE h.CODE_INFLOWW = c.codigo COLLATE utf8mb4_unicode_ci LIMIT 1),
            (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
              WHERE h.CODE_SITE = c.codigo COLLATE utf8mb4_unicode_ci AND h.CODE_INFLOWW IS NOT NULL
              ORDER BY h.UPDATE_DATE DESC LIMIT 1),
            (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
              WHERE h.CODE_INFLOWW IS NOT NULL
                AND UPPER(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(h.SOCIAL_NAME), ''), h.NAME), '(', 1))) = c.nome_ref COLLATE utf8mb4_unicode_ci
              ORDER BY h.UPDATE_DATE DESC LIMIT 1)
        )
    FROM (
        SELECT
            l.codigo,
            (SELECT UPPER(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(sd.colaboradorNomeSocial), ''), sd.colaboradorNome), '(', 1)))
               FROM `5c_schedule`.`scheduleday` sd
              WHERE sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
              ORDER BY sd.id DESC LIMIT 1) AS nome_ref
        FROM (SELECT DISTINCT codigo FROM tmp_abs_lote WHERE acao = 'RECALC') l
    ) c;

    START TRANSACTION;

    -- 3.3 Remove os slots atuais dos rep-dias do lote --------------------
    DELETE h
    FROM `5c_wfm_db_mis`.`abs_adh_af` h
    JOIN tmp_abs_lote l
      ON l.codigo = h.codigo AND l.dia = h.dia AND l.acao = 'RECALC';

    -- 3.4 Recalcula os slots (dedup por rep + dia + hora) ----------------
    INSERT INTO `5c_wfm_db_mis`.`abs_adh_af` (
        codigo, slot_inicio, dia, hora, colaborador_nome, colaborador_nome_social,
        code_infloww, teams, qtd_escalas, situacao, avisou_falta, classificacao
    )
    SELECT
        s.codigo,
        s.slot_inicio,
        DATE(s.slot_inicio),
        HOUR(s.slot_inicio),
        s.nome,
        s.nome_social,
        m.code_infloww,
        s.teams,
        s.qtd,
        CASE WHEN s.min_f = 1 THEN 'ABS' WHEN s.max_f = 1 THEN 'NADH' ELSE 'ADH' END,
        s.avisou,
        CASE WHEN s.min_f = 1 THEN 'ABS' WHEN s.max_f = 1 THEN 'NADH' ELSE 'ADH' END
    FROM (
        SELECT
            sd.codigo,
            STR_TO_DATE(LEFT(sd.shiftDateAndHour, 19), '%Y-%m-%dT%H:%i:%s')          AS slot_inicio,
            MAX(sd.colaboradorNome)                                                 AS nome,
            MAX(sd.colaboradorNomeSocial)                                           AS nome_social,
            LEFT(GROUP_CONCAT(DISTINCT sd.teamName ORDER BY sd.teamName SEPARATOR ', '), 500) AS teams,
            COUNT(*)                                                                AS qtd,
            MIN(COALESCE(sd.faltou, 0))                                             AS min_f,
            MAX(COALESCE(sd.faltou, 0))                                             AS max_f,
            MAX(COALESCE(sd.avisouFalta, 0))                                        AS avisou
        FROM tmp_abs_lote l
        JOIN `5c_schedule`.`scheduleday` sd
          ON sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
         AND sd.shiftDateAndHour >= CONCAT(l.dia, 'T00:00:00')
         AND sd.shiftDateAndHour <  CONCAT(l.dia + INTERVAL 1 DAY, 'T00:00:00')
        WHERE l.acao = 'RECALC'
          AND sd.shiftDateAndHour REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
        GROUP BY sd.codigo, STR_TO_DATE(LEFT(sd.shiftDateAndHour, 19), '%Y-%m-%dT%H:%i:%s')
    ) s
    LEFT JOIN tmp_abs_map m ON m.codigo COLLATE utf8mb4_unicode_ci = s.codigo COLLATE utf8mb4_unicode_ci;

    -- 3.5 Administrative Failure v2 ---------------------------------------
    -- Dia do turno (td) = DATE(slot - 5 h): T2..T6 do dia D + T1 (01h) de D+1.
    -- O dia de calendario D toca os dias do turno D-1 (pelo T1) e D; por isso
    -- os slots ABS de D+1 01h tambem sao reavaliados quando D entra no lote.
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_td;
    CREATE TEMPORARY TABLE tmp_abs_td (
        codigo       VARCHAR(30) NOT NULL,
        td           DATE        NOT NULL,
        code_infloww VARCHAR(50) NULL,
        PRIMARY KEY (codigo, td)
    ) ENGINE = MEMORY;

    INSERT IGNORE INTO tmp_abs_td (codigo, td, code_infloww)
    SELECT l.codigo, l.dia - INTERVAL 1 DAY, m.code_infloww
    FROM tmp_abs_lote l
    LEFT JOIN tmp_abs_map m ON m.codigo = l.codigo
    WHERE l.acao = 'RECALC';

    INSERT IGNORE INTO tmp_abs_td (codigo, td, code_infloww)
    SELECT l.codigo, l.dia, m.code_infloww
    FROM tmp_abs_lote l
    LEFT JOIN tmp_abs_map m ON m.codigo = l.codigo
    WHERE l.acao = 'RECALC';

    -- Slots com falta (situacao ABS) desses dias do turno
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_af;
    CREATE TEMPORARY TABLE tmp_abs_af (
        codigo        VARCHAR(30) NOT NULL,
        slot_inicio   DATETIME    NOT NULL,
        td            DATE        NOT NULL,
        turno         CHAR(1)     NOT NULL,
        n_slot        INT         NOT NULL DEFAULT 0,   -- horas com msg dentro do proprio slot
        n_fora        INT         NOT NULL DEFAULT 0,   -- horas com msg fora de qualquer slot escalado (dia do turno)
        n_outro_turno INT         NOT NULL DEFAULT 0,   -- horas com msg em slot escalado de outro turno (dia do turno)
        PRIMARY KEY (codigo, slot_inicio)
    ) ENGINE = MEMORY;

    INSERT INTO tmp_abs_af (codigo, slot_inicio, td, turno)
    SELECT h.codigo, h.slot_inicio, t.td,
           CASE WHEN h.hora IN (5,9) THEN 'A' WHEN h.hora IN (13,17) THEN 'C' ELSE 'D' END
    FROM tmp_abs_td t
    JOIN `5c_wfm_db_mis`.`abs_adh_af` h
      ON h.codigo = t.codigo
     AND h.slot_inicio >= TIMESTAMP(t.td) + INTERVAL 5 HOUR
     AND h.slot_inicio <  TIMESTAMP(t.td) + INTERVAL 29 HOUR
    WHERE h.situacao = 'ABS';

    -- Horas em que o rep enviou mensagens nesses dias do turno (hora cheia)
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_msg;
    CREATE TEMPORARY TABLE tmp_abs_msg (
        codigo    VARCHAR(30) NOT NULL,
        dh        DATETIME    NOT NULL,
        turno_esc CHAR(1)     NULL,       -- turno do slot escalado que cobre a hora; NULL = fora da escala
        PRIMARY KEY (codigo, dh)
    ) ENGINE = MEMORY;

    INSERT IGNORE INTO tmp_abs_msg (codigo, dh)
    SELECT t.codigo, mt.dh
    FROM tmp_abs_td t
    JOIN (
        SELECT SUBSTRING_INDEX(mi.Sender, ' ', 1)                                                  AS code,
               DATE_SUB(mi.Date_Hour, INTERVAL MINUTE(mi.Date_Hour) MINUTE) + INTERVAL v_ajuste_fuso HOUR AS dh
        FROM `5c_wfm_db_mis`.`tab_message_interval` mi
        WHERE mi.Sent_Date BETWEEN v_dia_min - INTERVAL 2 DAY AND v_dia_max + INTERVAL 2 DAY
          AND mi.Sender NOT LIKE 'AI %'
    ) mt
      ON mt.code COLLATE utf8mb4_unicode_ci = t.code_infloww COLLATE utf8mb4_unicode_ci
     AND mt.dh >= TIMESTAMP(t.td) + INTERVAL 5 HOUR
     AND mt.dh <  TIMESTAMP(t.td) + INTERVAL 29 HOUR
    WHERE t.code_infloww IS NOT NULL
      AND EXISTS (SELECT 1 FROM tmp_abs_af s WHERE s.codigo = t.codigo AND s.td = t.td);

    -- Qual slot escalado (de qualquer situacao) cobre cada hora: janela [slot, slot + 4 h)
    UPDATE tmp_abs_msg x
    SET x.turno_esc = (
        SELECT CASE WHEN h.hora IN (5,9) THEN 'A' WHEN h.hora IN (13,17) THEN 'C' ELSE 'D' END
        FROM `5c_wfm_db_mis`.`abs_adh_af` h
        WHERE h.codigo = x.codigo
          AND h.slot_inicio >  x.dh - INTERVAL 4 HOUR
          AND h.slot_inicio <= x.dh
        ORDER BY h.slot_inicio DESC
        LIMIT 1
    );

    -- Contagens por slot (uma referencia a tmp_abs_msg por comando)
    UPDATE tmp_abs_af s
    SET s.n_slot = (
        SELECT COUNT(*) FROM tmp_abs_msg x
        WHERE x.codigo = s.codigo
          AND x.dh >= s.slot_inicio AND x.dh < s.slot_inicio + INTERVAL 4 HOUR
    );

    UPDATE tmp_abs_af s
    SET s.n_fora = (
        SELECT COUNT(*) FROM tmp_abs_msg x
        WHERE x.codigo = s.codigo
          AND x.turno_esc IS NULL
          AND x.dh >= TIMESTAMP(s.td) + INTERVAL 5 HOUR AND x.dh < TIMESTAMP(s.td) + INTERVAL 29 HOUR
    );

    UPDATE tmp_abs_af s
    SET s.n_outro_turno = (
        SELECT COUNT(*) FROM tmp_abs_msg x
        WHERE x.codigo = s.codigo
          AND x.turno_esc <> s.turno
          AND x.dh >= TIMESTAMP(s.td) + INTERVAL 5 HOUR AND x.dh < TIMESTAMP(s.td) + INTERVAL 29 HOUR
    );

    -- Classificacao final dos slots ABS (prioridade: presenca > fora > dobra > ABS)
    UPDATE `5c_wfm_db_mis`.`abs_adh_af` h
    JOIN tmp_abs_af s
      ON s.codigo = h.codigo AND s.slot_inicio = h.slot_inicio
    SET h.horas_fora_escala      = LEAST(s.n_fora, 255),
        h.classificacao          = CASE WHEN s.n_slot >= v_min_horas_presenca THEN 'NADH'
                                        WHEN s.n_fora >= v_min_horas_af
                                          OR s.n_outro_turno >= v_min_horas_dobra THEN 'AF'
                                        ELSE 'ABS' END,
        h.administrative_failure = (s.n_slot < v_min_horas_presenca
                                    AND (s.n_fora >= v_min_horas_af OR s.n_outro_turno >= v_min_horas_dobra)),
        h.motivo_classificacao   = CASE WHEN s.n_slot >= v_min_horas_presenca THEN 'PRESENTE'
                                        WHEN s.n_fora >= v_min_horas_af THEN 'AF_FORA'
                                        WHEN s.n_outro_turno >= v_min_horas_dobra THEN 'AF_DOBRA'
                                        ELSE NULL END;

    -- 3.6 Tira da fila o que foi processado ------------------------------
    -- (se o rep-dia foi marcado de novo durante o processamento, marcado_em
    --  ficou maior que v_agora e ele continua na fila para a proxima rodada)
    DELETE f
    FROM `5c_wfm_db_mis`.`abs_adh_af_fila` f
    JOIN tmp_abs_lote l ON l.codigo = f.codigo AND l.dia = f.dia
    WHERE f.marcado_em <= v_agora;

    COMMIT;

    DROP TEMPORARY TABLE IF EXISTS tmp_abs_lote;
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_map;
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_td;
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_af;
    DROP TEMPORARY TABLE IF EXISTS tmp_abs_msg;
    DO RELEASE_LOCK('abs_adh_af_processar');
END proc$$
DELIMITER ;

SHOW CREATE PROCEDURE `5c_wfm_db_mis`.`sp_abs_adh_af_processar`;

-- ---------------------------------------------------------------------
-- 4. Reenfileirar o histórico em ordem cronológica
--    marcado_em no passado, crescente por dia: cada lote de 5.000 pega
--    ~20 dias seguidos (leitura de mensagens fica curta). Quem já estava
--    na fila continua lá.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `5c_wfm_db_mis`.`abs_adh_af_fila` (codigo, dia, marcado_em)
SELECT d.codigo, d.dia, TIMESTAMP('2000-01-01') + INTERVAL DATEDIFF(d.dia, '2026-01-01') SECOND
FROM (SELECT DISTINCT codigo, dia FROM `5c_wfm_db_mis`.`abs_adh_af`) d;

SELECT COUNT(*) AS fila_pendente FROM `5c_wfm_db_mis`.`abs_adh_af_fila`;   -- ~62 mil

-- ---------------------------------------------------------------------
-- 5. Processar
--    Opção A (recomendada): primeiro lote manual para medir o tempo; se
--    passar, religar o evento e deixar ele correr (5.000 a cada 5 min,
--    ~13 rodadas, ~1 h).
-- ---------------------------------------------------------------------
CALL `5c_wfm_db_mis`.`sp_abs_adh_af_processar`(5000);
SELECT COUNT(*) AS fila_pendente FROM `5c_wfm_db_mis`.`abs_adh_af_fila`;

ALTER EVENT `5c_wfm_db_mis`.`ev_abs_adh_af_processar` ENABLE;

-- Acompanhar até a fila voltar ao normal (poucas centenas):
-- SELECT COUNT(*) AS fila_pendente, MIN(dia), MAX(dia) FROM `5c_wfm_db_mis`.`abs_adh_af_fila`;

-- Opção B: rodar manualmente, repetindo a linha abaixo até a fila esvaziar.
-- CALL `5c_wfm_db_mis`.`sp_abs_adh_af_processar`(5000);

-- ---------------------------------------------------------------------
-- 6. Conferir (depois da fila processada)
-- ---------------------------------------------------------------------
-- 6a. Distribuição 56 dias. Esperado ~: ABS 2.500 · AF 665 · NADH +340
SELECT classificacao, COALESCE(motivo_classificacao, '') AS motivo, COUNT(*) AS slots
FROM `5c_wfm_db_mis`.`abs_adh_af`
WHERE dia BETWEEN CURDATE() - INTERVAL 56 DAY AND CURDATE() - INTERVAL 1 DAY
GROUP BY classificacao, motivo_classificacao
ORDER BY classificacao, motivo;

-- 6b. Antes x depois por slot (backup x atual), 56 dias
SELECT b.classificacao AS antes, a.classificacao AS depois, COALESCE(a.motivo_classificacao,'') AS motivo,
       COUNT(*) AS slots
FROM `5c_wfm_db_mis`.`abs_adh_af_bkp_af_20261020` b
JOIN `5c_wfm_db_mis`.`abs_adh_af` a ON a.codigo = b.codigo AND a.slot_inicio = b.slot_inicio
WHERE b.dia BETWEEN CURDATE() - INTERVAL 56 DAY AND CURDATE() - INTERVAL 1 DAY
  AND (b.classificacao <> a.classificacao OR a.motivo_classificacao IS NOT NULL)
GROUP BY b.classificacao, a.classificacao, a.motivo_classificacao
ORDER BY slots DESC;

-- 6c. Casos do parecer (13/10):
--   B456106 01h -> ABS (faltou T1, trabalhou o T6 escalado)
--   C051206 21h -> NADH / PRESENTE (mandou mensagem no próprio slot)
--   D154823 21h -> AF / AF_FORA (trabalhou 06–08h, fora da escala)
SELECT codigo, DATE_FORMAT(slot_inicio, '%Y-%m-%d %H:%i') AS slot, situacao, classificacao,
       motivo_classificacao, horas_fora_escala
FROM `5c_wfm_db_mis`.`abs_adh_af`
WHERE (codigo, slot_inicio) IN (('B456106','2026-10-13 01:00:00'),
                                ('C051206','2026-10-13 21:00:00'),
                                ('D154823','2026-10-13 21:00:00'));

-- ---------------------------------------------------------------------
-- 7. Peso no Capacity -> 0 e recalcular
--    Com a regra nova, AF = trabalhou fora da escala ou cumpriu o outro
--    turno escalado: em nenhum dos dois casos há hora de rep perdida.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `5c_planning`.`fc_parametros_bkp_af` (
  `lote`          DATETIME      NOT NULL,
  `chave`         VARCHAR(40)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor`         DECIMAL(12,4) NOT NULL,
  `descricao`     VARCHAR(255)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `atualizado_em` TIMESTAMP     NULL,
  PRIMARY KEY (`lote`, `chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT = 'Backup de fc_parametros antes de cada mudança de peso_falha_adm (AF_03/AF_04)';

INSERT INTO `5c_planning`.`fc_parametros_bkp_af` (lote, chave, valor, descricao, atualizado_em)
SELECT @lote, chave, valor, descricao, atualizado_em
FROM `5c_planning`.`fc_parametros`;

START TRANSACTION;

UPDATE `5c_planning`.`fc_parametros`
   SET valor     = 0.0000,
       descricao = 'AF no absenteísmo. 0 desde a AF v2 (20/10/2026): AF = trabalhou fora da escala ou cumpriu outro turno escalado; faltas reais voltaram para ABS'
 WHERE chave LIKE 'peso_falha_adm%';

SELECT chave, valor, descricao FROM `5c_planning`.`fc_parametros` WHERE chave LIKE 'peso_falha_adm%' ORDER BY chave;

COMMIT;
-- ROLLBACK;

CALL `5c_planning`.`sp_fc_rodar_dia`();
SELECT * FROM `5c_planning`.`fc_log_execucao` ORDER BY id DESC LIMIT 3;   -- status = OK

SELECT data_calculo,
       ROUND(AVG(taxa_abs_a) * 100, 2) AS abs_a, ROUND(AVG(taxa_abs_c) * 100, 2) AS abs_c,
       ROUND(AVG(taxa_abs_d) * 100, 2) AS abs_d
FROM `5c_planning`.`fc_buffer_diario`
GROUP BY data_calculo
ORDER BY data_calculo DESC
LIMIT 2;
-- Esperado: A ~8,9% · C ~9,9% · D ~10,8%  (19/10: 10,42 / 12,00 / 11,02)

SELECT * FROM `5c_planning`.`vw_fc_plano_semanal`;

-- =====================================================================
-- DESFAZER
-- =====================================================================
-- (a) Procedure original (19/10) + reprocessar com ela
-- ALTER EVENT `5c_wfm_db_mis`.`ev_abs_adh_af_processar` DISABLE;
-- DROP PROCEDURE IF EXISTS `5c_wfm_db_mis`.`sp_abs_adh_af_processar`;
-- DELIMITER $$
-- CREATE PROCEDURE `5c_wfm_db_mis`.`sp_abs_adh_af_processar`(IN p_limite INT)
--     COMMENT 'Recalcula abs_adh_af somente para os rep-dias pendentes na fila'
-- proc: BEGIN
--     DECLARE v_min_horas_af    INT DEFAULT 2;
--     DECLARE v_ajuste_fuso     INT DEFAULT 0;
--     DECLARE v_protecao_dias   INT DEFAULT 60;
--     DECLARE v_agora   DATETIME(6) DEFAULT NOW(6);
--     DECLARE v_qtd     INT DEFAULT 0;
--     DECLARE v_dia_min DATE;
--     DECLARE v_dia_max DATE;
--     DECLARE EXIT HANDLER FOR SQLEXCEPTION
--     BEGIN
--         ROLLBACK;
--         DO RELEASE_LOCK('abs_adh_af_processar');
--         RESIGNAL;
--     END;
--     IF GET_LOCK('abs_adh_af_processar', 0) = 0 THEN
--         LEAVE proc;
--     END IF;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_lote;
--     CREATE TEMPORARY TABLE tmp_abs_lote (
--         codigo VARCHAR(30) NOT NULL,
--         dia    DATE        NOT NULL,
--         acao   ENUM('RECALC','IGNORAR') NOT NULL DEFAULT 'RECALC',
--         PRIMARY KEY (codigo, dia)
--     ) ENGINE = MEMORY;
--     INSERT INTO tmp_abs_lote (codigo, dia)
--     SELECT f.codigo, f.dia
--     FROM `5c_wfm_db_mis`.`abs_adh_af_fila` f
--     WHERE f.marcado_em <= v_agora
--     ORDER BY f.marcado_em
--     LIMIT p_limite;
--     SELECT COUNT(*), MIN(dia), MAX(dia) INTO v_qtd, v_dia_min, v_dia_max FROM tmp_abs_lote;
--     IF v_qtd = 0 THEN
--         DO RELEASE_LOCK('abs_adh_af_processar');
--         LEAVE proc;
--     END IF;
--     UPDATE tmp_abs_lote l
--     SET l.acao = 'IGNORAR'
--     WHERE l.dia < CURDATE() - INTERVAL v_protecao_dias DAY
--       AND NOT EXISTS (
--           SELECT 1
--           FROM `5c_schedule`.`scheduleday` sd
--           WHERE sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
--             AND sd.shiftDateAndHour >= CONCAT(l.dia, 'T00:00:00')
--             AND sd.shiftDateAndHour <  CONCAT(l.dia + INTERVAL 1 DAY, 'T00:00:00')
--       );
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_map;
--     CREATE TEMPORARY TABLE tmp_abs_map (
--         codigo       VARCHAR(30) NOT NULL PRIMARY KEY,
--         code_infloww VARCHAR(50) NULL
--     ) ENGINE = MEMORY;
--     INSERT INTO tmp_abs_map (codigo, code_infloww)
--     SELECT
--         c.codigo,
--         COALESCE(
--             (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
--               WHERE h.CODE_INFLOWW = c.codigo COLLATE utf8mb4_unicode_ci LIMIT 1),
--             (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
--               WHERE h.CODE_SITE = c.codigo COLLATE utf8mb4_unicode_ci AND h.CODE_INFLOWW IS NOT NULL
--               ORDER BY h.UPDATE_DATE DESC LIMIT 1),
--             (SELECT h.CODE_INFLOWW FROM `5c_wfm_db_mis`.`tab_headcount` h
--               WHERE h.CODE_INFLOWW IS NOT NULL
--                 AND UPPER(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(h.SOCIAL_NAME), ''), h.NAME), '(', 1))) = c.nome_ref COLLATE utf8mb4_unicode_ci
--               ORDER BY h.UPDATE_DATE DESC LIMIT 1)
--         )
--     FROM (
--         SELECT
--             l.codigo,
--             (SELECT UPPER(TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(sd.colaboradorNomeSocial), ''), sd.colaboradorNome), '(', 1)))
--                FROM `5c_schedule`.`scheduleday` sd
--               WHERE sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
--               ORDER BY sd.id DESC LIMIT 1) AS nome_ref
--         FROM (SELECT DISTINCT codigo FROM tmp_abs_lote WHERE acao = 'RECALC') l
--     ) c;
--     START TRANSACTION;
--     DELETE h
--     FROM `5c_wfm_db_mis`.`abs_adh_af` h
--     JOIN tmp_abs_lote l
--       ON l.codigo = h.codigo AND l.dia = h.dia AND l.acao = 'RECALC';
--     INSERT INTO `5c_wfm_db_mis`.`abs_adh_af` (
--         codigo, slot_inicio, dia, hora, colaborador_nome, colaborador_nome_social,
--         code_infloww, teams, qtd_escalas, situacao, avisou_falta, classificacao
--     )
--     SELECT
--         s.codigo,
--         s.slot_inicio,
--         DATE(s.slot_inicio),
--         HOUR(s.slot_inicio),
--         s.nome,
--         s.nome_social,
--         m.code_infloww,
--         s.teams,
--         s.qtd,
--         CASE WHEN s.min_f = 1 THEN 'ABS' WHEN s.max_f = 1 THEN 'NADH' ELSE 'ADH' END,
--         s.avisou,
--         CASE WHEN s.min_f = 1 THEN 'ABS' WHEN s.max_f = 1 THEN 'NADH' ELSE 'ADH' END
--     FROM (
--         SELECT
--             sd.codigo,
--             STR_TO_DATE(LEFT(sd.shiftDateAndHour, 19), '%Y-%m-%dT%H:%i:%s')          AS slot_inicio,
--             MAX(sd.colaboradorNome)                                                 AS nome,
--             MAX(sd.colaboradorNomeSocial)                                           AS nome_social,
--             LEFT(GROUP_CONCAT(DISTINCT sd.teamName ORDER BY sd.teamName SEPARATOR ', '), 500) AS teams,
--             COUNT(*)                                                                AS qtd,
--             MIN(COALESCE(sd.faltou, 0))                                             AS min_f,
--             MAX(COALESCE(sd.faltou, 0))                                             AS max_f,
--             MAX(COALESCE(sd.avisouFalta, 0))                                        AS avisou
--         FROM tmp_abs_lote l
--         JOIN `5c_schedule`.`scheduleday` sd
--           ON sd.codigo = l.codigo COLLATE utf8mb4_unicode_ci
--          AND sd.shiftDateAndHour >= CONCAT(l.dia, 'T00:00:00')
--          AND sd.shiftDateAndHour <  CONCAT(l.dia + INTERVAL 1 DAY, 'T00:00:00')
--         WHERE l.acao = 'RECALC'
--           AND sd.shiftDateAndHour REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
--         GROUP BY sd.codigo, STR_TO_DATE(LEFT(sd.shiftDateAndHour, 19), '%Y-%m-%dT%H:%i:%s')
--     ) s
--     LEFT JOIN tmp_abs_map m ON m.codigo COLLATE utf8mb4_unicode_ci = s.codigo COLLATE utf8mb4_unicode_ci;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_af;
--     CREATE TEMPORARY TABLE tmp_abs_af (
--         codigo       VARCHAR(30) NOT NULL,
--         dia          DATE        NOT NULL,
--         code_infloww VARCHAR(50) NOT NULL,
--         horas_fora   INT         NOT NULL DEFAULT 0,
--         PRIMARY KEY (codigo, dia),
--         KEY ix_tmp_af_infloww (code_infloww, dia)
--     ) ENGINE = MEMORY;
--     INSERT INTO tmp_abs_af (codigo, dia, code_infloww)
--     SELECT h.codigo, h.dia, MAX(h.code_infloww)
--     FROM `5c_wfm_db_mis`.`abs_adh_af` h
--     JOIN tmp_abs_lote l
--       ON l.codigo = h.codigo AND l.dia = h.dia AND l.acao = 'RECALC'
--     WHERE h.situacao = 'ABS'
--       AND h.code_infloww IS NOT NULL
--     GROUP BY h.codigo, h.dia;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_msg;
--     CREATE TEMPORARY TABLE tmp_abs_msg (
--         codigo VARCHAR(30)      NOT NULL,
--         dia    DATE             NOT NULL,
--         hora   TINYINT UNSIGNED NOT NULL,
--         PRIMARY KEY (codigo, dia, hora)
--     ) ENGINE = MEMORY;
--     INSERT IGNORE INTO tmp_abs_msg (codigo, dia, hora)
--     SELECT a.codigo, a.dia, HOUR(mt.dt)
--     FROM tmp_abs_af a
--     JOIN (
--         SELECT
--             SUBSTRING_INDEX(mi.Sender, ' ', 1)            AS code,
--             mi.Date_Hour + INTERVAL v_ajuste_fuso HOUR    AS dt
--         FROM `5c_wfm_db_mis`.`tab_message_interval` mi
--         WHERE mi.SENT_DATE BETWEEN v_dia_min - INTERVAL 1 DAY AND v_dia_max + INTERVAL 1 DAY
--     ) mt
--       ON mt.code COLLATE utf8mb4_unicode_ci = a.code_infloww COLLATE utf8mb4_unicode_ci
--      AND DATE(mt.dt) = a.dia;
--     UPDATE tmp_abs_af a
--     SET a.horas_fora = (
--         SELECT COUNT(*)
--         FROM tmp_abs_msg x
--         WHERE x.codigo = a.codigo
--           AND x.dia    = a.dia
--           AND NOT EXISTS (
--               SELECT 1
--               FROM `5c_wfm_db_mis`.`abs_adh_af` h
--               WHERE h.codigo = x.codigo AND h.dia = x.dia AND h.hora = x.hora
--           )
--     );
--     UPDATE `5c_wfm_db_mis`.`abs_adh_af` h
--     JOIN tmp_abs_af a
--       ON a.codigo = h.codigo AND a.dia = h.dia
--     SET h.horas_fora_escala      = LEAST(a.horas_fora, 255),
--         h.administrative_failure = (h.situacao = 'ABS' AND a.horas_fora >= v_min_horas_af),
--         h.classificacao          = IF(h.situacao = 'ABS' AND a.horas_fora >= v_min_horas_af, 'AF', h.situacao);
--     DELETE f
--     FROM `5c_wfm_db_mis`.`abs_adh_af_fila` f
--     JOIN tmp_abs_lote l ON l.codigo = f.codigo AND l.dia = f.dia
--     WHERE f.marcado_em <= v_agora;
--     COMMIT;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_lote;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_map;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_af;
--     DROP TEMPORARY TABLE IF EXISTS tmp_abs_msg;
--     DO RELEASE_LOCK('abs_adh_af_processar');
-- END proc$$
-- DELIMITER ;
-- ALTER EVENT `5c_wfm_db_mis`.`ev_abs_adh_af_processar` ENABLE;
--
-- (b) Dados: voltar a tabela à foto do backup (rápido, sem reprocessar).
--     Slots criados depois do backup (dias novos) ficam como estão.
-- START TRANSACTION;
-- UPDATE `5c_wfm_db_mis`.`abs_adh_af` a
--   JOIN `5c_wfm_db_mis`.`abs_adh_af_bkp_af_20261020` b
--     ON b.codigo = a.codigo AND b.slot_inicio = a.slot_inicio
--    SET a.situacao = b.situacao, a.classificacao = b.classificacao,
--        a.horas_fora_escala = b.horas_fora_escala, a.administrative_failure = b.administrative_failure,
--        a.motivo_classificacao = NULL;
-- COMMIT;
--
-- (c) Coluna nova (opcional; a procedure original não usa)
-- ALTER TABLE `5c_wfm_db_mis`.`abs_adh_af` DROP COLUMN `motivo_classificacao`;
--
-- (d) Pesos: restaurar o lote do passo 7
-- SET @lote_restaurar = '2026-10-20 00:00:00';   -- <- trocar pelo lote do passo 7
-- UPDATE `5c_planning`.`fc_parametros` p
--   JOIN `5c_planning`.`fc_parametros_bkp_af` b ON b.chave = p.chave AND b.lote = @lote_restaurar
--    SET p.valor = b.valor, p.descricao = b.descricao
--  WHERE p.chave LIKE 'peso_falha_adm%';
-- CALL `5c_planning`.`sp_fc_rodar_dia`();
