-- =====================================================================
-- NS_05 · Fanvue e históricos de headcount e páginas vindos do servidor
--         novo 209.159.144.7 (rodar no .11, DEPOIS do NS_01)
-- ---------------------------------------------------------------------
-- O que entra no .11:
--
-- FANVUE (fanvue_data.statements_raw → 5c_wfm_db)
--   Faz o mesmo que o etl_fanvue do portal, só para invoices que o .11
--   ainda não tem:
--     - statements_fanvue (bruto): linha igual à do CSV, com o nome da
--       criadora do .11 ("ivyvale" → "Ivy Vale") e Paid At em UTC;
--     - statements (produção): DATE em Brasília, valores sem sinal,
--       DESCRIPTION "<tipo> from <fan> FanVue", STATUS complete/reverse.
--       A CLASSIFICATION é preenchida depois pelo ev_refresh_statements_class_15min,
--       como hoje.
--   Não apaga nem altera nada que já existe. O etl_fanvue pode continuar
--   rodando: se ele receber depois a mesma linha, ela é ignorada (igualzinha).
--   ATENÇÃO AO FUSO: apesar do nome, o paid_at_utc do servidor novo está em
--   horário de Brasília (UTC−3) em todas as linhas. Ex.: FVE-20261020-48379
--   = 07:47:13 lá e 10:47:13 UTC no .11. Por isso: Paid At (UTC) = paid_at_utc
--   + 3 h e statements.DATE = paid_at_utc. Se o DBA corrigir a coluna para
--   UTC de verdade, troque as duas contas (veja "FUSO" na procedure).
--   Linhas que o servidor novo copiou do .11 (source_file 'sync:...') são
--   ignoradas: já estão aqui.
--
-- HISTÓRICOS (só leitura no .11, crescem a cada mudança no servidor novo)
--   - 5c_headcount.headcount_history: insert / update (com changed_fields) /
--     removed / returned por pessoa (code), desde 13/10/2026.
--   - 5c_pages.paginas_history: o mesmo por página (page_id), com o JSON de
--     antes (previous_json), desde 21/10/2026.
--   Mesmos ids do servidor novo; cópia só do que ainda não está aqui.
--
-- ANTES DE RODAR, troque <usuario>, <senha> e <porta> no CREATE SERVER do
-- Fanvue (os mesmos do NS_01). Os históricos usam os servidores
-- srv_novo_headcount e srv_novo_pages criados no NS_01.
-- =====================================================================

-- ---------- 1. Servidor do Fanvue ----------
DROP SERVER IF EXISTS srv_novo_fanvue;
CREATE SERVER srv_novo_fanvue
  FOREIGN DATA WRAPPER mysql
  OPTIONS (HOST '209.159.144.7', PORT <porta>, DATABASE 'fanvue_data', USER '<usuario>', PASSWORD '<senha>');

