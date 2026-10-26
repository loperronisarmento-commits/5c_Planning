-- =====================================================================
-- NS_07 · Validação do Fanvue e dos históricos (só SELECT; rodar no .11)
-- ---------------------------------------------------------------------
-- A: depois do NS_05, antes dos CALL
-- B: depois dos CALL manuais
-- C: no dia a dia (depois do NS_06)
-- =====================================================================

-- ---------- A1. A federada lê e o de-para cobre as criadoras ----------
-- Esperado: todas com creator_name preenchido (hoje só "ivyvale" vem do CSV).
SELECT f.creator, m.creator_name, COUNT(*) AS linhas, MAX(f.paid_at_utc) AS ultima_venda_brasilia
  FROM `5c_wfm_db`.`fed_fanvue_statements` f
  LEFT JOIN `5c_wfm_db`.`fanvue_criadora_de_para` m
         ON m.fanvue_creator = f.creator COLLATE utf8mb4_0900_ai_ci
 WHERE f.source_file NOT LIKE 'sync:%'
 GROUP BY f.creator, m.creator_name;

-- ---------- A2. Históricos remotos ----------
SELECT 'headcount_history' AS historico, COUNT(*) AS linhas, MIN(changed_at) AS desde, MAX(changed_at) AS ultima
  FROM `5c_headcount`.`fed_headcount_history`
UNION ALL
SELECT 'paginas_history', COUNT(*), MIN(changed_at), MAX(changed_at)
  FROM `5c_pages`.`fed_paginas_history`;

-- ---------- B1. Execuções ----------
SELECT id, origem, iniciado_em, terminado_em, status, desde_id_remoto, ultimo_id_remoto,
       lidos, novos, novos_statements, pendentes, mensagem
  FROM `5c_wfm_db`.`sync_novo_servidor_log`
 ORDER BY id DESC
 LIMIT 15;

-- ---------- B2. Fanvue: o .11 ficou igual ao servidor novo ----------
-- Esperado: invoices iguais nos dois lados em cada mês (o .11 pode ter um
-- pouco mais em meses antigos: cargas manuais que o servidor novo não tem).
SELECT SUBSTRING(`Invoice Number`, 5, 6) AS mes,
       COUNT(DISTINCT `Invoice Number`) AS invoices_11,
       MAX(`Paid At (UTC)`) AS ultima_venda_utc
  FROM `5c_wfm_db`.`statements_fanvue`
 WHERE CREATOR = 'Ivy Vale' AND `Invoice Number` >= 'FVE-202609'
 GROUP BY mes;

SELECT SUBSTRING(invoice_number, 5, 6) AS mes, COUNT(*) AS invoices_novo
  FROM `5c_wfm_db`.`fed_fanvue_statements`
 WHERE creator = 'ivyvale' AND invoice_number >= 'FVE-202609'
 GROUP BY mes;

-- ---------- B3. Fanvue: a produção recebeu as mesmas vendas ----------
-- Esperado: linhas_statements ≈ linhas_bruto de hoje (menos tipos desconhecidos).
SELECT DATE(`Paid At (UTC)` - INTERVAL 3 HOUR) AS dia, COUNT(*) AS linhas_bruto,
       SUM(Gross) AS bruto
  FROM `5c_wfm_db`.`statements_fanvue`
 WHERE `Paid At (UTC)` >= UTC_TIMESTAMP() - INTERVAL 2 DAY
 GROUP BY dia;

SELECT DATE(`DATE`) AS dia, COUNT(*) AS linhas_statements, SUM(AMOUNT) AS bruto_abs,
       SUM(CLASSIFICATION IS NULL) AS sem_classificacao
  FROM `5c_wfm_db`.`statements`
 WHERE `DATE` >= NOW() - INTERVAL 2 DAY AND DESCRIPTION LIKE '% FanVue'
 GROUP BY dia;

-- ---------- B4. Históricos copiados = remotos ----------
SELECT 'headcount_history' AS historico,
       (SELECT COUNT(*) FROM `5c_headcount`.`headcount_history`)     AS local,
       (SELECT COUNT(*) FROM `5c_headcount`.`fed_headcount_history`) AS remoto
UNION ALL
SELECT 'paginas_history',
       (SELECT COUNT(*) FROM `5c_pages`.`paginas_history`),
       (SELECT COUNT(*) FROM `5c_pages`.`fed_paginas_history`);

-- ---------- B5. Exemplos de uso ----------
-- Quem saiu do headcount (com data) e quem voltou:
SELECT h.changed_at, h.change_type, h.code,
       JSON_UNQUOTE(JSON_EXTRACT(h.raw_json, '$.name'))     AS nome,
       JSON_UNQUOTE(JSON_EXTRACT(h.raw_json, '$.position')) AS cargo
  FROM `5c_headcount`.`headcount_history` h
 WHERE h.change_type IN ('removed', 'returned')
 ORDER BY h.changed_at DESC
 LIMIT 20;

-- Mudança de cargo ou departamento (ignora o totalTime, que muda todo dia):
SELECT h.changed_at, h.code, h.changed_fields,
       JSON_UNQUOTE(JSON_EXTRACT(h.raw_json, '$.position'))    AS cargo,
       JSON_UNQUOTE(JSON_EXTRACT(h.raw_json, '$.departament')) AS departamento
  FROM `5c_headcount`.`headcount_history` h
 WHERE h.change_type = 'update'
   AND (h.changed_fields LIKE '%"position"%' OR h.changed_fields LIKE '%"departament"%')
 ORDER BY h.changed_at DESC
 LIMIT 20;

-- Páginas que entraram, saíram ou mudaram de tier/cliente:
SELECT p.changed_at, p.change_type, p.page_id,
       JSON_UNQUOTE(JSON_EXTRACT(p.raw_json, '$.name'))      AS pagina,
       JSON_UNQUOTE(JSON_EXTRACT(p.previous_json, '$.tier')) AS tier_antes,
       JSON_UNQUOTE(JSON_EXTRACT(p.raw_json, '$.tier'))      AS tier_depois,
       p.changed_fields
  FROM `5c_pages`.`paginas_history` p
 ORDER BY p.changed_at DESC
 LIMIT 20;

-- ---------- C1. Saúde (rodar depois de 20-30 min) ----------
-- Esperado: última execução de cada origem com status success há menos de
-- 10 min e pendentes = 0.
SELECT l.origem, l.status, l.iniciado_em, l.pendentes, l.mensagem
  FROM `5c_wfm_db`.`sync_novo_servidor_log` l
  JOIN (SELECT origem, MAX(id) AS id FROM `5c_wfm_db`.`sync_novo_servidor_log` GROUP BY origem) u
    ON u.id = l.id;

SELECT origem, status, COUNT(*) AS execucoes, SUM(novos) AS novos, MAX(iniciado_em) AS ultima
  FROM `5c_wfm_db`.`sync_novo_servidor_log`
 WHERE iniciado_em >= NOW() - INTERVAL 1 DAY
 GROUP BY origem, status;
