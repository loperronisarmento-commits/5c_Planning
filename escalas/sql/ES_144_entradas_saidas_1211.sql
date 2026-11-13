SET NAMES utf8mb4;
-- =====================================================================
-- ES_144 · Entradas e saídas de 10 a 12/11 (12/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
--
-- Avisos de entradas e saídas conferidos contra o site (5c_pages.pages) e o Capacity (fc_pagina_carteira):
--   Janet VIP/Free, Livinia Roberts OFTV  saíram (09–10/11)  → já fechadas no Capacity (lote de 10/11)
--   Midwest Emma Free 221s, Yuni Yung     saíram              → já fechadas no Capacity em 11/11
--   Lily Cherry (tier E)                  entrou 10/11        → já na U3C2 desde 10/11
--   Liv Loxey (tier F, retorno)           voltou 10/11        → já na U3C1 desde 12/11 (Funil)
--   Isabel (tier F)                       entrou 11/11        → já na U3C1 desde 11/11 (entrou sozinha no Gerar); pedido: U3 ✓
--   Louise (tier D, retorno)              voltou 11/11        → entrou sozinha na U3C1; pedido: U1 → vai para a U1C2
--   Lais (tier F, Privacy)                entrou 11/11        → fora do Capacity; pedido: U2 → entra na U2C2, junto da
--                                                               Aline Novak (a outra Privacy da U2), no mesmo posto fixo
--   Sophia Lee (tier D, Elysium)          entra 12/11 21h     → ainda não está no site; a unidade fica para depois
-- A U1C2 e a U2C2 ficam com as duas páginas porque são as carteiras de destino com menos faturamento na unidade
-- (C1 ≈ 60%). A Ellie D fica sozinha no posto que dividia com a Aline (Privacy só com SR brasileiro).
-- Pode rodar de novo (as linhas só entram se faltarem).
-- =====================================================================

-- 0. Antes
SELECT 'antes' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira`
 WHERE pagina IN ('Louise', 'Lais', 'Isabel') ORDER BY pagina, vigente_desde;
SELECT 'antes' AS momento, carteira, grupo, seq, pagina FROM `5c_planning`.`fc_posto_fixo`
 WHERE vigente_ate IS NULL AND pagina IN ('Ellie D', 'Aline Novak', 'Lais');

START TRANSACTION;

-- 1. Louise: U3C1 → U1C2 a partir de 12/11
INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-12', NULL, 'U1C2', modelo, regra, ordem, fas_h_estimado,
       'Voltou em 11/11/2026 (tier D, origem Noah): pedido do usuário, U1 (antes entrou sozinha na U3C1) (ES_144)'
  FROM `5c_planning`.`fc_pagina_carteira`
 WHERE pagina = 'Louise' AND carteira = 'U3C1' AND vigente_ate IS NULL;
UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = '2026-11-12'
 WHERE pagina = 'Louise' AND carteira = 'U3C1' AND vigente_ate IS NULL;

-- 2. Lais (Privacy): entra na U2C2 a partir de 12/11, com o volume do arquétipo F MAIN (o mesmo da Isabel)
INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT 'Lais', '2026-11-12', NULL, 'U2C2', 'lais', 'PADRAO', 999, 6.89,
       'Entrou em 11/11/2026 (tier F, Privacy, origem Independent): pedido do usuário, U2, posto com a Aline Novak (ES_144)'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Lais' AND vigente_ate IS NULL);

-- 3. Posto fixo: Aline Novak + Lais (as duas Privacy da U2); a Ellie D fica sozinha
UPDATE `5c_planning`.`fc_posto_fixo` SET grupo = 10, seq = 1, obs = 'ES_144: posto da Privacy com a Lais (antes com a Ellie D)'
 WHERE carteira = 'U2C2' AND pagina = 'Aline Novak' AND vigente_ate IS NULL;
INSERT INTO `5c_planning`.`fc_posto_fixo` (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs)
SELECT 'U2C2', 10, 2, 'Lais', 1, 0, NULL, '2026-11-12', NULL, 'ES_144: entrou em 11/11, posto da Privacy com a Aline Novak'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_posto_fixo` WHERE pagina = 'Lais' AND vigente_ate IS NULL);

-- 4. Registro no Funil de entrada
INSERT INTO `5c_escalas`.`esc_pagina_entrada_checagem` (page_id, pagina, ativa_desde, tipo, decisao, carteira, vale_desde, nucleo_numero, observacao, decidido_por) VALUES
 (485, 'Louise', '2026-11-11', 'RETORNO', 'ENTROU', 'U1C2', '2026-11-12', NULL, 'Pedido do usuário em 12/11: U1 (tinha entrado sozinha na U3C1) (ES_144)', NULL),
 (499, 'Lais',   '2026-11-11', 'NOVA',    'ENTROU', 'U2C2', '2026-11-12', NULL, 'Pedido do usuário em 12/11: U2, Privacy, posto com a Aline Novak (ES_144)', NULL),
 (498, 'Isabel', '2026-11-11', 'NOVA',    'ENTROU', 'U3C1', '2026-11-11', NULL, 'Pedido do usuário em 12/11: U3 (já tinha entrado sozinha na U3C1) (ES_144)', NULL) AS n
ON DUPLICATE KEY UPDATE decisao = n.decisao, carteira = n.carteira, vale_desde = n.vale_desde, observacao = n.observacao;

COMMIT;

-- 5. Capacity de hoje
CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Entradas e saídas de 10 a 12/11: Louise na U1C2, Lais na U2C2 com a Aline Novak (ES_144)');

-- 6. Depois
SELECT 'depois' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira`
 WHERE pagina IN ('Louise', 'Lais', 'Isabel') ORDER BY pagina, vigente_desde;
SELECT 'depois' AS momento, carteira, grupo, seq, pagina FROM `5c_planning`.`fc_posto_fixo`
 WHERE vigente_ate IS NULL AND pagina IN ('Ellie D', 'Aline Novak', 'Lais');
SELECT 'depois' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.precisa, v.tem, v.saldo, v.buffer
  FROM `5c_planning`.`fc_capacity_versao` v WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM `5c_planning`.`fc_capacity_versao_unidade` u
 WHERE u.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`) ORDER BY u.unidade, u.turno;
