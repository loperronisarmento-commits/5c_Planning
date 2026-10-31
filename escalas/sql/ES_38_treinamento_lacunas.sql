SET NAMES utf8mb4;
-- =====================================================================
-- ES_38 · Treinamento: página de lacuna · Horas extras por bloco de 4 h (30/10/2026)
--
-- A página do treinamento pode ser:
--   - EMPRESTADA: a menor de um posto com 2 ou mais páginas; o dono fica com ela
--     (e a comissão) e o treinamento usa a página;
--   - LACUNA: página que estava sem ninguém no turno. O treinamento cobre a página
--     nos dias e no turno da turma, e o gerador não escala ninguém nela (quem iria
--     para lá cobre outras páginas). Por isso o treinamento vem antes de gerar.
-- Roda no 5c_escalas e no 5c_escalas_treino. Pode rodar de novo.
-- =====================================================================

USE `5c_escalas`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_treinamento_pagina' AND COLUMN_NAME = 'modo');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_treinamento_pagina ADD COLUMN modo ENUM('EMPRESTADA','LACUNA') NOT NULL DEFAULT 'EMPRESTADA' AFTER pagina, ADD COLUMN lacunas SMALLINT NULL COMMENT 'blocos sem ninguém no turno quando foi escolhida' AFTER ating", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

USE `5c_escalas_treino`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_treinamento_pagina' AND COLUMN_NAME = 'modo');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_treinamento_pagina ADD COLUMN modo ENUM('EMPRESTADA','LACUNA') NOT NULL DEFAULT 'EMPRESTADA' AFTER pagina, ADD COLUMN lacunas SMALLINT NULL AFTER ating", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- Horas extras por bloco de 4 h (T1 a T6) ----------
-- A regra é no máximo 12 h seguidas: com turno de 8 h, a hora extra tem de poder ser
-- só de um bloco (ex.: quem faz T2/T3 pega o T4). bloco NULL = o turno inteiro (pedidos antigos).
USE `5c_escalas`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_hora_extra' AND COLUMN_NAME = 'bloco');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_hora_extra ADD COLUMN bloco ENUM('T1','T2','T3','T4','T5','T6') NULL COMMENT 'bloco de 4 h; NULL = turno inteiro' AFTER turno", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
USE `5c_escalas_treino`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_hora_extra' AND COLUMN_NAME = 'bloco');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_hora_extra ADD COLUMN bloco ENUM('T1','T2','T3','T4','T5','T6') NULL AFTER turno", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

USE `5c_escalas`;
SELECT 'esc_hora_extra.bloco (1)' AS item, COUNT(*) AS tem FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_hora_extra' AND COLUMN_NAME = 'bloco'
UNION ALL SELECT 'modo e lacunas no 5c_escalas (2)' AS item, COUNT(*) AS tem FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_treinamento_pagina' AND COLUMN_NAME IN ('modo', 'lacunas')
UNION ALL SELECT 'modo e lacunas no Modo Treino (2)', COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_treinamento_pagina' AND COLUMN_NAME IN ('modo', 'lacunas');
