SET NAMES utf8mb4;
-- =====================================================================
-- ES_105_assist_baixo_fluxo.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11 (motor 1.19.10):
--   - Letícia Zaleski (L118244, U2 A n2, SR 1): "se for escalar, sempre assist" → v4_sr_so_assistente.
--   - Matheus Chagas (M175553, U2 C n2, SR 1): "sempre assist ou páginas pequenas" → v4_sr_baixo_fluxo.
--   - Gabriela Boretti Pereira (G386564, U2 C n2, SR 3): "deixar em páginas de baixo fluxo" → v4_sr_baixo_fluxo.
--     Ela sai da equipe fixa da Molly Stewart VIP e da zOFTV Molly Stewart (o posto da Molly no C tem 21,4
--     fãs/h, acima do corte).
--   - Corte do baixo fluxo: v4_baixo_fluxo_max = 12 fãs/h (37 dos 177 postos em 05/11).
--   Quem está nessas listas não acumula outro posto nem cobre página; no posto de assistente vale qualquer nível.
--   Sem mudança: Jonhnata Souza já é da equipe da Aline Novak (Privacy); Thiago Arantes já é da Marie Dee VIP.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT x.chave, x.valor, x.tipo, 'v4', x.descricao FROM (
  SELECT 'v4_sr_so_assistente' AS chave, 'L118244' AS valor, 'TEXTO' AS tipo, 'SRs que só entram como assistente, em qualquer página (não acumulam nem cobrem): Letícia Zaleski' AS descricao
  UNION ALL SELECT 'v4_sr_baixo_fluxo', 'M175553,G386564', 'TEXTO', 'SRs só em página de baixo fluxo (até v4_baixo_fluxo_max fãs/h) ou de assistente: Matheus Chagas, Gabriela Boretti'
  UNION ALL SELECT 'v4_baixo_fluxo_max', '12', 'DECIMAL', 'Carga máxima (fãs/h) do posto de quem está em v4_sr_baixo_fluxo'
) x WHERE NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = x.chave);

UPDATE esc_pagina_prioridade SET rep_codes = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', rep_codes, ','), ',G386564,', ',')),
       motivo = LEFT(CONCAT(motivo, ' · sem a Gabriela Boretti (baixo fluxo, ES_105)'), 255)
 WHERE pagina IN ('Molly Stewart VIP', 'zOFTV Molly Stewart') AND vigente_ate IS NULL AND FIND_IN_SET('G386564', rep_codes);

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_so_assistente', 'v4_sr_baixo_fluxo', 'v4_baixo_fluxo_max');
SELECT pagina, rep_codes FROM esc_pagina_prioridade WHERE pagina IN ('Molly Stewart VIP', 'zOFTV Molly Stewart') AND vigente_ate IS NULL;
