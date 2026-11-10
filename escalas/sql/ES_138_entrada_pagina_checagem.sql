SET NAMES utf8mb4;
-- =====================================================================
-- ES_138 · Entradas de página detectadas pela DB (10/11/2026)
--
-- O Funil de entrada ganha a seção "Ativas no site, fora da escala": páginas com
-- active = 1 em 5c_pages.pages e sem carteira aberta no Capacity (fc_pagina_carteira),
-- como entrada nova ou retorno, no espelho de Saídas de página. Caso que motivou:
-- Alexis Clark e Daniswings 157m, que saíram pela tela Saídas e voltaram a ficar
-- ativas em 09/11, mas ficaram presas no bloqueio de 60 dias (v4_saida_bloqueio_dias).
--
-- Esta tabela guarda a decisão de cada entrada (uma por página e dia em que ficou ativa):
--   ativa_desde  dia em que a página ficou ativa no site (foto diária 5c_planning.fc_pagina_status_diario)
--   tipo         RETORNO (já esteve no Capacity ou na escala) ou NOVA
--   decisao      ENTROU (carteira gravada no Capacity pela tela) ou IGNORAR (não é página de escala:
--                PH, X, MYM, teste...). Se a página sair e voltar, a nova entrada aparece de novo.
--   carteira, vale_desde  onde e desde quando entrou (ENTROU)
-- Roda no 5c_escalas e no 5c_escalas_treino. Só cria a tabela. Pode rodar de novo.
-- =====================================================================

USE `5c_escalas`;
CREATE TABLE IF NOT EXISTS `esc_pagina_entrada_checagem` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int DEFAULT NULL COMMENT '5c_pages.pages.id',
  `pagina` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativa_desde` date NOT NULL COMMENT 'dia em que ficou ativa no site',
  `tipo` enum('RETORNO','NOVA') COLLATE utf8mb4_unicode_ci NOT NULL,
  `decisao` enum('ENTROU','IGNORAR') COLLATE utf8mb4_unicode_ci NOT NULL,
  `carteira` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vale_desde` date DEFAULT NULL,
  `observacao` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decidido_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decidido_por` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entrada` (`pagina`, `ativa_desde`),
  KEY `ix_entrada_decisao` (`decisao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Decisão das páginas ativas no site e fora da escala (Funil de entrada)';

USE `5c_escalas_treino`;
CREATE TABLE IF NOT EXISTS `esc_pagina_entrada_checagem` LIKE `5c_escalas`.`esc_pagina_entrada_checagem`;

SELECT TABLE_SCHEMA AS banco, COUNT(*) AS tabela_criada FROM information_schema.TABLES
 WHERE TABLE_NAME = 'esc_pagina_entrada_checagem' AND TABLE_SCHEMA IN ('5c_escalas', '5c_escalas_treino') GROUP BY TABLE_SCHEMA;
