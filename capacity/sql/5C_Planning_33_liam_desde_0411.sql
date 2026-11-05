SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_33_liam_desde_0411.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Ajuste do script 32: o Gerar do 5C Escalas recalcula o Capacity com a data do dia em que se clica
-- (escEstruturaAtualizar) e sincroniza os postos da escala com essa versão. Para a Liam já sair no posto
-- de Mario Adrion e Blake se o Gerar for hoje (04/11), a troca passa a valer desde 04/11. Antes do corte
-- (05/11, turno C) a escala segue igual ao site, então não muda nada do que já foi postado.
-- =====================================================================

USE `5c_planning`;

START TRANSACTION;

UPDATE fc_pagina_carteira SET vigente_ate = '2026-11-04' WHERE pagina = 'Liam' AND carteira = 'U3C1' AND vigente_ate = '2026-11-05';
UPDATE fc_pagina_carteira SET vigente_desde = '2026-11-04' WHERE pagina = 'Liam' AND carteira = 'U3C2' AND vigente_desde = '2026-11-05';
UPDATE fc_posto_fixo SET vigente_ate = '2026-11-04' WHERE id = 289 AND pagina = 'Liam' AND carteira = 'U3C1' AND vigente_ate = '2026-11-05';
UPDATE fc_posto_fixo SET vigente_desde = '2026-11-04' WHERE pagina = 'Liam' AND carteira = 'U3C2' AND vigente_desde = '2026-11-05';

COMMIT;

CALL sp_fc_recalcular_postos('2026-11-04', 'Liam no posto de Mario Adrion e Blake desde 04/11 (script 33)');
CALL sp_fc_recalcular_postos('2026-11-05', 'Liam no posto de Mario Adrion e Blake (script 33)');

SELECT pagina, carteira, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM fc_pagina_carteira WHERE pagina = 'Liam' ORDER BY vigente_desde;
SELECT p.data_calculo, p.carteira, p.turno, p.posto, p.n_paginas, p.carga, GROUP_CONCAT(pp.pagina ORDER BY pp.pagina SEPARATOR ' | ') AS paginas
  FROM fc_postos p JOIN fc_postos_pagina pp ON pp.data_calculo = p.data_calculo AND pp.carteira = p.carteira AND pp.turno = p.turno AND pp.posto = p.posto
 WHERE p.data_calculo IN ('2026-11-04', '2026-11-05') AND p.carteira = 'U3C2' AND p.pagina_principal = 'Mario Adrion'
 GROUP BY p.data_calculo, p.carteira, p.turno, p.posto, p.n_paginas, p.carga ORDER BY p.data_calculo, p.turno;
