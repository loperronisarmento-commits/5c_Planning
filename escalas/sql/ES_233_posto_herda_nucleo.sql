SET NAMES utf8mb4;
-- =====================================================================
-- ES_233 · Parâmetro v4_posto_herda_nucleo (25/11/2026, decisão do usuário; 5C Escalas 1.40.0; via MCP)
--   "As páginas não vão mais trocar de núcleo, vai ser extremamente raro. Se uma página saiu e depois voltou, volta
--   sempre pra onde ela estava. Podemos mover de núcleo quando for pra controle."
--   1 = posto novo da sincronização da estrutura fica no núcleo (e cobertura) do último posto da página principal na
--   mesma unidade e turno (escEstruturaHerdarNucleo, lib/estrutura.php), por cima da escolha automática e da divisão
--   dos OS; o núcleo escolhido no Funil de entrada continua valendo por cima. 0 = como antes.
-- =====================================================================
USE `5c_escalas`;
SELECT 'antes' AS momento, chave, valor FROM esc_parametro WHERE chave IN ('v4_posto_herda_nucleo', 'v4_os_equilibrio');

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_posto_herda_nucleo', '1', t.tipo, t.grupo,
       '1 = a página não troca de núcleo: posto refeito ou de página que voltou fica no núcleo do último posto dela na mesma unidade e turno (25/11, ES_233)'
  FROM esc_parametro t WHERE t.chave = 'v4_os_equilibrio'
   AND NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = 'v4_posto_herda_nucleo');

SELECT 'depois' AS momento, chave, valor, tipo, grupo FROM esc_parametro WHERE chave IN ('v4_posto_herda_nucleo', 'v4_os_equilibrio');
