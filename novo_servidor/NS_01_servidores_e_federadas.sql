-- =====================================================================
-- NS_01 · Servidores remotos e tabelas FEDERATED (rodar no .11)
-- ---------------------------------------------------------------------
-- O headcount e as páginas passam a vir do servidor novo 209.159.144.7
-- (atualizados lá a cada 5-10 min pela API MisDev). Aqui no .11 criamos
-- uma "janela" para essas tabelas; o NS_02 copia delas para as tabelas
-- locais de sempre, então views, procedures e ferramentas não mudam.
--
-- ANTES DE RODAR, troque os 3 marcadores nas duas instruções CREATE SERVER:
--   <usuario>  <senha>  <porta>
-- São os mesmos dados de acesso do 209.159.144.7 que o DBA já usa na
-- federada de teste. Para ver: SHOW CREATE TABLE `5c_wfm_db`.`teste_creatonomy`;
-- (a CONNECTION mostra usuário, senha e porta). Não salve este arquivo com
-- a senha preenchida nem faça commit dele assim.
--
-- Precisa: engine FEDERATED ligada (já está: teste_creatonomy usa) e
-- privilégio FEDERATED_ADMIN (ou SUPER) para o CREATE SERVER.
-- =====================================================================

-- ---------- 1. Servidores (um por schema remoto) ----------
DROP SERVER IF EXISTS srv_novo_headcount;
CREATE SERVER srv_novo_headcount
  FOREIGN DATA WRAPPER mysql
  OPTIONS (HOST '209.159.144.7', PORT <porta>, DATABASE '5c_headcount', USER '<usuario>', PASSWORD '<senha>');

DROP SERVER IF EXISTS srv_novo_pages;
CREATE SERVER srv_novo_pages
  FOREIGN DATA WRAPPER mysql
  OPTIONS (HOST '209.159.144.7', PORT <porta>, DATABASE '5c_pages', USER '<usuario>', PASSWORD '<senha>');

-- ---------- 2. Federada do headcount (mesma estrutura do remoto) ----------
DROP TABLE IF EXISTS `5c_headcount`.`fed_headcount`;
CREATE TABLE `5c_headcount`.`fed_headcount` (
  `id`                  INT UNSIGNED NOT NULL,
  `code`                VARCHAR(30)  NOT NULL,
  `name`                VARCHAR(255) NULL,
  `socialName`          VARCHAR(255) NULL,
  `telegram`            VARCHAR(150) NULL,
  `companyEmail`        VARCHAR(255) NULL,
  `personalEmail`       VARCHAR(255) NULL,
  `departament`         VARCHAR(150) NULL,
  `position`            VARCHAR(255) NULL,
  `birthdate`           VARCHAR(20)  NULL,
  `gender`              VARCHAR(30)  NULL,
  `nacionality`         VARCHAR(100) NULL,
  `registerDate`        VARCHAR(20)  NULL,
  `startContract`       VARCHAR(20)  NULL,
  `experience`          TINYINT(1)   NULL,
  `contract`            TINYINT(1)   NULL,
  `terminationDate`     VARCHAR(20)  NULL,
  `totalTime`           VARCHAR(50)  NULL,
  `contractDate`        VARCHAR(50)  NULL,
  `contractExpiring`    VARCHAR(50)  NULL,
  `active`              TINYINT(1)   NULL,
  `raw_json`            LONGTEXT     NULL,
  `row_hash`            CHAR(40)     NOT NULL,
  `in_api`              TINYINT(1)   NOT NULL,
  `first_seen_at`       DATETIME     NOT NULL,
  `last_seen_at`        DATETIME     NOT NULL,
  `last_changed_at`     DATETIME     NOT NULL,
  `removed_from_api_at` DATETIME     NULL,
  `first_run_id`        INT UNSIGNED NULL,
  `last_run_id`         INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_headcount_code` (`code`),
  KEY `idx_headcount_in_api` (`in_api`)
) ENGINE=FEDERATED DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  CONNECTION='srv_novo_headcount/headcount'
  COMMENT='Janela para 209.159.144.7 5c_headcount.headcount (só leitura; usada pelo sp_sync_headcount_novo_servidor)';

-- ---------- 3. Federada das páginas ----------
DROP TABLE IF EXISTS `5c_pages`.`fed_paginas`;
CREATE TABLE `5c_pages`.`fed_paginas` (
  `id`                  INT UNSIGNED NOT NULL,
  `name`                VARCHAR(255) NULL,
  `tier`                VARCHAR(20)  NULL,
  `platform`            VARCHAR(100) NULL,
  `accountManagerCode`  VARCHAR(30)  NULL,
  `accountManager`      VARCHAR(255) NULL,
  `client`              VARCHAR(255) NULL,
  `active`              TINYINT(1)   NULL,
  `raw_json`            LONGTEXT     NULL,
  `row_hash`            CHAR(40)     NOT NULL,
  `in_api`              TINYINT(1)   NOT NULL,
  `first_seen_at`       DATETIME     NOT NULL,
  `last_seen_at`        DATETIME     NOT NULL,
  `last_changed_at`     DATETIME     NOT NULL,
  `removed_from_api_at` DATETIME     NULL,
  `first_run_id`        INT UNSIGNED NULL,
  `last_run_id`         INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_paginas_in_api` (`in_api`)
) ENGINE=FEDERATED DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  CONNECTION='srv_novo_pages/paginas'
  COMMENT='Janela para 209.159.144.7 5c_pages.paginas (só leitura; usada pelo sp_sync_pages_novo_servidor)';

-- ---------- 4. Teste rápido (deve devolver ~395 e ~133) ----------
SELECT (SELECT COUNT(*) FROM `5c_headcount`.`fed_headcount` WHERE in_api = 1) AS headcount_remoto,
       (SELECT COUNT(*) FROM `5c_pages`.`fed_paginas` WHERE in_api = 1)       AS paginas_remoto;
