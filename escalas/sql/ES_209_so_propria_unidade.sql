SET NAMES utf8mb4;
-- =====================================================================
-- ES_209 · Parâmetro v4_so_propria_unidade (5C Escalas 1.35.0, 23/11, pedido do usuário: "Pode restringir os usuários a páginas
-- da própria unidade, buffer da U1 só faz na U1 e assim por diante"); pode rodar pelo MCP
--   1 = o Gerar (lib/motor_v4.php) e o "mínimo de mudanças" (lib/minimo.php) só põem o SR em página da unidade dele: buffer,
--   Overlap, divisão (50/50) e equipe fixa. Célula travada à mão continua. 0 = volta "outra unidade em último caso".
-- =====================================================================
USE `5c_escalas`;
SELECT 'antes' AS momento, chave, valor FROM esc_parametro WHERE chave = 'v4_so_propria_unidade';
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_so_propria_unidade', '1', t.tipo, t.grupo,
       'SR só em página da própria unidade (buffer, Overlap, divisão e equipe fixa); célula travada à mão fica. 0 = outra unidade em último caso (1.35.0, ES_209)'
  FROM esc_parametro t WHERE t.chave = 'v4_uma_plataforma_turno'
   AND NOT EXISTS (SELECT 1 FROM esc_parametro WHERE chave = 'v4_so_propria_unidade');
SELECT 'depois' AS momento, chave, valor, tipo, grupo FROM esc_parametro WHERE chave = 'v4_so_propria_unidade';
