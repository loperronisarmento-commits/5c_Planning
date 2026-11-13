SET NAMES utf8mb4;
-- =====================================================================
-- ES_149 · Saída da Mikayla Free (12/11/2026 às 21h, aviso do usuário; aplicado pelo Claude via mysql.exe)
-- A Mikayla Paid continua na U1C1. Os mesmos passos da tela Saídas no Capacity:
--   1. fecha a carteira (U1C1) em 12/11 e o posto fixo dela (2 pessoas: titular + assistente fixo);
--   2. registra a saída em esc_saida_pagina (o Funil não a põe de volta sozinha por 60 dias);
--   3. recalcula o Capacity de hoje.
-- A escala muda no próximo Gerar das semanas de 16/11 e 23/11; a de 09/11 está travada pelos OM (turno D de
-- 12/11 em diante: os OM tiram a página).
-- =====================================================================

SELECT 'antes' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);

START TRANSACTION;
UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = '2026-11-12', obs = LEFT(CONCAT(IFNULL(obs, ''), ' · Saiu em 12/11/2026 às 21h (ES_149)'), 255)
 WHERE pagina = 'Mikayla Free' AND vigente_ate IS NULL;
UPDATE `5c_planning`.`fc_posto_fixo` SET vigente_ate = '2026-11-12', obs = 'ES_149: a página saiu'
 WHERE pagina = 'Mikayla Free' AND vigente_ate IS NULL;
INSERT INTO `5c_escalas`.`esc_saida_pagina` (pagina, vale_desde, evento_id, motivo, entra_pagina, entra_desde, status, capacity_antes, capacity_depois, resultado, erro, criado_por)
SELECT 'Mikayla Free', '2026-11-12', NULL, 'Modelo saiu às 21h de 12/11 (aviso do usuário); a Mikayla Paid continua', NULL, NULL, 'APLICADA', NULL, NULL,
       '{"origem": "ES_149", "obs": "Capacity fechado pelo script; escalas no próximo Gerar das semanas de 16/11 e 23/11"}', NULL, NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `5c_escalas`.`esc_saida_pagina` WHERE pagina = 'Mikayla Free' AND vale_desde = '2026-11-12');
COMMIT;

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Saída da Mikayla Free às 21h (ES_149)');

SELECT 'depois' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina LIKE 'Mikayla%';
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM `5c_planning`.`fc_capacity_versao_unidade` u
 WHERE u.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`) ORDER BY u.unidade, u.turno;
