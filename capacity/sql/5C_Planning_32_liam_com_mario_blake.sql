SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_32_liam_com_mario_blake.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 (OM da U3): juntar Liam, Blake e Mario Adrion no mesmo posto, para os
-- male models ficarem juntos. A partir de 05/11:
--   - a Liam sai da U3C1 (posto 8, com a Bianca) e vai para a U3C2, no posto 3 (Mario Adrion e Blake);
--   - fc_pagina_carteira e fc_posto_fixo: fecha a linha antiga em 05/11 (vigente_ate exclusivo, como na
--     tela Saídas) e abre a nova em 05/11;
--   - recalcula os postos com a data de 05/11 (gera versão do Capacity).
-- Política: posto com 3 modelos diferentes (a regra geral junta até 2); vale porque o posto é fixo.
-- =====================================================================

USE `5c_planning`;

START TRANSACTION;

UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-05', obs = LEFT(CONCAT('Vai para a U3C2 com Mario Adrion e Blake (script 32); ', IFNULL(obs, '')), 255)
 WHERE pagina = 'Liam' AND carteira = 'U3C1' AND vigente_ate IS NULL;
INSERT INTO fc_pagina_carteira (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-05', NULL, 'U3C2', modelo, regra, ordem, fas_h_estimado, 'Male models juntos: com Mario Adrion e Blake (script 32, pedido do OM da U3)'
  FROM fc_pagina_carteira WHERE pagina = 'Liam' AND carteira = 'U3C1' AND vigente_ate = '2026-11-05'
   AND NOT EXISTS (SELECT 1 FROM fc_pagina_carteira WHERE pagina = 'Liam' AND vigente_desde = '2026-11-05');

UPDATE fc_posto_fixo SET vigente_ate = '2026-11-05' WHERE id = 289 AND pagina = 'Liam' AND carteira = 'U3C1' AND vigente_ate IS NULL;
INSERT INTO fc_posto_fixo (carteira, grupo, seq, pagina, pessoas, apoio, agencia, vigente_desde, obs)
SELECT 'U3C2', 3, 3, 'Liam', 1, 0, 'Verge', '2026-11-05', 'Male models juntos: Mario Adrion, Blake e Liam (script 32)'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM fc_posto_fixo WHERE pagina = 'Liam' AND carteira = 'U3C2' AND vigente_ate IS NULL);

COMMIT;

CALL sp_fc_recalcular_postos('2026-11-05', 'Liam no posto de Mario Adrion e Blake (script 32)');

SELECT pagina, carteira, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM fc_pagina_carteira WHERE pagina = 'Liam' ORDER BY vigente_desde;
SELECT carteira, grupo, seq, pagina, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM fc_posto_fixo WHERE pagina IN ('Liam', 'Mario Adrion', 'Blake', 'Bianca') ORDER BY carteira, grupo, seq, vigente_desde;
SELECT p.carteira, p.turno, p.posto, p.tipo, p.n_paginas, p.carga, p.capacidade, GROUP_CONCAT(pp.pagina ORDER BY pp.pagina SEPARATOR ' | ') AS paginas
  FROM fc_postos p JOIN fc_postos_pagina pp ON pp.data_calculo = p.data_calculo AND pp.carteira = p.carteira AND pp.turno = p.turno AND pp.posto = p.posto
 WHERE p.data_calculo = '2026-11-05' AND p.carteira IN ('U3C1', 'U3C2') AND (p.pagina_principal IN ('Mario Adrion', 'Bianca'))
 GROUP BY p.carteira, p.turno, p.posto, p.tipo, p.n_paginas, p.carga, p.capacidade ORDER BY p.carteira, p.turno;
SELECT carteira, turno, COUNT(*) AS postos FROM fc_postos WHERE data_calculo = '2026-11-05' AND carteira LIKE 'U3%' GROUP BY carteira, turno;
