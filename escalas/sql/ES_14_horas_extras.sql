-- =====================================================================
-- ES_14_horas_extras.sql  (revisão de 28/10)
-- Tela "Horas extras": lista os postos sem ninguém e os buffers que faltam
-- (necessidade de buffer do Capacity × buffers de plantão escalados), registra
-- quem quer fazer hora extra em cada vaga e aloca na escala (rascunho).
-- O buffer de plantão agora vai para a página 5C Schedule com "-2" no CSV
-- (o Overlap continua com "-5"); não precisa de mudança no banco para isso.
-- Só cria uma tabela nova. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_hora_extra (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  semana_inicio DATE         NOT NULL COMMENT 'segunda-feira da semana do plano',
  dia           DATE         NOT NULL COMMENT 'dia do turno (o T1 do turno D é do dia seguinte)',
  turno         ENUM('A','C','D') NOT NULL,
  unidade       TINYINT UNSIGNED NULL,
  tipo          ENUM('POSTO','BUFFER') NOT NULL,
  posto_id      INT UNSIGNED NULL COMMENT 'esc_posto (tipo POSTO)',
  pagina        VARCHAR(150) NULL COMMENT 'página principal do posto',
  rep_code      VARCHAR(30)  NOT NULL,
  status        ENUM('INTERESSADO','ALOCADO','RECUSADO','CANCELADO') NOT NULL DEFAULT 'INTERESSADO',
  observacao    VARCHAR(255) NULL,
  plano_id      BIGINT UNSIGNED NULL COMMENT 'versão em que foi alocado',
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por    INT          NULL,
  decidido_em   DATETIME     NULL,
  decidido_por  INT          NULL,
  PRIMARY KEY (id),
  KEY ix_he_semana (semana_inicio, dia, turno),
  KEY ix_he_rep (rep_code, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pedidos de hora extra para cobrir postos e buffers vazios';

-- Conferência
SELECT COUNT(*) AS linhas FROM esc_hora_extra;
