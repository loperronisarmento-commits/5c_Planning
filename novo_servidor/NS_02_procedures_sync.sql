-- =====================================================================
-- NS_02 · Procedures de sincronização (rodar no .11, depois do NS_01)
-- ---------------------------------------------------------------------
-- Copiam do servidor novo (via federadas do NS_01) para as tabelas locais
-- de sempre. Nada muda para quem lê `5c_headcount`.`headcount` e
-- `5c_pages`.`pages` (views, procedures, bot, Capacity, módulos do portal).
--
-- HEADCOUNT  (chave: code; o id local é mantido)
--   - Só entra quem está na API (in_api = 1).
--   - A API nova não manda registerDate, startContract, contract e
--     contractExpiring. Enquanto não mandar:
--       * quem já existe aqui mantém o valor local;
--       * startContract 'Not Started' passa a contractDate quando um
--         contrato novo aparece (mesma regra dos cadastros recentes);
--       * contractExpiring vira '-' (não informado) quando o contractDate
--         muda: a data de vencimento não segue uma regra fixa;
--       * quem é novo: registerDate = 1º dia em que apareceu na API nova,
--         startContract = contractDate (ou 'Not Started'), contract = 0,
--         contractExpiring = '-'.
--     Quando a API passar a mandar esses campos, o valor dela vale.
--   - raw_json: o JSON novo por cima do antigo (as chaves que só existiam
--     no antigo, como startContract, continuam lá).
--   - Quem saiu da API: cópia em sync_removed_rows e DELETE (igual ao ETL
--     antigo; o sp_fc_hc_to usa essa cópia para o turnover).
--     Trava: não remove nada se o remoto vier vazio ou se for remover mais
--     de 50% de uma tabela com 20+ linhas.
--
-- PÁGINAS  (chave: id, o mesmo nos dois servidores)
--   - Páginas ativas da API: insert/update com active = 1.
--   - Páginas que deixaram de vir: active = 0 (nunca apaga; o histórico de
--     inativas e o sp_fc_paginas_eventos dependem disso). Mesma trava.
--
-- As duas gravam em import_log (running → success/failed), como os ETLs.
-- Uma execução por vez (GET_LOCK).
-- =====================================================================

DELIMITER $$

