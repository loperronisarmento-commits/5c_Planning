SET NAMES utf8mb4;
-- =====================================================================
-- ES_146 · Saída da Taylor Ballard (12/11/2026, aviso do usuário; aplicado pelo Claude via mysql.exe)
-- A página saiu (ainda ativa no site em 12/11). Os mesmos passos da tela Saídas no Capacity:
--   1. fecha a carteira (U1C1) em 12/11 e o posto fixo dela (estava sozinha desde o script 40);
--   2. registra a saída em esc_saida_pagina (o Funil não a põe de volta sozinha por 60 dias);
--   3. recalcula o Capacity de hoje.
-- As escalas mudam no próximo Gerar das semanas de 16/11 e 23/11; a de 09/11 está travada pelos OM.
-- =====================================================================

SELECT 'antes' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);

START TRANSACTION;
UPDATE `5c_planning`.`fc_pagina_carteira` SET vigente_ate = '2026-11-12', obs = LEFT(CONCAT(IFNULL(obs, ''), ' · Saiu em 12/11/2026: modelo saiu (ES_146)'), 255)
 WHERE pagina = 'Taylor Ballard' AND vigente_ate IS NULL;
UPDATE `5c_planning`.`fc_posto_fixo` SET vigente_ate = '2026-11-12', obs = 'ES_146: a página saiu'
 WHERE pagina = 'Taylor Ballard' AND vigente_ate IS NULL;
INSERT INTO `5c_escalas`.`esc_saida_pagina` (pagina, vale_desde, evento_id, motivo, entra_pagina, entra_desde, status, capacity_antes, capacity_depois, resultado, erro, criado_por)
SELECT 'Taylor Ballard', '2026-11-12', NULL, 'Modelo saiu (aviso do usuário em 12/11)', NULL, NULL, 'APLICADA', NULL, NULL,
       '{"origem": "ES_146", "obs": "Capacity fechado pelo script; escalas no próximo Gerar das semanas de 16/11 e 23/11"}', NULL, NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `5c_escalas`.`esc_saida_pagina` WHERE pagina = 'Taylor Ballard');
COMMIT;

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'Saída da Taylor Ballard (ES_146)');

SELECT 'depois' AS momento, pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Taylor Ballard';
SELECT 'depois' AS momento, v.versao, v.precisa, v.tem, v.saldo FROM `5c_planning`.`fc_capacity_versao` v
 WHERE v.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`);
SELECT 'depois' AS momento, u.unidade, u.turno, u.precisa, u.tem, u.saldo FROM `5c_planning`.`fc_capacity_versao_unidade` u
 WHERE u.versao = (SELECT MAX(versao) FROM `5c_planning`.`fc_capacity_versao`) ORDER BY u.unidade, u.turno;
