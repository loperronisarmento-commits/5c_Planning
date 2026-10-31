-- =====================================================================
-- ES_53_merito_abs_ativo.sql  (31/10)
-- Chave para tirar o absenteísmo da nota do mérito: merito_abs_ativo.
--   1 = como hoje (Nota = Desempenho × SLA × Presença ÷ (1 + pena), com o teto
--       do absenteísmo crítico);
--   0 = presença 1 para todos e sem o teto do crítico. O absenteísmo continua
--       medido e aparece nas telas Mérito e Mérito de cada SR, só como informação.
-- Entra com 1 (nada muda). Para desligar: Parâmetros › grupo "merito" ›
-- merito_abs_ativo = 0 e, depois, recalcular o mérito (o cache vale
-- merito_cache_horas). O mérito também desempata a escala no "Gerar".
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('merito_abs_ativo', '1', 'BOOL', 'merito', 'Absenteísmo na nota do mérito: 1 = pesa (presença e teto do crítico); 0 = fica fora, só como informação')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- Conferência
SELECT chave, valor, tipo, grupo, descricao FROM esc_parametro WHERE chave LIKE 'merito_abs%' ORDER BY chave;
