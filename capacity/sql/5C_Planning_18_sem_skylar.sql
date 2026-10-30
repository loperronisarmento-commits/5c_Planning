-- =====================================================================
-- 5C | Forecast de WFM — 18. Estrutura sem a Skylar Mae VIP
-- Schema: 5c_planning        28/10/2026        Rodar depois do 16.
--
-- A Skylar saiu em 28/10. A página ainda está ativa no cadastro, mas o cálculo dos postos já não deve
-- contar com ela. Este script:
--   1. fecha a vigência da Skylar Mae VIP em fc_pagina_carteira (vigente_ate = 01/11: não entra na
--      estrutura que começa em 01/11);
--   2. troca a maior página da U3 · C1 para a Lily Lou OFTV;
--   3. recalcula os postos de 28/10, que são a base fixa da escala de 01/11 a 08/11.
-- O cálculo diário das 06:30 já sai sem a Skylar. Enquanto ela estiver ativa no cadastro, aparece na
-- vw_fc_10_paginas_fora_da_estrutura como "Ativa sem carteira": some quando for desativada.
-- Não mexe em fc_contratacoes nem no cálculo de 26/10 (referência do 13).
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Cópia de segurança (só na primeira vez)
CREATE TABLE IF NOT EXISTS fc_pagina_carteira_bkp_skylar AS SELECT * FROM fc_pagina_carteira;
CREATE TABLE IF NOT EXISTS fc_carteira_bkp_skylar AS SELECT * FROM fc_carteira;

-- 2. Tirar a Skylar da estrutura
START TRANSACTION;

UPDATE fc_pagina_carteira
   SET vigente_ate = '2026-11-01', obs = 'Modelo saiu em 28/10/2026'
 WHERE pagina = 'Skylar Mae VIP' AND vigente_ate IS NULL;

UPDATE fc_carteira SET maior_pagina = 'Lily Lou OFTV'
 WHERE carteira = 'U3C1' AND maior_pagina = 'Skylar Mae VIP';

COMMIT;

-- 3. Recalcular a base da semana de 01/11 a 08/11
CALL sp_fc_postos('2026-10-28');

-- ---------- Conferência (data_calculo = 28/10) ----------
-- 3.1 A Skylar não ocupa mais posto (esperado 0 e 0)
SELECT (SELECT COUNT(*) FROM fc_pagina_carga  WHERE data_calculo = '2026-10-28' AND pagina = 'Skylar Mae VIP') AS `Carga Skylar`,
       (SELECT COUNT(*) FROM fc_postos_pagina WHERE data_calculo = '2026-10-28' AND pagina = 'Skylar Mae VIP') AS `Postos Skylar`;

-- 3.2 Por unidade e turno. Só a U3 muda (U3 · C1 perde 4 postos por turno).
SELECT CONCAT('U', n.unidade, n.turno) AS `Unidade`, n.postos AS `Postos`, e.postos AS `Esp. postos`,
       n.titulares AS `Titulares`, e.tit AS `Esp. titulares`, n.pico_dia AS `Pico`, e.pico AS `Esp. pico`,
       n.buffer AS `Buffer`, e.buf AS `Esp. buffer`, n.precisa AS `Precisa`, n.tem AS `Tem`, n.saldo AS `Saldo`,
       IF(n.postos = e.postos AND n.titulares = e.tit AND n.buffer = e.buf AND ABS(n.pico_dia - e.pico) <= 0.01,
          'ok', 'DIFERENTE') AS `Confere`
FROM fc_necessidade_estrutura n
JOIN (SELECT 3 AS u, 'A' AS t, 13 AS postos, 20 AS tit, 1.82 AS pico, 6 AS buf UNION ALL
      SELECT 3, 'C', 14, 21, 1.35, 6 UNION ALL SELECT 3, 'D', 12, 18, 1.62, 6 UNION ALL
      SELECT 2, 'A', 18, 27, 2.18, 6 UNION ALL SELECT 2, 'C', 18, 27, 2.69, 7 UNION ALL
      SELECT 2, 'D', 20, 30, 1.77, 7 UNION ALL SELECT 1, 'A', 21, 32, 1.56, 6 UNION ALL
      SELECT 1, 'C', 22, 33, 1.75, 7 UNION ALL SELECT 1, 'D', 22, 33, 2.22, 8) e
  ON e.u = n.unidade AND e.t = n.turno
WHERE n.data_calculo = '2026-10-28'
ORDER BY n.unidade, FIELD(n.turno, 'A', 'C', 'D');

-- 3.3 Buffer por turno: esperado A 18 · C 20 · D 21
SELECT turno AS `Turno`, ROUND(presentes_dia, 2) AS `Presentes/dia`, buffer AS `Buffer`
FROM fc_buffer_turno WHERE data_calculo = '2026-10-28' ORDER BY FIELD(turno, 'A', 'C', 'D');

-- 3.4 Totais: esperado 160 postos (titular 55 · double 94 · apoio 4 · assistente 7),
--     241 titulares, 59 de buffer, 300 precisa, 220 têm, saldo −80
SELECT (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-28') AS `Postos`,
       (SELECT SUM(tipo = 'titular')    FROM fc_postos WHERE data_calculo = '2026-10-28') AS `Titular`,
       (SELECT SUM(tipo = 'double')     FROM fc_postos WHERE data_calculo = '2026-10-28') AS `Double`,
       (SELECT SUM(tipo = 'apoio')      FROM fc_postos WHERE data_calculo = '2026-10-28') AS `Apoio`,
       (SELECT SUM(tipo = 'assistente') FROM fc_postos WHERE data_calculo = '2026-10-28') AS `Assistente`,
       SUM(titulares) AS `Titulares`, SUM(buffer) AS `Buffer`, SUM(precisa) AS `Precisa`,
       SUM(tem) AS `Tem`, SUM(saldo) AS `Saldo`
FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-28';

-- 3.5 Carteiras da U3: esperado U3 · C1 5/5/4 postos e 8/8/6 titulares; U3 · C2 igual (8/9/8 e 12/14/12)
SELECT carteira AS `Carteira`, turno AS `Turno`, postos AS `Postos`, titulares AS `Titulares`, tem AS `Tem`
FROM fc_necessidade_carteira
WHERE data_calculo = '2026-10-28' AND carteira IN ('U3C1', 'U3C2')
ORDER BY carteira, FIELD(turno, 'A', 'C', 'D');

-- ---------------------------------------------------------------------
-- 4. Desfazer (só se precisar): a Skylar volta para a U3 · C1
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- UPDATE fc_pagina_carteira SET vigente_ate = NULL, obs = NULL
--  WHERE pagina = 'Skylar Mae VIP' AND vigente_desde = '2026-11-01';
-- UPDATE fc_carteira SET maior_pagina = 'Skylar Mae VIP' WHERE carteira = 'U3C1';
-- COMMIT;
-- CALL sp_fc_postos('2026-10-28');
