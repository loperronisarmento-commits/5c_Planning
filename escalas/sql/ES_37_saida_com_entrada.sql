SET NAMES utf8mb4;
-- =====================================================================
-- ES_37 · Saída de página com outra página entrando no lugar (30/10/2026)
--
-- Na saída de uma página dá para dizer qual página entra no lugar (do Funil ou do
-- site da 5C) e a partir de quando. Quem perdeu a vaga fixa vai para a página nova,
-- nos mesmos núcleos. Nos dias entre a saída e a entrada, quem fica sem página vira
-- buffer (-2) se falta gente no turno, ou sai da escala nesses dias.
--   - esc_saida_pagina: página que entra e a data;
--   - esc_saida_sem_pagina: os dias sem página de cada SR e a decisão.
-- Roda no 5c_escalas e no 5c_escalas_treino (Modo Treino). Pode rodar de novo.
-- =====================================================================

USE `5c_escalas`;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_saida_pagina' AND COLUMN_NAME = 'entra_pagina');
SET @sql := IF(@tem = 0, 'ALTER TABLE esc_saida_pagina ADD COLUMN entra_pagina VARCHAR(150) NULL AFTER motivo, ADD COLUMN entra_desde DATE NULL AFTER entra_pagina', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS esc_saida_sem_pagina (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  saida_id  INT UNSIGNED NOT NULL,
  rep_code  VARCHAR(10)  NOT NULL,
  unidade   TINYINT      NOT NULL,
  turno     ENUM('A','C','D') NOT NULL,
  de        DATE         NOT NULL,
  ate       DATE         NOT NULL,
  decisao   ENUM('BUFFER','REMOVER') NOT NULL,
  motivo    VARCHAR(255) NULL,
  criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por INT         NULL,
  KEY ix_sem_pagina_rep (rep_code, de, ate),
  KEY ix_sem_pagina_saida (saida_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dias sem página depois de uma saída, até a página nova entrar (ES_37)';

-- ---------- Modo Treino ----------
USE `5c_escalas_treino`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_saida_pagina' AND COLUMN_NAME = 'entra_pagina');
SET @sql := IF(@tem = 0, 'ALTER TABLE esc_saida_pagina ADD COLUMN entra_pagina VARCHAR(150) NULL AFTER motivo, ADD COLUMN entra_desde DATE NULL AFTER entra_pagina', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
CREATE TABLE IF NOT EXISTS esc_saida_sem_pagina LIKE `5c_escalas`.esc_saida_sem_pagina;

USE `5c_escalas`;

-- ---------- Conferência ----------
SELECT 'esc_saida_pagina: entra_pagina e entra_desde (2)' AS item, COUNT(*) AS tem FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_saida_pagina' AND COLUMN_NAME IN ('entra_pagina', 'entra_desde')
UNION ALL SELECT 'esc_saida_sem_pagina (1)', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_saida_sem_pagina'
UNION ALL SELECT 'no Modo Treino (1)', COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_saida_sem_pagina';
