SET NAMES utf8mb4;
-- =====================================================================
-- ES_139 · Núcleo na entrada de página e as entradas de 10/11 na U3 (10/11/2026, aplicado pelo Claude via mysql.exe)
--
-- 1. esc_pagina_entrada_checagem ganha nucleo_numero (1, 2 ou 3): o núcleo (OS) da unidade da carteira em que a
--    página entra. O Gerar (sincronização da estrutura) cria os postos novos dela nesse núcleo, nos 3 turnos
--    (versão 1.25.1). NULL = automático (núcleo menos carregado). Roda no 5c_escalas e no 5c_escalas_treino.
-- 2. Pedido do usuário (10/11), com as páginas da seção Ativas no site, fora da escala (Funil de entrada):
--      Alexis Clark     volta para a U3C1 a partir de 10/11 (pediu OS1; ver o passo 4)
--      Daniswings 157m  entra na U3C2 a partir de 10/11 (antes U2C2; junto da Goddess Sylvanas), núcleo 3 (OS3)
--      Lexis Star       saiu de verdade: ignorada no Funil (segue ativa no site), o Capacity não muda
--    A carteira copia a última linha da página (modelo, regra, ordem, volume), como o "Pôr na escala" da tela.
--    A semana de 09/11 não muda: o ES_135 (U3 v2 do OM) já escala as duas. Os postos entram no próximo Gerar.
-- 3. Recalcula o Capacity de 10/11 (a data de cálculo das escalas em uso), com versão.
-- Pode rodar de novo (a coluna e as linhas só entram se faltarem).
-- =====================================================================

USE `5c_escalas`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_pagina_entrada_checagem' AND COLUMN_NAME = 'nucleo_numero');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_pagina_entrada_checagem ADD COLUMN nucleo_numero TINYINT UNSIGNED NULL COMMENT 'núcleo (OS) da unidade da carteira; NULL = automático' AFTER vale_desde", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

USE `5c_escalas_treino`;
SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '5c_escalas_treino' AND TABLE_NAME = 'esc_pagina_entrada_checagem' AND COLUMN_NAME = 'nucleo_numero');
SET @sql := IF(@tem = 0, "ALTER TABLE esc_pagina_entrada_checagem ADD COLUMN nucleo_numero TINYINT UNSIGNED NULL COMMENT 'núcleo (OS) da unidade da carteira; NULL = automático' AFTER vale_desde", 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Carteiras no Capacity (só se a página ainda não tem carteira aberta)
INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-10', NULL, 'U3C1', modelo, regra, ordem, fas_h_estimado, 'Voltou em 10/11/2026 (Funil de entrada, ativa no site desde 09/11/2026): pedido do usuário, núcleo 1 (ES_139)'
  FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Alexis Clark'
   AND NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` x WHERE x.pagina = 'Alexis Clark' AND x.vigente_ate IS NULL)
 ORDER BY vigente_desde DESC LIMIT 1;

INSERT INTO `5c_planning`.`fc_pagina_carteira` (pagina, vigente_desde, vigente_ate, carteira, modelo, regra, ordem, fas_h_estimado, obs)
SELECT pagina, '2026-11-10', NULL, 'U3C2', modelo, regra, ordem, fas_h_estimado, 'Voltou em 10/11/2026 (Funil de entrada, ativa no site desde 09/11/2026): pedido do usuário, U3 núcleo 3, antes U2C2 (ES_139)'
  FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina = 'Daniswings 157m'
   AND NOT EXISTS (SELECT 1 FROM `5c_planning`.`fc_pagina_carteira` x WHERE x.pagina = 'Daniswings 157m' AND x.vigente_ate IS NULL)
 ORDER BY vigente_desde DESC LIMIT 1;

USE `5c_escalas`;
INSERT INTO esc_pagina_entrada_checagem (page_id, pagina, ativa_desde, tipo, decisao, carteira, vale_desde, nucleo_numero, observacao, decidido_por) VALUES
 (398, 'Alexis Clark', '2026-11-09', 'RETORNO', 'ENTROU', 'U3C1', '2026-11-10', 1, 'Pedido do usuário em 10/11: U3, OS1 (ES_139)', NULL),
 (302, 'Daniswings 157m', '2026-11-09', 'RETORNO', 'ENTROU', 'U3C2', '2026-11-10', 3, 'Pedido do usuário em 10/11: U3, OS3; antes U2C2 (ES_139)', NULL),
 (251, 'Lexis Star', '2026-11-07', 'RETORNO', 'IGNORAR', NULL, NULL, NULL, 'Saiu de verdade (usuário em 10/11), mesmo ativa no site (ES_139)', NULL) AS n
ON DUPLICATE KEY UPDATE decisao = n.decisao, carteira = n.carteira, vale_desde = n.vale_desde, nucleo_numero = n.nucleo_numero, observacao = n.observacao;

-- 3. Capacity de 10/11 com as duas páginas
CALL `5c_planning`.`sp_fc_recalcular_postos`('2026-11-10', 'Entrada de Alexis Clark (U3C1) e Daniswings 157m (U3C2) pelo Funil de entrada, ES_139 (5C Escalas)');

SELECT pagina, carteira, vigente_desde, vigente_ate FROM `5c_planning`.`fc_pagina_carteira` WHERE pagina IN ('Alexis Clark', 'Daniswings 157m') AND vigente_ate IS NULL;
SELECT pagina, decisao, carteira, nucleo_numero FROM `5c_escalas`.`esc_pagina_entrada_checagem` ORDER BY pagina;

-- 4. Depois (10/11, decisão do usuário): no Capacity a Alexis Clark divide o posto com a Bianca nos 3 turnos, e a
--    Bianca fica no núcleo dela. O núcleo escolhido só vale para posto em que todas as páginas o têm, então a Alexis
--    fica no núcleo do posto (sem núcleo fixo). Aplicado pelo MCP.
UPDATE `5c_escalas`.`esc_pagina_entrada_checagem` SET nucleo_numero = NULL,
       observacao = 'Pedido do usuário em 10/11: U3. Pediu OS1, mas divide o posto com a Bianca, que fica onde está: a Alexis fica no núcleo do posto (ES_139)'
 WHERE pagina = 'Alexis Clark' AND ativa_desde = '2026-11-09' AND decisao = 'ENTROU';
