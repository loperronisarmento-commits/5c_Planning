-- =====================================================================
-- 5C | Forecast de WFM — 13. Validação dos postos e da necessidade da estrutura
-- Schema: 5c_planning        Rodar depois do 10, 11 e 12.
--
-- Bloco A recalcula 26/10 (janela 12–25/10, a mesma do relatório de 27/10) e o dia de hoje.
-- O resto é só SELECT.
--
-- Referência (espelho do procedimento com os fãs/h do banco para 12–25/10; fator 1,5; Política de no
-- máximo 2 páginas por rep quando as modelos são diferentes; buffer por faltas + pico típico com teto
-- de 40 fãs/h por rep, contado no total de cada turno; falha administrativa sem AF_DOBRA):
--   175 postos · 265 titulares · 62 buffer (A 20 · C 21 · D 21) · 327 precisa · 220 têm · saldo −107
--   Taxa de falta (31/08–25/10): A 9,13% + 0,16% · C 10,34% + 0,16% · D 10,89% + 0,36% (absenteísmo + falha adm.)
--   Antes das decisões de 28/10 (até 3 páginas de modelos diferentes; AF com dobra) dava 161 postos e 315.
--   Na distribuição entre unidades, diferença de 1 é aceitável (no turno A, U1 e U3 ficam quase empatadas);
--   o total de cada turno tem que bater.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------- A. Recalcular ----------
CALL sp_fc_postos('2026-10-26');
CALL sp_fc_postos(CURDATE());

-- ---------- B. Totais de 26/10 × referência ----------
SELECT x.indicador AS `Indicador`, x.calculado AS `Calculado`, x.esperado AS `Esperado`,
       IF(x.calculado = x.esperado, 'ok', 'DIFERENTE') AS `Confere`
