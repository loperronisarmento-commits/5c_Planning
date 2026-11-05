SET NAMES utf8mb4;
-- =====================================================================
-- ES_115_versao_documentos.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11: versão em todos os arquivos (escalas, organogramas, mérito etc.).
-- A escala das páginas já tem a versão do plano (esc_plano.versao/subversao). Para os outros documentos
-- (organograma, escala da liderança, mérito, regras, owners, restrições dos OM), esta tabela guarda a
-- versão de cada conteúdo: o número só sobe quando o conteúdo muda (lib/documentos.php).
-- =====================================================================

USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_documento_versao (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo        VARCHAR(40)  NOT NULL COMMENT 'organograma, lideranca, merito, regras, owners, restricoes',
  escopo      VARCHAR(160) NOT NULL DEFAULT '' COMMENT 'ex.: U1, pagina:Cynthia, semana:2026-11-09',
  versao      INT UNSIGNED NOT NULL,
  assinatura  CHAR(40)     NOT NULL COMMENT 'sha1 do conteúdo sem data e hora de geração',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por  INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_doc_versao (tipo, escopo, versao),
  KEY ix_doc_assinatura (tipo, escopo, assinatura)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Versão dos documentos gerados (ES_115)';

SELECT COUNT(*) AS linhas FROM esc_documento_versao;
