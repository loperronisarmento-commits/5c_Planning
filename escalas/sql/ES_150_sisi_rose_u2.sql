SET NAMES utf8mb4;
-- =====================================================================
-- ES_150 · Sisi Rose da U1C2 para a U2C1 (13/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
-- Decisão de 13/11: a Sisi vai para a U2 e não há outras movimentações de página.
--   1. Carteira: U1C2 fecha em 13/11 e abre U2C1 em 13/11 (a U2C1 tem menos postos que a U2C2: 8 × 10).
--   2. Posto fixo: continua exclusiva, com 2 pessoas (titular + assistente fixo), num grupo novo da U2C1.
--   3. Sem equipe fixa, divisão 50/50 ou assistente cadastrados para ela (nada a fechar).
--   4. Recalcula o Capacity de hoje. A escala muda no próximo Gerar (semanas de 16/11 e 23/11).
-- =====================================================================

SELECT 'antes' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);

START TRANSACTION;
INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-13', NULL, 'U2C1', modelo, regra, ordem, fas_h_estimado, 'Mudou da U1C2 para a U2C1 em 13/11/2026: pedido do usuário (ES_150)'
  FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Sisi Rose' AND carteira = 'U1C2' AND vigente_ate IS NULL;
UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = '2026-11-13' WHERE pagina = 'Sisi Rose' AND carteira = 'U1C2' AND vigente_ate IS NULL;

INSERT INTO `5c_planning`.`fc_posto_fixo` (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs)
SELECT 'U2C1', (SELECT COALESCE(MAX(f.grupo), 0) + 1 FROM `5c_planning`.`fc_posto_fixo` f WHERE f.carteira = 'U2C1' AND f.vigente_ate IS NULL),
       1, 'Sisi Rose', 2, 0, agencia, '2026-11-13', NULL, 'ES_150: veio da U1C2, exclusiva com assistente fixo'
  FROM `5c_planning`.`fc_posto_fixo` WHERE id = 329 AND vigente_ate IS NULL;
UPDATE `5c_planning`.`fc_posto_fixo` SET vigente_ate = '2026-11-13', obs = 'ES_150: foi para a U2C1' WHERE id = 329 AND vigente_ate IS NULL;
COMMIT;

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Sisi Rose da U1C2 para a U2C1 (ES_150)');

SELECT 'depois' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Sisi Rose';
SELECT 'depois' AS momento, carteira, grupo, pessoas, vigente_desde, vigente_ate FROM `5c_planning`.`fc_posto_fixo` WHERE pagina = 'Sisi Rose';
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM `5c_planning`.`fc_capacity_versao_unidade` u
 WHERE u.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`) ORDER BY u.unidade, u.turno;