-- =====================================================================
-- HEADCOUNT
-- =====================================================================
DROP PROCEDURE IF EXISTS `5c_headcount`.`sp_sync_headcount_novo_servidor`$$
CREATE PROCEDURE `5c_headcount`.`sp_sync_headcount_novo_servidor`()
COMMENT 'Espelha 209.159.144.7 5c_headcount.headcount em 5c_headcount.headcount'
proc: BEGIN
  DECLARE v_log       INT UNSIGNED DEFAULT NULL;
  DECLARE v_total     INT DEFAULT 0;
  DECLARE v_locais    INT DEFAULT 0;
  DECLARE v_novos     INT DEFAULT 0;
  DECLARE v_alterados INT DEFAULT 0;
  DECLARE v_faltando  INT DEFAULT 0;
  DECLARE v_removidos INT DEFAULT 0;
  DECLARE v_aviso     TEXT DEFAULT '';
  DECLARE v_erro      TEXT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
    ROLLBACK;
    IF v_log IS NOT NULL THEN
      UPDATE `5c_headcount`.`import_log`
         SET finished_at = NOW(), status = 'failed', errors = 1,
             message = CONCAT('Sync do servidor novo (209.159.144.7) falhou: ', IFNULL(v_erro, '?'))
       WHERE id = v_log;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_hc_novo;
    DO RELEASE_LOCK('sync_headcount_novo_servidor');
  END;

  IF GET_LOCK('sync_headcount_novo_servidor', 0) = 0 THEN
    LEAVE proc;  -- a execução anterior ainda está rodando
  END IF;

  INSERT INTO `5c_headcount`.`import_log` (started_at, status, message)
  VALUES (NOW(), 'running', 'Sync do servidor novo (209.159.144.7)');
  SET v_log = LAST_INSERT_ID();

  -- 1. Uma leitura só do remoto; o resto é local.
  DROP TEMPORARY TABLE IF EXISTS tmp_hc_novo;
  CREATE TEMPORARY TABLE tmp_hc_novo (
    `code`             VARCHAR(30)  NOT NULL PRIMARY KEY,
    `name`             VARCHAR(255) NULL,
    `socialName`       VARCHAR(255) NULL,
    `telegram`         VARCHAR(150) NULL,
    `companyEmail`     VARCHAR(255) NULL,
    `personalEmail`    VARCHAR(255) NULL,
    `departament`      VARCHAR(150) NULL,
    `position`         VARCHAR(255) NULL,
    `birthdate`        VARCHAR(20)  NULL,
    `gender`           VARCHAR(30)  NULL,
    `nacionality`      VARCHAR(100) NULL,
    `registerDate`     VARCHAR(20)  NULL,
    `startContract`    VARCHAR(20)  NULL,
    `experience`       TINYINT(1)   NULL,
    `contract`         TINYINT(1)   NULL,
    `terminationDate`  VARCHAR(20)  NULL,
    `totalTime`        VARCHAR(50)  NULL,
    `contractDate`     VARCHAR(50)  NULL,
    `contractExpiring` VARCHAR(50)  NULL,
    `active`           TINYINT(1)   NULL,
    `raw_json`         LONGTEXT     NULL,
    `first_seen_at`    DATETIME     NULL,
    `tem_contrato`     TINYINT(1)   NOT NULL DEFAULT 0,  -- contractDate é uma data (e não '-')
    `raw_local`        LONGTEXT     NULL   -- raw_json que já estava no .11
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

  INSERT INTO tmp_hc_novo
    (code, name, socialName, telegram, companyEmail, personalEmail, departament, position,
     birthdate, gender, nacionality, registerDate, startContract, experience, contract,
     terminationDate, totalTime, contractDate, contractExpiring, active, raw_json, first_seen_at)
  SELECT code, name, socialName, telegram, companyEmail, personalEmail, departament, position,
         birthdate, gender, nacionality, registerDate, startContract, experience, contract,
         terminationDate, totalTime, contractDate, contractExpiring, active, raw_json, first_seen_at
    FROM `5c_headcount`.`fed_headcount`
   WHERE in_api = 1;

  UPDATE tmp_hc_novo
     SET tem_contrato = IFNULL(TRIM(contractDate) REGEXP '^[0-9]{2}/[0-9]{2}/[0-9]{4}$', 0);

  SELECT COUNT(*) INTO v_total FROM tmp_hc_novo;
  IF v_total = 0 THEN
    UPDATE `5c_headcount`.`import_log`
       SET finished_at = NOW(), status = 'failed', total_records = 0, errors = 1,
           message = 'Sync do servidor novo: o remoto não devolveu nenhum registro; nada foi alterado'
     WHERE id = v_log;
    DROP TEMPORARY TABLE IF EXISTS tmp_hc_novo;
    DO RELEASE_LOCK('sync_headcount_novo_servidor');
    LEAVE proc;
  END IF;

  SELECT COUNT(*) INTO v_locais FROM `5c_headcount`.`headcount`;
  SELECT COUNT(*) INTO v_novos
    FROM tmp_hc_novo t
   WHERE NOT EXISTS (SELECT 1 FROM `5c_headcount`.`headcount` h WHERE h.code = t.code);
  SELECT COUNT(*) INTO v_faltando
    FROM `5c_headcount`.`headcount` h
   WHERE NOT EXISTS (SELECT 1 FROM tmp_hc_novo t WHERE t.code = h.code);

  -- 2. Completa os 4 campos que a API nova não manda (o valor da API, se
  --    vier, sempre ganha) e junta o raw_json.
  UPDATE tmp_hc_novo t
    LEFT JOIN `5c_headcount`.`headcount` h ON h.code = t.code
     SET t.registerDate = COALESCE(t.registerDate, h.registerDate, DATE_FORMAT(t.first_seen_at, '%d/%m/%Y')),
         t.startContract = COALESCE(t.startContract,
                             CASE WHEN h.code IS NULL
                                    THEN IF(t.tem_contrato = 1, TRIM(t.contractDate), 'Not Started')
                                  WHEN IFNULL(h.startContract, 'Not Started') = 'Not Started'
                                       AND t.tem_contrato = 1 AND NOT (h.contractDate <=> t.contractDate)
                                    THEN TRIM(t.contractDate)
                                  ELSE h.startContract END),
         t.contract = COALESCE(t.contract, h.contract, 0),
         t.contractExpiring = COALESCE(t.contractExpiring,
                                IF(h.code IS NOT NULL AND h.contractDate <=> t.contractDate, h.contractExpiring, '-')),
         t.raw_local = h.raw_json;

  UPDATE tmp_hc_novo
     SET raw_json = JSON_MERGE_PATCH(
                      JSON_MERGE_PATCH(IF(JSON_VALID(raw_local), raw_local, '{}'),
                                       IF(JSON_VALID(raw_json),  raw_json,  '{}')),
                      JSON_OBJECT('registerDate', registerDate, 'startContract', startContract,
                                  'contract', IF(contract = 1, CAST('true' AS JSON), CAST('false' AS JSON)),
                                  'contractExpiring', contractExpiring));

  START TRANSACTION;

  -- 3. Insert/update por code (o id local não muda).
  INSERT INTO `5c_headcount`.`headcount`
    (code, name, socialName, telegram, companyEmail, personalEmail, departament, position,
     birthdate, gender, nacionality, registerDate, startContract, experience, contract,
     terminationDate, totalTime, contractDate, contractExpiring, active, raw_json)
  SELECT t.code, t.name, t.socialName, t.telegram, t.companyEmail, t.personalEmail, t.departament, t.position,
         t.birthdate, t.gender, t.nacionality, t.registerDate, t.startContract, t.experience, t.contract,
         t.terminationDate, t.totalTime, t.contractDate, t.contractExpiring, t.active, t.raw_json
    FROM tmp_hc_novo t
  ON DUPLICATE KEY UPDATE
    name = t.name, socialName = t.socialName, telegram = t.telegram,
    companyEmail = t.companyEmail, personalEmail = t.personalEmail,
    departament = t.departament, position = t.position, birthdate = t.birthdate,
    gender = t.gender, nacionality = t.nacionality, registerDate = t.registerDate,
    startContract = t.startContract, experience = t.experience, contract = t.contract,
    terminationDate = t.terminationDate, totalTime = t.totalTime,
    contractDate = t.contractDate, contractExpiring = t.contractExpiring,
    active = t.active, raw_json = t.raw_json;
  -- linhas afetadas: 1 por insert, 2 por update com mudança, 0 sem mudança
  SET v_alterados = GREATEST(ROW_COUNT() - v_novos, 0) DIV 2;

  -- 4. Quem saiu da API: arquiva e remove (com trava).
  IF v_faltando > 0 THEN
    IF v_locais >= 20 AND v_faltando / v_locais > 0.5 THEN
      SET v_aviso = CONCAT(' · remoção BLOQUEADA: removeria ', v_faltando, ' de ', v_locais,
                           ' linhas (acima de 50%); nada foi removido');
    ELSE
      INSERT INTO `5c_headcount`.`sync_removed_rows` (table_name, row_key, scope, row_json, import_log_id)
      SELECT 'headcount', h.code, NULL,
             JSON_OBJECT(
               'id', h.id, 'code', h.code, 'name', h.name, 'socialName', h.socialName,
               'telegram', h.telegram, 'companyEmail', h.companyEmail, 'personalEmail', h.personalEmail,
               'departament', h.departament, 'position', h.position, 'birthdate', h.birthdate,
               'gender', h.gender, 'nacionality', h.nacionality, 'registerDate', h.registerDate,
               'startContract', h.startContract, 'experience', h.experience, 'contract', h.contract,
               'terminationDate', h.terminationDate, 'totalTime', h.totalTime,
               'contractDate', h.contractDate, 'contractExpiring', h.contractExpiring,
               'active', h.active, 'raw_json', h.raw_json,
               'imported_at', DATE_FORMAT(h.imported_at, '%Y-%m-%d %H:%i:%s'),
               'updated_at',  DATE_FORMAT(h.updated_at,  '%Y-%m-%d %H:%i:%s')
             ),
             v_log
        FROM `5c_headcount`.`headcount` h
       WHERE NOT EXISTS (SELECT 1 FROM tmp_hc_novo t WHERE t.code = h.code);

      DELETE h FROM `5c_headcount`.`headcount` h
       WHERE NOT EXISTS (SELECT 1 FROM tmp_hc_novo t WHERE t.code = h.code);
      SET v_removidos = ROW_COUNT();
    END IF;
  END IF;

  COMMIT;

  UPDATE `5c_headcount`.`import_log`
     SET finished_at = NOW(), status = 'success', total_records = v_total,
         inserted = v_novos, updated = v_alterados,
         unchanged = GREATEST(v_total - v_novos - v_alterados, 0), errors = 0,
         message = CONCAT('Sync do servidor novo (209.159.144.7): ', v_total, ' na API, ',
                          v_novos, ' novos, ', v_alterados, ' alterados, ',
                          v_removidos, ' removidos (cópia em sync_removed_rows)', v_aviso)
   WHERE id = v_log;

  DROP TEMPORARY TABLE IF EXISTS tmp_hc_novo;
  DO RELEASE_LOCK('sync_headcount_novo_servidor');
END$$


-- =====================================================================
-- PÁGINAS
-- =====================================================================
DROP PROCEDURE IF EXISTS `5c_pages`.`sp_sync_pages_novo_servidor`$$
CREATE PROCEDURE `5c_pages`.`sp_sync_pages_novo_servidor`()
COMMENT 'Espelha 209.159.144.7 5c_pages.paginas (ativas) em 5c_pages.pages'
proc: BEGIN
  DECLARE v_log        INT UNSIGNED DEFAULT NULL;
  DECLARE v_total      INT DEFAULT 0;
  DECLARE v_ativas     INT DEFAULT 0;
  DECLARE v_novas      INT DEFAULT 0;
  DECLARE v_alteradas  INT DEFAULT 0;
  DECLARE v_saindo     INT DEFAULT 0;
  DECLARE v_inativadas INT DEFAULT 0;
  DECLARE v_aviso      TEXT DEFAULT '';
  DECLARE v_erro       TEXT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_erro = MESSAGE_TEXT;
    ROLLBACK;
    IF v_log IS NOT NULL THEN
      UPDATE `5c_pages`.`import_log`
         SET finished_at = NOW(), status = 'failed', errors = 1,
             message = CONCAT('Sync do servidor novo (209.159.144.7) falhou: ', IFNULL(v_erro, '?'))
       WHERE id = v_log;
    END IF;
    DROP TEMPORARY TABLE IF EXISTS tmp_pag_novo;
    DO RELEASE_LOCK('sync_pages_novo_servidor');
  END;

  IF GET_LOCK('sync_pages_novo_servidor', 0) = 0 THEN
    LEAVE proc;
  END IF;

  INSERT INTO `5c_pages`.`import_log` (started_at, status, message)
  VALUES (NOW(), 'running', 'Sync do servidor novo (209.159.144.7)');
  SET v_log = LAST_INSERT_ID();

  DROP TEMPORARY TABLE IF EXISTS tmp_pag_novo;
  CREATE TEMPORARY TABLE tmp_pag_novo (
    `id`             INT UNSIGNED NOT NULL PRIMARY KEY,
    `name`           VARCHAR(255) NULL,
    `tier`           VARCHAR(20)  NULL,
    `platform`       VARCHAR(150) NULL,
    `accountManager` VARCHAR(255) NULL,
    `client`         VARCHAR(255) NULL,
    `raw_json`       LONGTEXT     NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- A API nova só devolve páginas ativas.
  INSERT INTO tmp_pag_novo (id, name, tier, platform, accountManager, client, raw_json)
  SELECT id, name, tier, platform, accountManager, client, raw_json
    FROM `5c_pages`.`fed_paginas`
   WHERE in_api = 1 AND IFNULL(active, 1) = 1;

  SELECT COUNT(*) INTO v_total FROM tmp_pag_novo;
  IF v_total = 0 THEN
    UPDATE `5c_pages`.`import_log`
       SET finished_at = NOW(), status = 'failed', total_records = 0, errors = 1,
           message = 'Sync do servidor novo: o remoto não devolveu nenhuma página; nada foi alterado'
     WHERE id = v_log;
    DROP TEMPORARY TABLE IF EXISTS tmp_pag_novo;
    DO RELEASE_LOCK('sync_pages_novo_servidor');
    LEAVE proc;
  END IF;

  SELECT COUNT(*) INTO v_ativas FROM `5c_pages`.`pages` WHERE active = 1;
  SELECT COUNT(*) INTO v_novas
    FROM tmp_pag_novo t
   WHERE NOT EXISTS (SELECT 1 FROM `5c_pages`.`pages` p WHERE p.id = t.id);
  SELECT COUNT(*) INTO v_saindo
    FROM `5c_pages`.`pages` p
   WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM tmp_pag_novo t WHERE t.id = p.id);

  START TRANSACTION;

  INSERT INTO `5c_pages`.`pages` (id, name, tier, platform, accountManager, client, active, raw_json)
  SELECT t.id, t.name, t.tier, t.platform, t.accountManager, t.client, 1, t.raw_json
    FROM tmp_pag_novo t
  ON DUPLICATE KEY UPDATE
    name = t.name, tier = t.tier, platform = t.platform,
    accountManager = t.accountManager, client = t.client,
    active = 1, raw_json = t.raw_json;
  SET v_alteradas = GREATEST(ROW_COUNT() - v_novas, 0) DIV 2;

  -- Saíram da API: ficam como inativas (com trava).
  IF v_saindo > 0 THEN
    IF v_ativas >= 20 AND v_saindo / v_ativas > 0.5 THEN
      SET v_aviso = CONCAT(' · inativação BLOQUEADA: inativaria ', v_saindo, ' de ', v_ativas,
                           ' páginas ativas (acima de 50%); nada foi inativado');
    ELSE
      UPDATE `5c_pages`.`pages` p
         SET p.active = 0,
             p.raw_json = IF(JSON_VALID(p.raw_json), JSON_SET(p.raw_json, '$.active', CAST('false' AS JSON)), p.raw_json)
       WHERE p.active = 1
         AND NOT EXISTS (SELECT 1 FROM tmp_pag_novo t WHERE t.id = p.id);
      SET v_inativadas = ROW_COUNT();
    END IF;
  END IF;

  COMMIT;

  UPDATE `5c_pages`.`import_log`
     SET finished_at = NOW(), status = 'success', total_records = v_total,
         inserted = v_novas, updated = v_alteradas + v_inativadas,
         unchanged = GREATEST(v_total - v_novas - v_alteradas, 0), errors = 0,
         message = CONCAT('Sync do servidor novo (209.159.144.7): ', v_total, ' ativas na API, ',
                          v_novas, ' novas, ', v_alteradas, ' alteradas, ',
                          v_inativadas, ' inativadas', v_aviso)
   WHERE id = v_log;

  DROP TEMPORARY TABLE IF EXISTS tmp_pag_novo;
  DO RELEASE_LOCK('sync_pages_novo_servidor');
END$$

DELIMITER ;

-- Primeira carga manual (confira o resultado no NS_04 antes de ligar os events):
-- CALL `5c_headcount`.`sp_sync_headcount_novo_servidor`();
-- CALL `5c_pages`.`sp_sync_pages_novo_servidor`();
