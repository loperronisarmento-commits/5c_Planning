-- =====================================================================
-- NS_04 · Validação (só SELECT; rodar no .11)
-- ---------------------------------------------------------------------
-- A: antes da primeira carga (depois do NS_01)
-- B: depois do CALL manual de cada procedure
-- C: depois dos events (NS_03), no dia a dia
-- =====================================================================

-- ---------- A1. O que a primeira carga vai mudar ----------
-- Esperado hoje: 395 × 395, 0 novos, 0 saindo, 133 × 133 páginas.
SELECT 'headcount' AS tabela,
       (SELECT COUNT(*) FROM `5c_headcount`.`fed_headcount` WHERE in_api = 1) AS remoto,
       (SELECT COUNT(*) FROM `5c_headcount`.`headcount`)                     AS local,
       (SELECT COUNT(*) FROM `5c_headcount`.`fed_headcount` f
         WHERE f.in_api = 1
           AND NOT EXISTS (SELECT 1 FROM `5c_headcount`.`headcount` h
                            WHERE h.code = f.code COLLATE utf8mb4_0900_ai_ci)) AS novos,
       (SELECT COUNT(*) FROM `5c_headcount`.`headcount` h
         WHERE NOT EXISTS (SELECT 1 FROM `5c_headcount`.`fed_headcount` f
                            WHERE f.in_api = 1 AND f.code COLLATE utf8mb4_0900_ai_ci = h.code)) AS saindo
UNION ALL
SELECT 'pages (ativas)',
       (SELECT COUNT(*) FROM `5c_pages`.`fed_paginas` WHERE in_api = 1 AND IFNULL(active, 1) = 1),
       (SELECT COUNT(*) FROM `5c_pages`.`pages` WHERE active = 1),
       (SELECT COUNT(*) FROM `5c_pages`.`fed_paginas` f
         WHERE f.in_api = 1 AND IFNULL(f.active, 1) = 1
           AND NOT EXISTS (SELECT 1 FROM `5c_pages`.`pages` p WHERE p.id = f.id AND p.active = 1)),
       (SELECT COUNT(*) FROM `5c_pages`.`pages` p
         WHERE p.active = 1
           AND NOT EXISTS (SELECT 1 FROM `5c_pages`.`fed_paginas` f
                            WHERE f.id = p.id AND f.in_api = 1 AND IFNULL(f.active, 1) = 1));

-- ---------- B1. Últimas execuções (status success, mensagem do sync) ----------
(SELECT 'headcount' AS origem, id, started_at, finished_at, status, total_records, inserted, updated, unchanged, message
   FROM `5c_headcount`.`import_log` ORDER BY id DESC LIMIT 5)
UNION ALL
(SELECT 'pages', id, started_at, finished_at, status, total_records, inserted, updated, unchanged, message
   FROM `5c_pages`.`import_log` ORDER BY id DESC LIMIT 5);

-- ---------- B2. Local igual ao remoto (as duas colunas de cada par iguais) ----------
SELECT 'headcount' AS tabela,
       (SELECT SUM(CRC32(CONCAT_WS('|', code, IFNULL(name,''), IFNULL(position,''), IFNULL(departament,''),
                                   IFNULL(active,''), IFNULL(contractDate,''), IFNULL(terminationDate,''),
                                   IFNULL(experience,''), IFNULL(telegram,''), IFNULL(totalTime,''))))
          FROM `5c_headcount`.`fed_headcount` WHERE in_api = 1) AS chk_remoto,
       (SELECT SUM(CRC32(CONCAT_WS('|', code, IFNULL(name,''), IFNULL(position,''), IFNULL(departament,''),
                                   IFNULL(active,''), IFNULL(contractDate,''), IFNULL(terminationDate,''),
                                   IFNULL(experience,''), IFNULL(telegram,''), IFNULL(totalTime,''))))
          FROM `5c_headcount`.`headcount`) AS chk_local
UNION ALL
SELECT 'pages (ativas)',
       (SELECT SUM(CRC32(CONCAT_WS('|', id, IFNULL(name,''), IFNULL(tier,''), IFNULL(platform,''),
                                   IFNULL(accountManager,''), IFNULL(client,''))))
          FROM `5c_pages`.`fed_paginas` WHERE in_api = 1 AND IFNULL(active, 1) = 1),
       (SELECT SUM(CRC32(CONCAT_WS('|', id, IFNULL(name,''), IFNULL(tier,''), IFNULL(platform,''),
                                   IFNULL(accountManager,''), IFNULL(client,''))))
          FROM `5c_pages`.`pages` WHERE active = 1);

