-- =====================================================================
-- ES_45_folga_juntos.sql  (31/10)
-- Folga juntos (casais): pares de pessoas que precisam folgar nos mesmos dias
-- (tela Pessoas › Folga juntos). Duas pessoas folgam juntas quando estão no mesmo
-- ponto do ciclo de 21 dias (mesmo deslocamento: núcleo de mesmo número, ou o
-- deslocamento do OC/OA). A tela mostra se cada par já folga junto e propõe o
-- ajuste (SR muda de núcleo, OC/OA troca o deslocamento com outro do turno, OS
-- troca de núcleo com outro OS); o motor dá aos SRs do par o mesmo dia de Overlap.
--   1. esc_folga_par (também no 5c_escalas_treino, se existir);
--   2. os 3 pares já conhecidos:
--      Rafael Gauze Pereira dos Reis (R096272, OC) e Joao Paulo Caial Pires (J110041, OS);
--      Marcos Vinicius Cid Mélo (M137535, SR) e Rafael Garcia da Costa (R169247, SR);
--      Matheus Luz Boaventura (M116898, SR) e Iris Castro Moreira (I131039, SR).
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

SET @ddl := 'CREATE TABLE IF NOT EXISTS `{schema}`.`esc_folga_par` (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rep_a          VARCHAR(20) NOT NULL,
  rep_b          VARCHAR(20) NOT NULL,
  observacao     VARCHAR(255) NULL,
  vigente_desde  DATE NOT NULL,
  vigente_ate    DATE NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por     INT NULL,
  PRIMARY KEY (id),
  KEY idx_rep_a (rep_a),
  KEY idx_rep_b (rep_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Pares que folgam juntos (casais), tela Folga juntos (ES_45)''';

SET @sql := REPLACE(@ddl, '{schema}', '5c_escalas'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @treino := (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '5c_escalas_treino');
SET @sql := IF(@treino = 1, REPLACE(@ddl, '{schema}', '5c_escalas_treino'), 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO esc_folga_par (rep_a, rep_b, observacao, vigente_desde)
SELECT x.a, x.b, 'Casal (pedido de 31/10)', '2026-11-01'
  FROM (SELECT 'R096272' AS a, 'J110041' AS b UNION ALL SELECT 'M137535', 'R169247' UNION ALL SELECT 'M116898', 'I131039') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_folga_par p
                    WHERE p.vigente_ate IS NULL
                      AND p.rep_a IN (x.a COLLATE utf8mb4_unicode_ci, x.b COLLATE utf8mb4_unicode_ci)
                      AND p.rep_b IN (x.a COLLATE utf8mb4_unicode_ci, x.b COLLATE utf8mb4_unicode_ci));

-- Conferência
SELECT id, rep_a, rep_b, observacao, vigente_desde, vigente_ate FROM esc_folga_par ORDER BY id;
SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME = 'esc_folga_par' ORDER BY 1;