FROM (
  SELECT 1 AS o, 'Páginas com carga' AS indicador,
         (SELECT COUNT(DISTINCT pagina) FROM fc_pagina_carga WHERE data_calculo = '2026-10-26') AS calculado, 86 AS esperado
  UNION ALL SELECT 2, 'Postos', (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-26'), 175
  UNION ALL SELECT 3, 'Postos titular', (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-26' AND tipo = 'titular'), 58
  UNION ALL SELECT 4, 'Postos double', (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-26' AND tipo = 'double'), 94
  UNION ALL SELECT 5, 'Postos apoio', (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-26' AND tipo = 'apoio'), 8
  UNION ALL SELECT 6, 'Postos assistente', (SELECT COUNT(*) FROM fc_postos WHERE data_calculo = '2026-10-26' AND tipo = 'assistente'), 15
  UNION ALL SELECT 7, 'Soma das frações = postos', (SELECT ROUND(SUM(fracao)) FROM fc_postos_pagina WHERE data_calculo = '2026-10-26'), 175
  UNION ALL SELECT 8, 'Titulares', (SELECT SUM(titulares) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-26'), 265
  UNION ALL SELECT 9, 'Buffer', (SELECT SUM(buffer) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-26'), 62
  UNION ALL SELECT 10, 'Precisa', (SELECT SUM(precisa) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-26'), 327
  UNION ALL SELECT 11, 'Tem (reps reais)', (SELECT SUM(tem) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-26'), 220
  UNION ALL SELECT 12, 'Saldo', (SELECT SUM(saldo) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-26'), -107
) x ORDER BY x.o;

-- ---------- C. Por unidade e turno × referência ----------
SELECT CONCAT('U', n.unidade) AS `Unidade`, n.turno AS `Turno`,
       n.postos AS `Postos`, e.postos AS `Esperado`, n.titulares AS `Titulares`, e.tit AS `Titulares esperados`,
       ROUND(n.taxa_abs * 100, 2) AS `Abs %`, ROUND(n.taxa_af * 100, 2) AS `AF %`,
       n.faltas_dia AS `Faltas/dia`, e.faltas AS `Faltas esperadas`, n.pico_dia AS `Pico/dia`, e.pico AS `Pico esperado`,
       ROUND(n.presentes_dia, 2) AS `Presentes/dia`, n.buffer AS `Buffer`, e.buf AS `Buffer esperado`,
       n.precisa AS `Precisa`, n.tem AS `Tem`, n.saldo AS `Saldo`,
       IF(n.postos = e.postos AND n.titulares = e.tit AND n.tem = e.tem AND ABS(n.buffer - e.buf) <= 1, 'ok', 'DIFERENTE') AS `Confere`
FROM fc_necessidade_estrutura n
JOIN (SELECT 1 AS u, 'A' AS t, 17 AS postos, 26 AS tit, 25 AS tem, 1.58 AS faltas, 2.24 AS pico, 6 AS buf
      UNION ALL SELECT 1, 'C', 18, 27, 20, 1.89, 1.88, 7 UNION ALL SELECT 1, 'D', 17, 26, 21, 1.91, 1.64, 6
      UNION ALL SELECT 2, 'A', 19, 29, 28, 1.77, 2.11, 7 UNION ALL SELECT 2, 'C', 19, 29, 25, 2.00, 2.32, 7
      UNION ALL SELECT 2, 'D', 20, 30, 30, 2.25, 1.94, 7 UNION ALL SELECT 3, 'A', 21, 32, 23, 1.95, 1.88, 7
      UNION ALL SELECT 3, 'C', 22, 33, 24, 2.31, 1.77, 7 UNION ALL SELECT 3, 'D', 22, 33, 24, 2.48, 1.92, 8) e
  ON e.u = n.unidade AND e.t = n.turno
WHERE n.data_calculo = '2026-10-26'
ORDER BY n.unidade, FIELD(n.turno, 'A', 'C', 'D');

-- ---------- C2. Buffer de cada turno (total das 3 unidades) × referência ----------
SELECT b.turno AS `Turno`, ROUND(b.presentes_dia, 2) AS `Presentes/dia`, b.buffer_global AS `Buffer do turno`,
       e.g AS `Esperado`, b.buffer_minimo AS `Mínimo`, b.buffer AS `Distribuído`,
       IF(b.buffer_global = e.g AND b.buffer = e.g, 'ok', 'DIFERENTE') AS `Confere`
FROM fc_buffer_turno b
JOIN (SELECT 'A' AS t, 20 AS g UNION ALL SELECT 'C', 21 UNION ALL SELECT 'D', 21) e ON e.t = b.turno
WHERE b.data_calculo = '2026-10-26'
ORDER BY FIELD(b.turno, 'A', 'C', 'D');

-- ---------- D. Por carteira e turno × referência ----------
SELECT c.nome AS `Carteira`, n.turno AS `Turno`, n.postos AS `Postos`, e.postos AS `Esperado`,
       n.titulares AS `Titulares`, n.tem AS `Tem`, IF(n.postos = e.postos, 'ok', 'DIFERENTE') AS `Confere`
FROM fc_necessidade_carteira n
JOIN fc_carteira c ON c.carteira = n.carteira
JOIN (SELECT 'U1C1' AS k, 'A' AS t, 9 AS postos UNION ALL SELECT 'U1C1', 'C', 9 UNION ALL SELECT 'U1C1', 'D', 9
      UNION ALL SELECT 'U1C2', 'A', 8 UNION ALL SELECT 'U1C2', 'C', 9 UNION ALL SELECT 'U1C2', 'D', 8
      UNION ALL SELECT 'U2C1', 'A', 10 UNION ALL SELECT 'U2C1', 'C', 10 UNION ALL SELECT 'U2C1', 'D', 10
      UNION ALL SELECT 'U2C2', 'A', 9 UNION ALL SELECT 'U2C2', 'C', 9 UNION ALL SELECT 'U2C2', 'D', 10
      UNION ALL SELECT 'U3C1', 'A', 11 UNION ALL SELECT 'U3C1', 'C', 11 UNION ALL SELECT 'U3C1', 'D', 12
      UNION ALL SELECT 'U3C2', 'A', 10 UNION ALL SELECT 'U3C2', 'C', 11 UNION ALL SELECT 'U3C2', 'D', 10) e
  ON e.k = n.carteira AND e.t = n.turno
WHERE n.data_calculo = '2026-10-26'
ORDER BY n.carteira, FIELD(n.turno, 'A', 'C', 'D');

-- ---------- E. Páginas grandes: postos por turno (26/10) ----------
-- Esperado: Skylar 4/4/4 · Mikayla Free 2/2/2 · Midwest Emma 2/2/2 · Elly Clutch 1,5/1/1,5 ·
--           Cynthia 1/1,5/1 · Nicole Doshi 1/1/1,5 · Mikayla Paid 1/1/1 · Ivy Vale 0,5/0,5/0,5
SELECT pp.pagina AS `Página`,
       ROUND(SUM(IF(pp.turno = 'A', pp.fracao, 0)), 2) AS `A`,
       ROUND(SUM(IF(pp.turno = 'C', pp.fracao, 0)), 2) AS `C`,
       ROUND(SUM(IF(pp.turno = 'D', pp.fracao, 0)), 2) AS `D`
FROM fc_postos_pagina pp
WHERE pp.data_calculo = '2026-10-26'
  AND pp.pagina IN ('Skylar Mae VIP', 'Mikayla Free', 'Midwest Emma Free 221s', 'Elly Clutch 100m',
                    'Cynthia', 'Nicole Doshi', 'Mikayla Paid', 'Ivy Vale')
GROUP BY pp.pagina
ORDER BY pp.pagina;

-- ---------- F. Hoje ----------
SELECT * FROM vw_fc_7_necessidade_estrutura;
SELECT * FROM vw_fc_9_carteiras;
-- Páginas que precisam de manutenção no cadastro da estrutura
SELECT * FROM vw_fc_10_paginas_fora_da_estrutura;

-- ---------- G. Amanhã, depois das 06:30: a etapa 'postos' no log diário ----------
SELECT id, data_calculo, etapa, inicio, fim, status, mensagem
FROM fc_log_execucao ORDER BY id DESC LIMIT 3;
