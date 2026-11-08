SET NAMES utf8mb4;
-- =====================================================================
-- ES_129_restricoes_om_inativas.sql  (07/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário (07/11): na tela Páginas › Restrições dos OM, editar, apagar, ativar e desativar as
-- restrições, uma por uma e em lote (a OM da U1 quer gerar uma leva sem nenhuma restrição).
-- Desativar tira a restrição da fonte que o Gerar lê (esc_pagina_prioridade, esc_pagina_assistente,
-- esc_sr_divisao, parâmetros v4_sr_*, pode_assistente, inegociável) e guarda aqui o que foi tirado
-- (payload JSON), para ativar de novo como estava (lib/restricoes_om_gestao.php).
-- Cria a tabela no real e no Modo Treino.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `5c_escalas`.`esc_restricao_om_inativa` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(200) NOT NULL COMMENT 'chave da restrição quando foi desativada (prio|id|REP, param|chave|REP...)',
  fonte VARCHAR(20) NOT NULL COMMENT 'prio, assist, div, param, podeassist, ineg',
  rep_code VARCHAR(20) NULL,
  pagina VARCHAR(255) NULL,
  unidade TINYINT NULL,
  tipo VARCHAR(120) NOT NULL COMMENT 'rótulo da restrição (como aparecia na tela)',
  texto VARCHAR(500) NOT NULL DEFAULT '',
  payload MEDIUMTEXT NOT NULL COMMENT 'o que foi tirado da fonte (JSON), para devolver ao ativar',
  desativada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  desativada_por INT NULL,
  KEY idx_unidade (unidade),
  KEY idx_rep (rep_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Restrições dos OM desativadas (ES_129)';

CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_restricao_om_inativa` LIKE `5c_escalas`.`esc_restricao_om_inativa`;

SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_NAME = 'esc_restricao_om_inativa';
