-- =====================================================================
-- 5C | Forecast de WFM — 16. Postos fixos da semana de 01/11 a 08/11 (para o Planning tool)
-- Schema: 5c_planning        28/10/2026        Rodar depois do 11 (versão 1.1) e do 13.
--
-- Recalcula os postos com data_calculo = 28/10/2026 (carga de 14 a 27/10), já com o agrupamento
-- em duas fases (primeiro as páginas da mesma modelo). O Planning tool carrega a escala da semana
-- de fc_postos e fc_postos_pagina com data_calculo = '2026-10-28'. O cálculo diário das 06:30 grava
-- outras datas e não mexe nesta.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

CALL sp_fc_postos('2026-10-28');

-- 1. Necessidade da semana por unidade e turno
SELECT CONCAT('U', unidade) AS `Unidade`, turno AS `Turno`, postos AS `Postos`, titulares AS `Titulares`,
       buffer AS `Buffer`, precisa AS `Precisa`, tem AS `Tem`, saldo AS `Saldo`
FROM fc_necessidade_estrutura
WHERE data_calculo = '2026-10-28'
ORDER BY unidade, FIELD(turno, 'A', 'C', 'D');

-- 2. Totais
SELECT COUNT(*) AS `Postos`,
       SUM(tipo = 'titular') AS `Titular`, SUM(tipo = 'double') AS `Double`,
       SUM(tipo = 'apoio') AS `Apoio`, SUM(tipo = 'assistente') AS `Assistente`,
       (SELECT SUM(precisa) FROM fc_necessidade_estrutura WHERE data_calculo = '2026-10-28') AS `Precisa`
FROM fc_postos WHERE data_calculo = '2026-10-28';

-- 3. Postos com 2 ou mais páginas: quantos são da mesma modelo
SELECT SUM(z.modelos = 1) AS `Mesma modelo`, SUM(z.modelos > 1) AS `Modelos diferentes`
FROM (SELECT pp.carteira, pp.turno, pp.posto, COUNT(DISTINCT pc.modelo) AS modelos
      FROM fc_postos_pagina pp
      JOIN fc_pagina_carga pc ON pc.data_calculo = pp.data_calculo AND pc.pagina = pp.pagina AND pc.turno = pp.turno
      WHERE pp.data_calculo = '2026-10-28'
      GROUP BY pp.carteira, pp.turno, pp.posto
      HAVING COUNT(*) > 1) z;