-- ---------- 2. Federadas (mesma estrutura do remoto) ----------
DROP TABLE IF EXISTS `5c_wfm_db`.`fed_fanvue_statements`;
CREATE TABLE `5c_wfm_db`.`fed_fanvue_statements` (
  `id`             BIGINT UNSIGNED NOT NULL,
  `creator`        VARCHAR(100)    NOT NULL,
  `invoice_number` VARCHAR(32)     NOT NULL,
  `paid_at_utc`    DATETIME        NOT NULL,
  `type`           VARCHAR(20)     NOT NULL,
  `fan_handle`     VARCHAR(64)     NOT NULL,
  `net`            DECIMAL(12,2)   NOT NULL,
  `tax`            DECIMAL(12,2)   NOT NULL,
  `fanvue_fee`     DECIMAL(12,2)   NOT NULL,
  `price`          DECIMAL(12,2)   NOT NULL,
  `gross`          DECIMAL(12,2)   NOT NULL,
  `fan_paid`       DECIMAL(12,2)   NOT NULL,
  `sales_tax`      DECIMAL(12,2)   NOT NULL,
  `tax_basis`      VARCHAR(20)     NOT NULL,
  `source_file`    VARCHAR(255)    NOT NULL,
  `loaded_at`      TIMESTAMP       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`)
) ENGINE=FEDERATED DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  CONNECTION='srv_novo_fanvue/statements_raw'
  COMMENT='Janela para 209.159.144.7 fanvue_data.statements_raw (só leitura)';

DROP TABLE IF EXISTS `5c_headcount`.`fed_headcount_history`;
CREATE TABLE `5c_headcount`.`fed_headcount_history` (
  `id`             BIGINT UNSIGNED NOT NULL,
  `import_log_id`  INT UNSIGNED    NULL,
  `code`           VARCHAR(30)     NOT NULL,
  `change_type`    ENUM('insert','update','removed','returned') NOT NULL,
  `changed_fields` TEXT            NULL,
  `row_hash`       CHAR(40)        NULL,
  `raw_json`       LONGTEXT        NULL,
  `changed_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=FEDERATED DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  CONNECTION='srv_novo_headcount/headcount_history'
  COMMENT='Janela para 209.159.144.7 5c_headcount.headcount_history (só leitura)';

DROP TABLE IF EXISTS `5c_pages`.`fed_paginas_history`;
CREATE TABLE `5c_pages`.`fed_paginas_history` (
  `id`             BIGINT UNSIGNED NOT NULL,
  `import_log_id`  INT UNSIGNED    NULL,
  `page_id`        INT UNSIGNED    NOT NULL,
  `change_type`    ENUM('insert','update','removed','returned') NOT NULL,
  `changed_fields` TEXT            NULL,
  `previous_json`  LONGTEXT        NULL,
  `row_hash`       CHAR(40)        NULL,
  `raw_json`       LONGTEXT        NULL,
  `changed_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=FEDERATED DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  CONNECTION='srv_novo_pages/paginas_history'
  COMMENT='Janela para 209.159.144.7 5c_pages.paginas_history (só leitura)';

-- ---------- 3. Tabelas locais ----------
-- Históricos: mesmos ids do servidor novo. O code fica na mesma collation do
-- 5c_headcount.headcount, para cruzar sem COLLATE.
CREATE TABLE IF NOT EXISTS `5c_headcount`.`headcount_history` (
  `id`             BIGINT UNSIGNED NOT NULL COMMENT 'id do servidor novo',
  `import_log_id`  INT UNSIGNED    NULL     COMMENT 'import_log do servidor novo',
  `code`           VARCHAR(30)     NOT NULL,
  `change_type`    ENUM('insert','update','removed','returned') NOT NULL,
  `changed_fields` TEXT            NULL     COMMENT 'JSON com os campos que mudaram (update)',
  `row_hash`       CHAR(40)        NULL,
  `raw_json`       LONGTEXT        NULL     COMMENT 'pessoa como ficou depois da mudança',
  `changed_at`     DATETIME        NOT NULL,
  `copiado_em`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hh_code_changed` (`code`, `changed_at`),
  KEY `idx_hh_changed_at` (`changed_at`),
  KEY `idx_hh_tipo` (`change_type`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Histórico do headcount copiado do servidor novo 209.159.144.7 (NS_05)';

CREATE TABLE IF NOT EXISTS `5c_pages`.`paginas_history` (
  `id`             BIGINT UNSIGNED NOT NULL COMMENT 'id do servidor novo',
  `import_log_id`  INT UNSIGNED    NULL     COMMENT 'import_log do servidor novo',
  `page_id`        INT UNSIGNED    NOT NULL COMMENT '= 5c_pages.pages.id',
  `change_type`    ENUM('insert','update','removed','returned') NOT NULL,
  `changed_fields` TEXT            NULL     COMMENT 'JSON com os campos que mudaram (update)',
  `previous_json`  LONGTEXT        NULL     COMMENT 'página como estava antes',
  `row_hash`       CHAR(40)        NULL,
  `raw_json`       LONGTEXT        NULL     COMMENT 'página como ficou depois',
  `changed_at`     DATETIME        NOT NULL,
  `copiado_em`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ph_page_changed` (`page_id`, `changed_at`),
  KEY `idx_ph_changed_at` (`changed_at`),
  KEY `idx_ph_tipo` (`change_type`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico das páginas copiado do servidor novo 209.159.144.7 (NS_05)';

-- De-para do nome da criadora: o servidor novo usa o usuário do Fanvue
-- ("ivyvale"); o .11 usa o nome da página ("Ivy Vale"). Criadora nova no
-- Fanvue: inclua aqui e rode CALL `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`(0);
CREATE TABLE IF NOT EXISTS `5c_wfm_db`.`fanvue_criadora_de_para` (
  `fanvue_creator` VARCHAR(100) NOT NULL COMMENT 'como vem do servidor novo (minúsculo, sem espaço)',
  `creator_name`   VARCHAR(100) NOT NULL COMMENT 'como fica na statements / statements_fanvue',
  `criado_em`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`fanvue_creator`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='De-para criadora Fanvue (servidor novo) → nome no .11';

-- Carga inicial: cada nome já usado no .11 ("Ivy Vale" → "ivyvale").
INSERT IGNORE INTO `5c_wfm_db`.`fanvue_criadora_de_para` (fanvue_creator, creator_name)
SELECT DISTINCT LOWER(REPLACE(CREATOR, ' ', '')), CREATOR
  FROM `5c_wfm_db`.`statements_fanvue`
 WHERE CREATOR IS NOT NULL AND CREATOR <> '';

-- Uma linha por execução dos syncs deste script (o headcount e as páginas
-- continuam no import_log de cada schema, NS_02).
CREATE TABLE IF NOT EXISTS `5c_wfm_db`.`sync_novo_servidor_log` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `origem`           VARCHAR(30)     NOT NULL COMMENT 'fanvue | headcount_history | paginas_history',
  `iniciado_em`      DATETIME        NOT NULL,
  `terminado_em`     DATETIME        NULL,
  `status`           ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  `desde_id_remoto`  BIGINT UNSIGNED NULL,
  `ultimo_id_remoto` BIGINT UNSIGNED NULL COMMENT 'maior id lido no servidor novo',
  `lidos`            INT UNSIGNED    NULL,
  `novos`            INT UNSIGNED    NULL COMMENT 'fanvue: linhas novas na statements_fanvue',
  `novos_statements` INT UNSIGNED    NULL COMMENT 'fanvue: linhas novas na statements',
  `pendentes`        INT UNSIGNED    NULL COMMENT 'fanvue: sem de-para de criadora ou tipo desconhecido',
  `mensagem`         TEXT            NULL,
  PRIMARY KEY (`id`),
  KEY `idx_origem` (`origem`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Execuções dos syncs do servidor novo 209.159.144.7 (NS_05)';


DELIMITER $$

-- =====================================================================
-- FANVUE
--   p_desde_id NULL = continua de onde parou (com 500 ids de folga).
--   p_desde_id 0    = relê tudo (use depois de incluir um de-para novo).
-- =====================================================================
DROP PROCEDURE IF EXISTS `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`$$
CREATE PROCEDURE `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`(IN p_desde_id BIGINT)
COMMENT 'Copia vendas Fanvue novas de 209.159.144.7 para statements_fanvue e statements'
proc: BEGIN
  DECLARE v_log          BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_ultimo       BIGINT DEFAULT 0;
  DECLARE v_desde        BIGINT DEFAULT 0;
  DECLARE v_ate          BIGINT DEFAULT NULL;
  DECLARE v_lidos        INT DEFAULT 0;
  DECLARE v_novos        INT DEFAULT 0;
  DECLARE v_novos_stmt   INT DEFAULT 0;
  DECLARE v_sem_de_para  INT DEFAULT 0;
  DECLARE v_tipo_novo    INT DEFAULT 0;
  DECLARE v_lista        TEXT DEFAULT NULL;
  DECLARE v_erro         TEXT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
    ROLLBACK;
    IF v_log IS NOT NULL THEN
      UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
         SET terminado_em = NOW(), status = 'failed',
             mensagem = CONCAT('Falhou: ', IFNULL(v_erro, '?'))
       WHERE id = v_log;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_fv_novo;
    DO RELEASE_LOCK('sync_fanvue_novo_servidor');
  END;

  IF GET_LOCK('sync_fanvue_novo_servidor', 0) = 0 THEN
    LEAVE proc;
  END IF;

  SELECT IFNULL(MAX(ultimo_id_remoto), 0) INTO v_ultimo
    FROM `5c_wfm_db`.`sync_novo_servidor_log`
   WHERE origem = 'fanvue' AND status = 'success';
  SET v_desde = IF(p_desde_id IS NULL, GREATEST(v_ultimo - 500, 0), GREATEST(p_desde_id, 0));

  INSERT INTO `5c_wfm_db`.`sync_novo_servidor_log` (origem, iniciado_em, status, desde_id_remoto)
  VALUES ('fanvue', NOW(), 'running', v_desde);
  SET v_log = LAST_INSERT_ID();

  DROP TEMPORARY TABLE IF EXISTS tmp_fv_novo;
  CREATE TEMPORARY TABLE tmp_fv_novo (
    `id_remoto`    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `creator`      VARCHAR(100)    NOT NULL,
    `invoice`      VARCHAR(32)     NOT NULL,
    `paid_at_brt`  DATETIME        NOT NULL,  -- paid_at_utc do servidor novo (está em Brasília)
    `tipo`         VARCHAR(20)     NOT NULL,
    `fan_handle`   VARCHAR(64)     NOT NULL,
    `net`          DECIMAL(12,2)   NOT NULL,
    `tax`          DECIMAL(12,2)   NOT NULL,
    `fee`          DECIMAL(12,2)   NOT NULL,
    `price`        DECIMAL(12,2)   NOT NULL,
    `gross`        DECIMAL(12,2)   NOT NULL,
    `creator_name` VARCHAR(100)    NULL,
    `ja_existe`    TINYINT(1)      NOT NULL DEFAULT 0,
    `tipo_ok`      TINYINT(1)      NOT NULL DEFAULT 0,
    KEY `idx_invoice` (`invoice`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

  -- Leitura por faixa de id (vai para o servidor novo como WHERE id > ...).
  -- As linhas que o servidor novo copiou do .11 ('sync:...') ficam de fora:
  -- já estão aqui.
  INSERT INTO tmp_fv_novo (id_remoto, creator, invoice, paid_at_brt, tipo, fan_handle, net, tax, fee, price, gross)
  SELECT id, creator, invoice_number, paid_at_utc, type, fan_handle, net, tax, fanvue_fee, price, gross
    FROM `5c_wfm_db`.`fed_fanvue_statements`
   WHERE id > v_desde
     AND source_file NOT LIKE 'sync:%';

  SELECT COUNT(*), MAX(id_remoto) INTO v_lidos, v_ate FROM tmp_fv_novo;

  UPDATE tmp_fv_novo t
    JOIN `5c_wfm_db`.`fanvue_criadora_de_para` m ON m.fanvue_creator = t.creator
     SET t.creator_name = m.creator_name;

  UPDATE tmp_fv_novo t
     SET t.ja_existe = EXISTS (SELECT 1 FROM `5c_wfm_db`.`statements_fanvue` f
                                WHERE f.`Invoice Number` = t.invoice),
         t.tipo_ok = LOWER(TRIM(t.tipo)) IN ('message', 'post', 'renewal', 'subscription',
                                             'tip', 'referal', 'referral', 'reversal');

  SELECT SUM(creator_name IS NULL), SUM(creator_name IS NOT NULL AND tipo_ok = 0),
         GROUP_CONCAT(DISTINCT IF(creator_name IS NULL, creator, CONCAT(creator, ':', tipo)))
    INTO v_sem_de_para, v_tipo_novo, v_lista
    FROM tmp_fv_novo
   WHERE ja_existe = 0 AND (creator_name IS NULL OR tipo_ok = 0);

  START TRANSACTION;

  -- Bruto: igual ao que o etl_fanvue grava a partir do CSV.
  INSERT INTO `5c_wfm_db`.`statements_fanvue`
    (`CREATOR`, `Type`, `Price`, `Gross`, `Fanvue Fee`, `Net`, `Tax`, `Invoice Number`, `Paid At (UTC)`, `Fan Handle`)
  -- FUSO: Paid At (UTC) = horário de Brasília do servidor novo + 3 h.
  SELECT creator_name, tipo, price, gross, fee, net, tax, invoice,
         paid_at_brt + INTERVAL 3 HOUR, fan_handle
    FROM tmp_fv_novo
   WHERE ja_existe = 0 AND creator_name IS NOT NULL
   ORDER BY paid_at_brt, id_remoto;
  SET v_novos = ROW_COUNT();

  -- Produção: mesmas regras do etl_fanvue (buildDescription, buildStatus,
  -- toUtcMinus3 e abs nos valores). Duplicata exata = ignorada (uq_statements).
  -- FUSO: DATE = UTC − 3 h = o horário que já vem do servidor novo.
  INSERT INTO `5c_wfm_db`.`statements`
    (`DATE`, `AMOUNT`, `FEE`, `NET`, `DESCRIPTION`, `STATUS`, `CREATOR_NAME`, `FAN_NAME`, `FAN_USERNAME`)
  SELECT t.paid_at_brt,
         ABS(t.gross), ABS(t.fee), ABS(t.net),
         CONCAT(CASE LOWER(TRIM(t.tipo))
                  WHEN 'message'      THEN 'Payment for message from '
                  WHEN 'post'         THEN 'Payment for message from '
                  WHEN 'subscription' THEN 'Subscription from '
                  WHEN 'renewal'      THEN 'Recurring subscription from '
                  WHEN 'tip'          THEN 'Tip from '
                  WHEN 'referal'      THEN 'Referal from '
                  WHEN 'referral'     THEN 'Referal from '
                  WHEN 'reversal'     THEN 'Reverse from '
                END, TRIM(t.fan_handle), ' FanVue'),
         IF(LOWER(TRIM(t.tipo)) = 'reversal', 'reverse', 'complete'),
         t.creator_name, LEFT(t.fan_handle, 100), LEFT(t.fan_handle, 100)
    FROM tmp_fv_novo t
   WHERE t.ja_existe = 0 AND t.creator_name IS NOT NULL AND t.tipo_ok = 1
   ORDER BY t.paid_at_brt, t.id_remoto
  ON DUPLICATE KEY UPDATE idstatements = idstatements;
  SET v_novos_stmt = ROW_COUNT();

  COMMIT;

  UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
     SET terminado_em = NOW(), status = 'success',
         ultimo_id_remoto = IFNULL(v_ate, v_ultimo), lidos = v_lidos,
         novos = v_novos, novos_statements = v_novos_stmt,
         pendentes = IFNULL(v_sem_de_para, 0) + IFNULL(v_tipo_novo, 0),
         mensagem = CONCAT(v_lidos, ' lidas, ', v_novos, ' novas na statements_fanvue, ',
                           v_novos_stmt, ' na statements',
                           IF(IFNULL(v_sem_de_para, 0) > 0,
                              CONCAT(' · ', v_sem_de_para, ' sem de-para de criadora (fanvue_criadora_de_para)'), ''),
                           IF(IFNULL(v_tipo_novo, 0) > 0,
                              CONCAT(' · ', v_tipo_novo, ' de tipo desconhecido (só no bruto)'), ''),
                           IF(v_lista IS NULL, '', CONCAT(': ', LEFT(v_lista, 300))))
   WHERE id = v_log;

  DROP TEMPORARY TABLE IF EXISTS tmp_fv_novo;
  DO RELEASE_LOCK('sync_fanvue_novo_servidor');
END$$


-- =====================================================================
-- HISTÓRICO DO HEADCOUNT (só acrescenta; o histórico não muda lá)
-- =====================================================================
DROP PROCEDURE IF EXISTS `5c_headcount`.`sp_sync_headcount_history_novo_servidor`$$
CREATE PROCEDURE `5c_headcount`.`sp_sync_headcount_history_novo_servidor`()
COMMENT 'Copia o headcount_history novo de 209.159.144.7'
proc: BEGIN
  DECLARE v_log   BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_desde BIGINT DEFAULT 0;
  DECLARE v_novos INT DEFAULT 0;
  DECLARE v_ate   BIGINT DEFAULT NULL;
  DECLARE v_erro  TEXT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
    ROLLBACK;
    IF v_log IS NOT NULL THEN
      UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
         SET terminado_em = NOW(), status = 'failed', mensagem = CONCAT('Falhou: ', IFNULL(v_erro, '?'))
       WHERE id = v_log;
    END IF;
    DO RELEASE_LOCK('sync_headcount_history_novo_servidor');
  END;

  IF GET_LOCK('sync_headcount_history_novo_servidor', 0) = 0 THEN
    LEAVE proc;
  END IF;

  SELECT IFNULL(MAX(id), 0) INTO v_desde FROM `5c_headcount`.`headcount_history`;

  INSERT INTO `5c_wfm_db`.`sync_novo_servidor_log` (origem, iniciado_em, status, desde_id_remoto)
  VALUES ('headcount_history', NOW(), 'running', v_desde);
  SET v_log = LAST_INSERT_ID();

  START TRANSACTION;
  INSERT INTO `5c_headcount`.`headcount_history`
    (id, import_log_id, code, change_type, changed_fields, row_hash, raw_json, changed_at)
  SELECT id, import_log_id, code, change_type, changed_fields, row_hash, raw_json, changed_at
    FROM `5c_headcount`.`fed_headcount_history`
   WHERE id > v_desde;
  SET v_novos = ROW_COUNT();
  COMMIT;

  SELECT MAX(id) INTO v_ate FROM `5c_headcount`.`headcount_history`;

  UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
     SET terminado_em = NOW(), status = 'success', ultimo_id_remoto = v_ate,
         lidos = v_novos, novos = v_novos,
         mensagem = CONCAT(v_novos, ' mudanças novas no histórico do headcount')
   WHERE id = v_log;

  DO RELEASE_LOCK('sync_headcount_history_novo_servidor');
END$$


-- =====================================================================
-- HISTÓRICO DAS PÁGINAS
-- =====================================================================
DROP PROCEDURE IF EXISTS `5c_pages`.`sp_sync_paginas_history_novo_servidor`$$
CREATE PROCEDURE `5c_pages`.`sp_sync_paginas_history_novo_servidor`()
COMMENT 'Copia o paginas_history novo de 209.159.144.7'
proc: BEGIN
  DECLARE v_log   BIGINT UNSIGNED DEFAULT NULL;
  DECLARE v_desde BIGINT DEFAULT 0;
  DECLARE v_novos INT DEFAULT 0;
  DECLARE v_ate   BIGINT DEFAULT NULL;
  DECLARE v_erro  TEXT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
    ROLLBACK;
    IF v_log IS NOT NULL THEN
      UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
         SET terminado_em = NOW(), status = 'failed', mensagem = CONCAT('Falhou: ', IFNULL(v_erro, '?'))
       WHERE id = v_log;
    END IF;
    DO RELEASE_LOCK('sync_paginas_history_novo_servidor');
  END;

  IF GET_LOCK('sync_paginas_history_novo_servidor', 0) = 0 THEN
    LEAVE proc;
  END IF;

  SELECT IFNULL(MAX(id), 0) INTO v_desde FROM `5c_pages`.`paginas_history`;

  INSERT INTO `5c_wfm_db`.`sync_novo_servidor_log` (origem, iniciado_em, status, desde_id_remoto)
  VALUES ('paginas_history', NOW(), 'running', v_desde);
  SET v_log = LAST_INSERT_ID();

  START TRANSACTION;
  INSERT INTO `5c_pages`.`paginas_history`
    (id, import_log_id, page_id, change_type, changed_fields, previous_json, row_hash, raw_json, changed_at)
  SELECT id, import_log_id, page_id, change_type, changed_fields, previous_json, row_hash, raw_json, changed_at
    FROM `5c_pages`.`fed_paginas_history`
   WHERE id > v_desde;
  SET v_novos = ROW_COUNT();
  COMMIT;

  SELECT MAX(id) INTO v_ate FROM `5c_pages`.`paginas_history`;

  UPDATE `5c_wfm_db`.`sync_novo_servidor_log`
     SET terminado_em = NOW(), status = 'success', ultimo_id_remoto = v_ate,
         lidos = v_novos, novos = v_novos,
         mensagem = CONCAT(v_novos, ' mudanças novas no histórico das páginas')
   WHERE id = v_log;

  DO RELEASE_LOCK('sync_paginas_history_novo_servidor');
END$$

DELIMITER ;

-- ---------- 4. Primeira carga manual ----------
-- Confira com o NS_07 (bloco A) antes. Hoje o Fanvue deve trazer só as
-- vendas da Ivy Vale depois da última carga do etl_fanvue (~60-70 linhas):
-- o histórico do servidor novo é igual ao do .11.
-- CALL `5c_headcount`.`sp_sync_headcount_history_novo_servidor`();
-- CALL `5c_pages`.`sp_sync_paginas_history_novo_servidor`();
-- CALL `5c_wfm_db`.`sp_sync_fanvue_novo_servidor`(NULL);
