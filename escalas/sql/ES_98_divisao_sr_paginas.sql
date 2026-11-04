SET NAMES utf8mb4;
-- =====================================================================
-- ES_98_divisao_sr_paginas.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Divisão do SR entre páginas (motor 1.19.9): o SR reparte os turnos em que está disponível entre as
-- páginas da lista, na proporção de "parte" (0 a 1), mesmo que a página seja de outra unidade. A cada
-- dia, a vez vai para a página mais atrasada em relação à parte dela; o que faltar para 1 é a escala
-- normal da unidade dele. Entra antes da equipe fixa da página.
--   Vanderson Garcia (V115422): 50% Brandi Love (U2) e 50% Elly Clutch (U3).
--   Luan Oliveira (L101222): 50% Elly Clutch (U3) e 50% a escala normal da unidade dele.
--   Bruno Fonseca (B163774): 50% Molly Stewart VIP e 50% Nicole Doshi (as duas da U2; pedido de 04/11).
-- =====================================================================

USE `5c_escalas`;

CREATE TABLE IF NOT EXISTS esc_sr_divisao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rep_code VARCHAR(10) NOT NULL,
  pagina VARCHAR(120) NOT NULL,
  parte DECIMAL(4,3) NOT NULL,
  vigente_desde DATE NOT NULL,
  vigente_ate DATE NULL,
  motivo VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_div_rep (rep_code, vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO esc_sr_divisao (rep_code, pagina, parte, vigente_desde, motivo)
SELECT x.rep, x.pagina, x.parte, '2026-11-04', x.motivo FROM (
  SELECT 'V115422' AS rep, 'Brandi Love' AS pagina, 0.500 AS parte, 'Manager: Vanderson 50% Brandi Love e 50% Elly Clutch (04/11)' AS motivo
  UNION ALL SELECT 'V115422', 'Elly Clutch 100m', 0.500, 'Manager: Vanderson 50% Brandi Love e 50% Elly Clutch (04/11)'
  UNION ALL SELECT 'L101222', 'Elly Clutch 100m', 0.500, 'Manager: Luan 50% Elly Clutch e 50% a escala normal (04/11)'
  UNION ALL SELECT 'B163774', 'Molly Stewart VIP', 0.500, 'Manager: Bruno Fonseca divide os turnos entre Molly Stewart VIP e Nicole Doshi (04/11)'
  UNION ALL SELECT 'B163774', 'Nicole Doshi', 0.500, 'Manager: Bruno Fonseca divide os turnos entre Molly Stewart VIP e Nicole Doshi (04/11)'
) x WHERE NOT EXISTS (SELECT 1 FROM esc_sr_divisao d WHERE d.rep_code = x.rep AND d.pagina = x.pagina AND d.vigente_desde = '2026-11-04');

SELECT rep_code, pagina, parte, vigente_desde FROM esc_sr_divisao ORDER BY rep_code, pagina;
