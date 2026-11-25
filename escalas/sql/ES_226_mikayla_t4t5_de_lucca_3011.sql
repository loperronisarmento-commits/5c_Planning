SET NAMES utf8mb4;
-- =====================================================================
-- ES_226 · Mikayla Paid, T4/T5: Guilherme de Lucca G115750 travado nos dias sem o Pedro, na semana de 30/11
-- (24/11, pedido do usuário depois da OM da U1: "T4T5 com o Pedro, gosto muito. E o de Lucca enquanto o Jonnas está
-- de férias, funciona."; Jonnas Silva J066864 de férias de 27/11 a 07/12, ES_177).
--   Plano 168 (v1.6): Pedro P611260 em 30/11, 02, 03 e 04/12 (vem da base 80/20); de Lucca em 01, 05 e 06/12.
--   Trava o de Lucca nesses 3 dias (6 células), porque pelo KPI (nível 0) a 1.39.0 o trocaria pela Rafaela.
--   O 07/12 é da semana seguinte: travar lá quando a semana de 07/12 for gerada, se o Jonnas ainda não tiver voltado.
-- Depois de um recálculo da semana de 30/11 que não mantenha as travadas: este script de novo (com o id do plano novo).
-- =====================================================================
USE `5c_escalas`;
SET @plano := 168;
SELECT 'antes' AS momento, dia, bloco, rep_code, origem, travada FROM esc_plano_alocacao
 WHERE plano_id = @plano AND pagina = 'Mikayla Paid' AND papel = 'REGULAR' AND bloco IN ('T4', 'T5') ORDER BY dia, bloco;

START TRANSACTION;
UPDATE esc_plano_alocacao
   SET rep_code = 'G115750', origem = 'MANUAL', travada = 1,
       motivo = 'Mikayla Paid T4/T5: de Lucca enquanto o Jonnas está de férias (pedido da OM da U1, ES_226)', alterado_em = NOW()
 WHERE plano_id = @plano AND pagina = 'Mikayla Paid' AND papel = 'REGULAR' AND bloco IN ('T4', 'T5')
   AND dia IN ('2026-12-01', '2026-12-05', '2026-12-06');
SELECT ROW_COUNT() AS celulas_travadas;
COMMIT;

SELECT 'depois' AS momento, dia, bloco, rep_code, origem, travada FROM esc_plano_alocacao
 WHERE plano_id = @plano AND pagina = 'Mikayla Paid' AND papel = 'REGULAR' AND bloco IN ('T4', 'T5') ORDER BY dia, bloco;
