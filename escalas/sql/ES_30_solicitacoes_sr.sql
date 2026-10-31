-- =====================================================================
-- ES_30_solicitacoes_sr.sql  (30/10)
-- Solicitações do SR (tela Pessoas › Solicitações): o líder registra o pedido de
-- trabalhar como assistente, em dupla, mudar de turno, de núcleo ou de unidade (ou
-- outro); o Planejamento aprova ou recusa e, quando aprova, aplica uma troca 1 por 1
-- com outro SR (a pessoa vai para o núcleo do par e assume as vagas fixas dele, e
-- vice-versa), a partir de uma data.
-- Cria a tabela no 5c_escalas e, se o Modo Treino estiver instalado (ES_28), no
-- 5c_escalas_treino. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;

SET @ddl := 'CREATE TABLE IF NOT EXISTS `{schema}`.`esc_solicitacao` (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rep_code             VARCHAR(30)  NOT NULL COMMENT ''SR que pede'',
  tipo                 ENUM(''ASSISTENTE'',''DUPLA'',''TURNO'',''NUCLEO'',''UNIDADE'',''OUTRO'') NOT NULL,
  destino_unidade      TINYINT UNSIGNED NULL COMMENT ''unidade desejada (TURNO, NUCLEO, UNIDADE)'',
  destino_turno        CHAR(1)      NULL COMMENT ''turno desejado: A, C ou D'',
  destino_nucleo_id    INT UNSIGNED NULL COMMENT ''núcleo desejado (esc_nucleo.id)'',
  a_partir             DATE         NULL COMMENT ''a partir de quando a pessoa quer'',
  texto                VARCHAR(500) NULL COMMENT ''o pedido nas palavras do líder'',
  canal                ENUM(''LIDER'',''PLANEJAMENTO'',''FORMULARIO'') NOT NULL DEFAULT ''LIDER'',
  status               ENUM(''PENDENTE'',''APROVADO'',''RECUSADO'',''CANCELADO'',''APLICADO'') NOT NULL DEFAULT ''PENDENTE'',
  solicitado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  solicitado_por       INT          NULL,
  solicitado_por_nome  VARCHAR(120) NULL COMMENT ''quem registrou (nome no portal)'',
  decidido_em          DATETIME     NULL,
  decidido_por         INT          NULL,
  comentario_decisao   VARCHAR(500) NULL,
  troca_com            VARCHAR(30)  NULL COMMENT ''par da troca 1 por 1 (rep_code)'',
  troca_desde          DATE         NULL COMMENT ''a troca vale desta data'',
  aplicado_em          DATETIME     NULL,
  aplicado_por         INT          NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_rep (rep_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Solicitações do SR: assistente, dupla, turno, núcleo, unidade (ES_30)''';

SET @sql := REPLACE(@ddl, '{schema}', '5c_escalas');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @treino := (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '5c_escalas_treino');
SET @sql := IF(@treino = 1, REPLACE(@ddl, '{schema}', '5c_escalas_treino'), 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência
SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_COMMENT
  FROM information_schema.TABLES
 WHERE TABLE_NAME = 'esc_solicitacao' AND TABLE_SCHEMA IN ('5c_escalas', '5c_escalas_treino');
