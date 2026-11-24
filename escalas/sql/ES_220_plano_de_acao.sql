-- ES_220 (24/11/2026): Plano de Ação (item C da revisão de 24/11; a tela era "Sugestões de troca").
-- Uma linha por ação sugerida ou pendente do sistema, venha de onde vier: trocas de pessoas e de páginas,
-- cobertura de lacunas, Recalcular Tier (Grade), alertas (diário do Capacity), entradas e saídas de página,
-- pedidos pendentes e dados a conferir. A chave "ref" evita repetir a mesma ação a cada leitura.
SET NAMES utf8mb4;

-- Antes: a tabela não existe
SELECT COUNT(*) AS existe FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_acao';

CREATE TABLE IF NOT EXISTS `5c_escalas`.`esc_acao` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ref` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'chave única da ação na origem (ex.: tier:422, sug:2026-11-30:C:COBERTURA)',
  `tipo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'TROCA_PESSOA, TROCA_PAGINA, COBERTURA, CARGA, RECALCULAR_TIER, ENTRADA_PAGINA, SAIDA_PAGINA, ALERTA, PEDIDO, DADOS',
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detalhe` text COLLATE utf8mb4_unicode_ci,
  `envolve` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'quem ou o que a ação envolve (pessoas, páginas)',
  `origem` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'tela ou processo que gerou: gerar, grade, owners, equilibrio, funil, saidas, movimentos, capacity, indisponibilidade, solicitacoes, auditoria',
  `unidade` tinyint DEFAULT NULL,
  `turno` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dia` date DEFAULT NULL COMMENT 'dia a que a ação se refere, quando tem',
  `link_tela` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_params` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'JSON com os parâmetros da tela de origem',
  `dados` json DEFAULT NULL,
  `status` enum('PENDENTE','FEITA','DESCARTADA') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDENTE',
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'motivo do descarte ou observação ao concluir',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `visto_em` datetime DEFAULT NULL COMMENT 'última vez que a origem ainda apontava a ação',
  `decidido_em` datetime DEFAULT NULL,
  `decidido_por` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_acao_ref` (`ref`),
  KEY `ix_acao_status` (`status`, `tipo`, `unidade`),
  KEY `ix_acao_dia` (`dia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Plano de Ação: ações sugeridas ou pendentes de todas as telas (1.38.0)';

-- Depois
SELECT COUNT(*) AS existe FROM information_schema.TABLES WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_acao';
SHOW CREATE TABLE `5c_escalas`.`esc_acao`;

-- Modo Treino: a mesma tabela no schema de treino.
-- (rodado junto: CREATE TABLE IF NOT EXISTS `5c_escalas_treino`.`esc_acao` LIKE a de cima)