-- ---------- B3. Os 4 campos que a API nova não manda continuam preenchidos ----------
-- Esperado: 0 em todas as colunas "sem_".
SELECT COUNT(*) AS pessoas,
       SUM(registerDate IS NULL)     AS sem_registerDate,
       SUM(startContract IS NULL)    AS sem_startContract,
       SUM(contract IS NULL)         AS sem_contract,
       SUM(contractExpiring IS NULL) AS sem_contractExpiring,
       SUM(JSON_VALID(raw_json) = 0) AS raw_json_invalido,
       SUM(JSON_EXTRACT(raw_json, '$.startContract') IS NULL) AS raw_sem_startContract,
       SUM(JSON_EXTRACT(raw_json, '$.workPattern') IS NOT NULL) AS raw_com_campos_novos
  FROM `5c_headcount`.`headcount`;

-- ---------- B4. As views e o bot continuam lendo ----------
-- Antes da troca (21/10/2026): vw_rep 395 · vw_pagina 472 ·
-- vw_pagina_party_atual 225 · vw_rep_headcount 395 · v_headcount_turno_atual 292 ·
-- v_reps_sem_turno 1 · v_desligados 29 · v_cobertura_party 48.
SELECT '5c_bot.vw_rep' AS objeto, COUNT(*) AS linhas FROM `5c_bot`.`vw_rep`
UNION ALL SELECT '5c_bot.vw_pagina', COUNT(*) FROM `5c_bot`.`vw_pagina`
UNION ALL SELECT '5c_bot.vw_pagina_party_atual', COUNT(*) FROM `5c_bot`.`vw_pagina_party_atual`
UNION ALL SELECT '5c_wfm_db_mis.vw_rep_headcount', COUNT(*) FROM `5c_wfm_db_mis`.`vw_rep_headcount`
UNION ALL SELECT '5c_headcount.v_headcount_turno_atual', COUNT(*) FROM `5c_headcount`.`v_headcount_turno_atual`
UNION ALL SELECT '5c_headcount.v_reps_sem_turno', COUNT(*) FROM `5c_headcount`.`v_reps_sem_turno`
UNION ALL SELECT '5c_headcount.v_desligados', COUNT(*) FROM `5c_headcount`.`v_desligados`
UNION ALL SELECT '5c_headcount.v_cobertura_party', COUNT(*) FROM `5c_headcount`.`v_cobertura_party`;

-- ---------- C1. Saúde dos events (rodar depois de 20-30 min) ----------
-- Esperado: LAST_EXECUTED há menos de 10 min; última linha do import_log
-- 'success' com "Sync do servidor novo" na mensagem.
SELECT EVENT_SCHEMA, EVENT_NAME, STATUS, LAST_EXECUTED,
       TIMESTAMPDIFF(MINUTE, LAST_EXECUTED, NOW()) AS min_desde_ultima
  FROM information_schema.EVENTS
 WHERE EVENT_NAME IN ('ev_sync_headcount_novo_servidor', 'ev_sync_pages_novo_servidor');

SELECT 'headcount' AS origem, status, COUNT(*) AS execucoes, MAX(started_at) AS ultima
  FROM `5c_headcount`.`import_log`
 WHERE started_at >= NOW() - INTERVAL 1 DAY AND message LIKE 'Sync do servidor novo%'
 GROUP BY status
UNION ALL
SELECT 'pages', status, COUNT(*), MAX(started_at)
  FROM `5c_pages`.`import_log`
 WHERE started_at >= NOW() - INTERVAL 1 DAY AND message LIKE 'Sync do servidor novo%'
 GROUP BY status;

-- ---------- C2. Ninguém mais escreve pela API antiga ----------
-- Esperado: nenhuma linha nova sem "Sync do servidor novo" depois da troca.
SELECT 'headcount' AS origem, id, started_at, status, LEFT(message, 80) AS message
  FROM `5c_headcount`.`import_log`
 WHERE started_at >= NOW() - INTERVAL 1 DAY AND IFNULL(message, '') NOT LIKE 'Sync do servidor novo%'
UNION ALL
SELECT 'pages', id, started_at, status, LEFT(message, 80)
  FROM `5c_pages`.`import_log`
 WHERE started_at >= NOW() - INTERVAL 1 DAY AND IFNULL(message, '') NOT LIKE 'Sync do servidor novo%'
 ORDER BY started_at DESC;
