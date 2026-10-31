-- =====================================================================
-- ES_33_politica_paginas.sql  (30/10)
-- Política de páginas por mérito (escalas/Politica_paginas_merito_2026-10-30.md):
--   1. parâmetros (grupo 'politica'), ajustáveis em Parâmetros;
--   2. esc_pagina_prioridade: prioridades fixas de página (vencem a nota). Já entra a
--      Privacy (Andressa Urach e Aline Novak) para o time legado Felix Legion III:
--      Marcos Vinicius Cid Mélo (M137535), Loik Costa da Silva (L052002) e Melody
--      Llavores Vale (M203717);
--   3. esc_politica_troca: trocas sugeridas, aplicadas e descartadas (de owner e de
--      unidade), para a carência e o limite por semana.
-- Cria as tabelas também no 5c_escalas_treino, se existir. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('pol_piso',              '0.50', 'DECIMAL', 'politica', 'Atingimento igual ou abaixo disto: prazo mínimo para perder a página (0,50 = 50% da meta)'),
  ('pol_corte_acomp',       '0.85', 'DECIMAL', 'politica', 'Abaixo disto o prazo corre; entre isto e a meta, só acompanhamento'),
  ('pol_prazo_min',         '3',    'INT',     'politica', 'Prazo (dias) de quem vai extremamente mal'),
  ('pol_prazo_max',         '14',   'INT',     'politica', 'Prazo (dias) de quem está logo abaixo do corte'),
  ('pol_gamma',             '2',    'DECIMAL', 'politica', 'Curva do prazo (retorno decrescente): maior que 1 = curto embaixo e cresce perto do corte; menor que 1 = mais tolerante'),
  ('pol_prazo_acomp',       '28',   'INT',     'politica', 'Dias de acompanhamento entre o corte e a meta antes de sugerir troca'),
  ('pol_destaque',          '1.10', 'DECIMAL', 'politica', 'Atingimento de destaque (candidata a página maior)'),
  ('pol_destaque_dias',     '14',   'INT',     'politica', 'Dias seguidos no destaque para virar candidata'),
  ('pol_carencia',          '7',    'INT',     'politica', 'Dias sem reavaliar depois de uma troca (estabilidade)'),
  ('pol_owner_min',         '14',   'INT',     'politica', 'Dias mínimos do owner na página antes de trocar o owner'),
  ('pol_max_trocas_semana', '2',    'INT',     'politica', 'Páginas que uma unidade perde ou recebe por semana, no máximo'),
  ('pol_janela',            '28',   'INT',     'politica', 'Janela de avaliação (dias)'),
  ('pol_dias_minimos',      '3',    'INT',     'politica', 'Dias com dados para avaliar')
AS n ON DUPLICATE KEY UPDATE descricao = n.descricao, grupo = n.grupo;

SET @ddl_prio := 'CREATE TABLE IF NOT EXISTS `{schema}`.`esc_pagina_prioridade` (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pagina         VARCHAR(150) NOT NULL COMMENT ''página (nome exato)'',
  rep_codes      VARCHAR(255) NOT NULL COMMENT ''quem tem prioridade, separados por vírgula'',
  motivo         VARCHAR(255) NULL,
  vigente_desde  DATE NOT NULL,
  vigente_ate    DATE NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pagina (pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Prioridades fixas de página: vencem a nota da política (ES_33)''';

SET @ddl_troca := 'CREATE TABLE IF NOT EXISTS `{schema}`.`esc_politica_troca` (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo           ENUM(''OWNER'',''UNIDADE'',''DEFINIR_OWNER'') NOT NULL,
  pagina         VARCHAR(150) NOT NULL,
  turno          CHAR(1) NULL,
  de_ref         VARCHAR(30) NULL COMMENT ''owner que sai (rep) ou unidade de origem'',
  para_ref       VARCHAR(30) NULL COMMENT ''owner que entra (rep) ou unidade de destino'',
  atingimento    DECIMAL(6,3) NULL,
  status         ENUM(''APLICADA'',''DESCARTADA'') NOT NULL,
  vale_desde     DATE NULL,
  motivo         VARCHAR(255) NULL,
  resultado      JSON NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por     INT NULL,
  PRIMARY KEY (id),
  KEY idx_pagina (pagina, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Trocas da política de páginas (ES_33)''';

SET @sql := REPLACE(@ddl_prio, '{schema}', '5c_escalas'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := REPLACE(@ddl_troca, '{schema}', '5c_escalas'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @treino := (SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '5c_escalas_treino');
SET @sql := IF(@treino = 1, REPLACE(@ddl_prio, '{schema}', '5c_escalas_treino'), 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@treino = 1, REPLACE(@ddl_troca, '{schema}', '5c_escalas_treino'), 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde)
SELECT x.pagina, 'M137535,L052002,M203717', 'Privacy: time legado Felix Legion III (Marcos Vinicius Cid Mélo)', '2026-11-01'
  FROM (SELECT 'Andressa Urach' AS pagina UNION ALL SELECT 'Aline Novak') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina COLLATE utf8mb4_unicode_ci AND p.vigente_ate IS NULL);

-- Conferência
SELECT chave, valor FROM esc_parametro WHERE grupo = 'politica' ORDER BY chave;
SELECT pagina, rep_codes, motivo FROM esc_pagina_prioridade WHERE vigente_ate IS NULL;
SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME IN ('esc_pagina_prioridade', 'esc_politica_troca') ORDER BY 1, 2;
