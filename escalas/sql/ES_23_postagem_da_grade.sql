-- =====================================================================
-- ES_23_postagem_da_grade.sql  (29/10)
-- Controle de postagem da grade: para cada versão publicada e página, quando
-- o intervalo foi apagado e quando o CSV foi postado no site (Schedule Ajustment).
-- A tela Grade da escala mostra um cartão por página com o CSV para copiar,
-- "1 · Apaguei" e "2 · Postei", como na troca de páginas. Com o CSV guardado,
-- a versão seguinte mostra quais páginas mudaram e quais podem ficar como estão.
-- Só cria a tabela. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_plano_postagem (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id     BIGINT UNSIGNED NOT NULL,
  page_id      INT          NOT NULL,
  pagina       VARCHAR(150) NOT NULL,
  semana       DATE         NOT NULL COMMENT 'segunda-feira da semana do CSV',
  csv          MEDIUMTEXT   NOT NULL,
  csv_hash     CHAR(64)     NOT NULL,
  apagado_em   DATETIME     NULL,
  apagado_por  INT          NULL,
  postado_em   DATETIME     NULL,
  postado_por  INT          NULL,
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_plano_postagem (plano_id, page_id),
  KEY ix_plano_postagem_semana (semana, page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Controle de apagar/postar o CSV da grade por página';

-- Conferência
SELECT COUNT(*) AS tabela_criada, '1' AS esperado FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_postagem';
