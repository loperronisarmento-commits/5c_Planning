-- =====================================================================
-- ES_69_ajustes_managers_4.sql  (01/11)
-- Quarta leva dos managers para a semana de 02/11:
--   - Midwest Emma, T4/T5 (turno C): a Letícia Belo Aragão (L434531) entra na Emma (no lugar do
--     Carlos Vicente, do Fabio Bueno e da Gabriela Pereira); o Fabio (F333185) e a Gabriela (G386564)
--     descem para a Molly Stewart. A Letícia sai da equipe fixa da Molly e o Fabio e a Gabriela entram.
--   - CJ Miles, T2/T3 (turno A): a Nathalia Scalercio (N176385) de regular e a Beatriz Carvalho da
--     Silva (B183426) de assistente (as duas na equipe fixa da página; a Beatriz só assistente; o motor
--     1.18.1 deixa quem o manager marcou de assistente ficar no posto de assistente em qualquer nível).
--   - Sophie Sierra: o Julio Antezana (J621606) sai da página (179% da meta, não faz sentido numa
--     página pequena).
-- Julia Broggio e Willian Rocha (Mo Saffari, Aline Novak, Sophie Sierra, Apex) já saíram no ES_67.
-- Já aplicado em 01/11 pelo MCP. Não precisa recalcular o Capacity.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_pagina_prioridade SET rep_codes = 'M497745,T102316,E123445,L173493,B163774,D422040,F333185,G386564',
       motivo = 'Manager: equipe fixa da Molly (a Letícia foi para a Emma; Fabio e Gabriela entram no T4/T5)'
 WHERE pagina = 'Molly Stewart VIP' AND vigente_ate IS NULL;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde) VALUES
  ('Midwest Emma Free 221s', 'L434531', 'Manager: Letícia Belo Aragão na Emma no T4/T5', '2026-11-02'),
  ('CJ Miles', 'N176385,B183426', 'Manager: Nathalia de regular e Beatriz de assistente no T2/T3', '2026-11-02');

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde) VALUES
  ('CJ Miles', 'B183426', 'ASSISTENTE', 'Manager: Beatriz de assistente na CJ Miles (Nathalia de regular)', '2026-11-02'),
  ('Sophie Sierra', 'J621606', 'FORA', 'Manager: Julio (179% da meta) não fica numa página pequena', '2026-11-02');
