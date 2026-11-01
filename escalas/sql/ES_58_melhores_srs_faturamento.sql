-- =====================================================================
-- ES_58_melhores_srs_faturamento.sql  (01/11)
-- Melhores SRs pelo faturamento (decisão de 01/11): antes da equipe fixa, as vagas das páginas
-- das modelos que faturam v4_melhores_receita_min (US$ 100 mil) ou mais em 30 dias recebem o SR
-- do turno com a maior venda por hora, na ordem da fila (a modelo que mais fatura escolhe
-- primeiro). Substitui a regra antiga pelo tier do Intraday (v4_tiers_melhores_srs, SS/A/B), que
-- deixava a Mikayla Free (tier C, a página que mais fatura) com SRs de venda baixa.
-- Página desconsiderada (OFTV sem venda) nunca entra nessa regra.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('v4_melhores_receita_min', '100000', 'DECIMAL', 'v4', 'US$ em 30 dias da modelo a partir do qual as páginas dela recebem antes os SRs que mais vendem por hora (na ordem de faturamento)')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- A regra antiga pelo tier sai de uso (o parâmetro fica só como registro).
UPDATE esc_parametro SET descricao = 'Sem uso desde a 1.18.0 (01/11): os melhores SRs seguem o faturamento (v4_melhores_receita_min)'
 WHERE chave = 'v4_tiers_melhores_srs';

-- Conferência
SELECT chave, valor, tipo, grupo, descricao FROM esc_parametro WHERE chave IN ('v4_melhores_receita_min', 'v4_tiers_melhores_srs');
