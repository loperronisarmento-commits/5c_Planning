SET NAMES utf8mb4;
-- =====================================================================
-- ES_74_os3_faixa_faturamento.sql  (02/11, já aplicado pelo MCP)
-- Divisão dos OS (Equilíbrio): o OS3 ficava com 9% a 15% do faturamento do turno. Pedido do
-- usuário: de 15% a 20%. O corte entre as partes de cima (OS1, OS1, OS2) e de baixo (OS2, OS3,
-- OS3) sai do meio e vai para onde o OS3 cai nessa faixa (lib/equilibrio.php, escEqDivisao).
-- Prévia de 09/11: OS3 com 16% a 19%, OS1 com 51% a 58% e OS2 com 25% a 31%.
-- =====================================================================

INSERT INTO `5c_escalas`.`esc_parametro` (chave, valor, tipo, grupo, descricao, atualizado_em) VALUES
  ('v4_os3_parte_min', '0.15', 'DECIMAL', 'v4', 'Divisão dos OS (Equilíbrio): parte mínima do OS3 no faturamento do turno; o corte entre as partes de cima e de baixo vai para onde o OS3 fica na faixa', NOW()),
  ('v4_os3_parte_max', '0.20', 'DECIMAL', 'v4', 'Divisão dos OS (Equilíbrio): parte máxima do OS3 no faturamento do turno', NOW())
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);
