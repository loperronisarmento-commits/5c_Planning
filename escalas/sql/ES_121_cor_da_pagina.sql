SET NAMES utf8mb4;
-- =====================================================================
-- ES_121_cor_da_pagina.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- P-02 (cor da página, validada em 03/11) no Gerar a partir da 1.19.17. Decisão do usuário em 04/11,
-- depois da simulação (lacunas da semana de 05/11: 358 sem a cor, 538 só com a vermelha, 964 completa):
-- vale só a trava da vermelha, com reserva de quem conhece; a cota de 20% da amarela fica desligada.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
 ('v4_cor_ativa', '1', 'BOOL', 'v4', 'P-02: o Gerar usa a cor da página (vermelha só com quem conhece ou é da equipe fixa; reserva de quem conhece)'),
 ('v4_cor_amarela_ativa', '0', 'BOOL', 'v4', 'P-02: cota da amarela (no máximo v4_cor_amarela_novos dos turnos da semana com gente nova). Desligada em 04/11'),
 ('v4_cor_amarela_novos', '0.20', 'DECIMAL', 'v4', 'P-02: parte dos turnos da semana da amarela que pode ir para quem não conhece a página'),
 ('v4_cor_conhece_dias', '7', 'INT', 'v4', 'P-02: dias na página para contar como quem conhece'),
 ('v4_cor_janela_dias', '90', 'INT', 'v4', 'P-02: janela (dias) para contar os dias na página')
AS n ON DUPLICATE KEY UPDATE valor = n.valor, tipo = n.tipo, descricao = n.descricao;

SELECT chave, valor FROM esc_parametro WHERE chave LIKE 'v4_cor_%' ORDER BY chave;
