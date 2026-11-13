SET NAMES utf8mb4;
-- =====================================================================
-- ES_152 · Postos fixos da U1 iguais à composição do usuário (13/11/2026, pedido do usuário; via mysql.exe)
-- Base: a lista "hoje está assim" de 13/11, com as mudanças pedidas no mesmo dia:
--   - a Sisi Rose já foi para a U2C1 (ES_150) e não entra;
--   - troca Thalia ↔ Coco: a Thalia (com a 147t) vai para o posto da Charlie na U1C1 e a Coco vai para o posto da
--     Jena Wolfy e da Mrs Robinson na U1C2 (ES_151 já mudou as carteiras);
--   - a Rebecca (com a Rebecca OFTV) não estava na lista, mas segue ativa no site: fica num posto próprio na U1C2.
-- Resultado: U1C1 com 7 postos por turno e U1C2 com 10 (eram 7 e 9).
-- As linhas de 13/11 do ES_151 são apagadas e as anteriores fecham em 13/11; a lista nova vale de 13/11.
-- =====================================================================

USE `5c_planning`;

SELECT 'antes' AS momento, f.carteira, f.grupo, MAX(f.pessoas) AS pessoas, GROUP_CONCAT(f.pagina ORDER BY f.seq SEPARATOR ' + ') AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' GROUP BY f.carteira, f.grupo ORDER BY f.carteira, f.grupo;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_es152;
CREATE TEMPORARY TABLE tmp_es152 (carteira VARCHAR(8), grupo SMALLINT, seq TINYINT, pagina VARCHAR(150), pessoas TINYINT, apoio TINYINT)
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT INTO tmp_es152 VALUES
 ('U1C1', 1, 1, 'Mikayla Paid', 1, 0),
 ('U1C1', 2, 1, 'Rocket Reyna VIP', 2, 0), ('U1C1', 2, 2, 'Rocket Reyna OFTV', 2, 0),
 ('U1C1', 3, 1, 'Levi Paid', 1, 0), ('U1C1', 3, 2, 'Levi Free', 1, 0), ('U1C1', 3, 3, 'Sumner', 1, 0),
 ('U1C1', 4, 1, 'Silvia Saige VIP', 1, 0), ('U1C1', 4, 2, 'Silvia Saige Free', 1, 0),
 ('U1C1', 5, 1, 'Diana Rider', 1, 0), ('U1C1', 5, 2, 'zOFTV Diana Rider', 1, 1),
 ('U1C1', 6, 1, 'Thalia 147m', 1, 0), ('U1C1', 6, 2, 'Charlie 306m', 1, 0), ('U1C1', 6, 3, 'Thalia 147t', 1, 1),
 ('U1C2', 1, 1, 'Holly Jane', 1, 0),
 ('U1C2', 2, 1, 'Louise', 1, 0),
 ('U1C2', 3, 1, 'Natasha Nice VIP', 1, 0),
 ('U1C2', 4, 1, 'Jena Wolfy VIP 001m', 1, 0), ('U1C2', 4, 2, 'Mrs Robinson 303m', 1, 0), ('U1C2', 4, 3, 'Jena Wolfy Free 001s', 1, 0),
 ('U1C2', 4, 4, 'Coco Bunnie 260m', 1, 0), ('U1C2', 4, 5, 'Jena Wolfy OFTV 001t', 1, 1),
 ('U1C2', 5, 1, 'Tiff 176m', 1, 0),
 ('U1C2', 6, 1, 'Charly', 1, 0),
 ('U1C2', 7, 1, 'Krystal 141m', 1, 0), ('U1C2', 7, 2, 'Krystal 141t', 1, 1),
 ('U1C2', 8, 1, 'Angel', 1, 0),
 ('U1C2', 9, 1, 'Maya', 1, 0), ('U1C2', 9, 2, 'Blaire', 1, 0), ('U1C2', 9, 3, 'Dakota', 1, 0),
 ('U1C2', 10, 1, 'Rebecca', 1, 0), ('U1C2', 10, 2, 'Rebecca OFTV', 1, 1);

-- agência de cada página (a última gravada)
DROP TEMPORARY TABLE IF EXISTS tmp_es152_ag;
CREATE TEMPORARY TABLE tmp_es152_ag AS
SELECT pagina, MAX(agencia) AS agencia FROM fc_posto_fixo WHERE agencia IS NOT NULL GROUP BY pagina;

DELETE FROM fc_posto_fixo WHERE carteira LIKE 'U1%' AND vigente_desde = '2026-11-13' AND vigente_ate IS NULL;
UPDATE fc_posto_fixo SET vigente_ate = '2026-11-13', obs = LEFT(CONCAT('ES_152: U1 igual à lista do usuário; ', IFNULL(obs, '')), 255)
 WHERE carteira LIKE 'U1%' AND vigente_ate IS NULL;
INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, vigente_ate, obs)
SELECT t.carteira, t.grupo, t.seq, t.pagina, t.pessoas, t.apoio, a.agencia, '2026-11-13', NULL, 'ES_152: composição do usuário de 13/11'
  FROM tmp_es152 t LEFT JOIN tmp_es152_ag a ON a.pagina = t.pagina;

COMMIT;

CALL sp_fc_recalcular_postos(CURDATE(), 'U1: postos fixos iguais à composição do usuário (ES_152)');

SELECT 'depois' AS momento, f.carteira, f.grupo, MAX(f.pessoas) AS pessoas, GROUP_CONCAT(CONCAT(f.pagina, IF(f.apoio = 1, ' [apoio]', '')) ORDER BY f.seq SEPARATOR ' + ') AS paginas
  FROM fc_posto_fixo f WHERE f.vigente_ate IS NULL AND f.carteira LIKE 'U1%' GROUP BY f.carteira, f.grupo ORDER BY f.carteira, f.grupo;
SELECT 'fora do fixo (U1)' AS momento, c.carteira, c.pagina FROM fc_pagina_carga c
 WHERE c.data_calculo = CURDATE() AND c.carteira LIKE 'U1%' AND c.ocupa_posto = 1
   AND NOT EXISTS (SELECT 1 FROM fc_posto_fixo x WHERE x.pagina = c.pagina AND x.carteira = c.carteira AND x.vigente_ate IS NULL)
 GROUP BY c.carteira, c.pagina;
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM fc_capacity_versao_unidade u
 WHERE u.versao = (SELECT MAX(versao) FROM fc_capacity_versao) ORDER BY u.unidade, u.turno;
