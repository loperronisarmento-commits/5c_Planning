SET NAMES utf8mb4;
-- =====================================================================
-- ES_103_caial_multipagina.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário: o João Paulo Caial (J110041, U2 A núcleo 1, SR 5) faz Mo Saffari, Sean Adams, Jay,
-- Sebi Barraza e Matty Caron ao mesmo tempo, sem assistente; escalar fixo nelas o máximo possível.
-- Parâmetro novo v4_sr_multipagina ("CÓDIGO:página|página;CÓDIGO:..."; motor 1.19.9): no turno dele, o SR
-- pega todos os postos vazios que só têm páginas da lista, acumulando, sem limite de capacidade.
-- Na U2 A são 4 postos (Mo Saffari; Jay + Sebi Barraza; Sean Adams; Matty Caron), 44,4 fãs/h em 35,2 (126%).
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_sr_multipagina', 'J110041:Mo Saffari|Sean Adams|Jay|Sebi Barraza|Matty Caron', 'TEXTO', 'v4',
       'SR fixo em várias páginas ao mesmo tempo, acumulando os postos (CÓDIGO:página|página;...): João Paulo Caial'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_parametro WHERE chave = 'v4_sr_multipagina');

SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_sr_multipagina';
