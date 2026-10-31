-- =====================================================================
-- ES_27_funil_destino_nucleo.sql  (30/10)
-- Funil de entrada na estrutura nova: o destino de uma página prevista passa
-- a ser unidade · turno (· núcleo), em vez de time e party. As colunas antigas
-- (destino_time, destino_party) ficam para o histórico.
-- Sem este script a tela continua funcionando, com o destino por party.
-- Pode rodar de novo (só cria a coluna que ainda não existe).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_pagina_previsao' AND COLUMN_NAME = 'destino_unidade');
SET @sql := IF(@tem = 0, 'ALTER TABLE esc_pagina_previsao ADD COLUMN destino_unidade TINYINT UNSIGNED NULL COMMENT ''unidade de destino (1 a 3)'' AFTER destino_party', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_pagina_previsao' AND COLUMN_NAME = 'destino_turno');
SET @sql := IF(@tem = 0, 'ALTER TABLE esc_pagina_previsao ADD COLUMN destino_turno CHAR(1) NULL COMMENT ''turno de destino: A, C ou D'' AFTER destino_unidade', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_pagina_previsao' AND COLUMN_NAME = 'destino_nucleo_id');
SET @sql := IF(@tem = 0, 'ALTER TABLE esc_pagina_previsao ADD COLUMN destino_nucleo_id INT UNSIGNED NULL COMMENT ''núcleo de destino (esc_nucleo.id), opcional'' AFTER destino_turno', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_pagina_previsao' AND COLUMN_NAME LIKE 'destino%'
 ORDER BY ORDINAL_POSITION;
