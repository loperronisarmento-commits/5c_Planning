-- =====================================================================
-- ES_59_sobrecarga_mesma_agencia.sql  (01/11)
-- Sobrecarga para fechar lacunas (decisão de 01/11): no último passo do motor, um SR já alocado
-- no turno pode puxar mais postos até 30% acima da capacidade, contanto que todas as páginas
-- sejam da mesma agência (5c_pages.pages.client). Acima de 100% ele entra cobrindo (papel de
-- assistente, não é dono) e cede a página quando houver gente. Até 100% continua a Política.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('v4_over_capacity_max', '0.30', 'DECIMAL', 'v4', 'Quanto um SR pode passar da capacidade ao puxar postos em lacuna: só com páginas da mesma agência, cobrindo (sem ser dono), sinalizado')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- Conferência
SELECT chave, valor, descricao FROM esc_parametro WHERE chave = 'v4_over_capacity_max';
