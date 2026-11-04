SET NAMES utf8mb4;
-- =====================================================================
-- ES_100_sr_uma_pagina.sql  (04/11, aplicado pelo Claude via MCP)
--
-- Pedido do usuário: alguns SRs não funcionam com várias páginas. Parâmetro novo v4_sr_uma_pagina
-- (códigos separados por vírgula; motor 1.19.9): o SR não fica em posto com mais de uma página (fora
-- as de apoio), não acumula outro posto (passo 6) nem cobre página (passo 7).
--   A311550 Omari Anthony Mambo (U1 A núcleo 2, SR 1)
-- Para incluir outros: tela Parâmetros (grupo v4) ou UPDATE no valor.
--
-- Também (pedido de 04/11): v4_nao_subordinado, pares "subordinado:líder". O João Paulo Caial (J110041)
-- não pode ficar sob o Rafael Gauze (R096272, OC da U1 A), porque são casal. A tela Colaboradores recusa
-- o núcleo em que ele ficaria sob essa pessoa (OS do núcleo, OC do turno ou OM da unidade).
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_sr_uma_pagina', 'A311550', 'TEXTO', 'v4', 'SRs que só trabalham com uma página (não pegam posto de várias páginas, não acumulam nem cobrem): Omari Mambo'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_parametro WHERE chave = 'v4_sr_uma_pagina');

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT 'v4_nao_subordinado', 'J110041:R096272', 'TEXTO', 'v4', 'Quem não pode ser subordinado de quem (subordinado:líder): João Paulo Caial e Rafael Gauze (casal)'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_parametro WHERE chave = 'v4_nao_subordinado');

-- Skye Zyon (Daniel Zyon do Nascimento Dalla Vecchia, D008138, U1 D, SR 3): assistente fixa da Mikayla Free.
-- Entra na equipe da página e fica "só assistente" nela (vale também para SR 3); no posto de assistente,
-- a equipe fixa dá preferência a quem está marcado como assistente da página (motor 1.19.9).
UPDATE esc_pagina_prioridade SET rep_codes = CONCAT(rep_codes, ',D008138'), motivo = 'Manager: equipe da Mikayla Free e VIP, 3 por turno; Skye Zyon de assistente fixa no D (ES_97/ES_100)'
 WHERE pagina = 'Mikayla Free' AND vigente_desde = '2026-11-04' AND vigente_ate IS NULL AND motivo LIKE '%ES_97%' AND NOT FIND_IN_SET('D008138', rep_codes);
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, vigente_desde, vigente_ate, motivo)
SELECT 'Mikayla Free', 'D008138', 'ASSISTENTE', '2026-11-04', NULL, 'Manager: Skye Zyon (SR 3) de assistente fixa na Mikayla Free, sem trocar o nome (04/11, ES_100)'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente WHERE pagina = 'Mikayla Free' AND rep_code = 'D008138');

SELECT chave, valor, grupo FROM esc_parametro WHERE chave IN ('v4_sr_uma_pagina', 'v4_nao_subordinado');
