-- =====================================================================
-- ES_31_corrige_es25_roster.sql  (30/10)
-- O ES_25 rodou depois do ES_29 e 2 das trocas de turno ficaram pela metade, porque
-- o par mudou de cargo no roster de 30/10:
--   - Marcos Vinicius Cid Mélo (M137535) foi para U2 · T6/T1 · núcleo 3, mas a
--     Carolina Xavier Broggio (C099427), agora OS da U3 · T4/T5, não foi para o lugar
--     dele e ficou com a vaga fixa dele (posto 348, dupla da U2 · T4/T5);
--   - Matheus Silva Oliveira (M060354) foi para U3 · T4/T5 · núcleo 3, mas o Raphael
--     Estevam (R095006), agora OC da U1 · T2/T3, ficou com as 2 vagas fixas dele
--     (postos 365 e 366, duplas da U3 · T2/T3).
-- Este script desfaz só esses 2 pares: Marcos volta para U2 · T4/T5 · núcleo 2 e
-- Matheus para U3 · T2/T3 · núcleo 1, cada um com as vagas fixas de volta. Se eles
-- ainda quiserem mudar, registre em Pessoas › Solicitações (troca 1 por 1 com um par).
-- Também fecha em 31/10 a configuração antiga do Raphael, como o ES_24 fez com os
-- outros OC (o ES_29 tinha reaberto).
-- As outras 18 trocas do ES_25 ficaram certas e não mudam.
-- Depois: rodar de novo o ES_30 (cria a tabela de solicitações no treino) e
-- recalcular as semanas de 26/10 e 02/11. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- 1. Marcos de volta ao núcleo de origem (U2 · C · 2) a partir de 01/11.
UPDATE esc_colaborador_config c
  JOIN esc_nucleo n ON n.unidade = 2 AND n.turno = 'C' AND n.numero = 2 AND n.vigente_ate IS NULL
   SET c.nucleo_id = n.id, c.unidade = n.unidade, c.turno = n.turno,
       c.observacao = LEFT(CONCAT('volta ao núcleo de origem: o par do ES_25 (C099427) virou OS no ES_29 (ES_31); ', COALESCE(c.observacao, '')), 255)
 WHERE c.rep_code = 'M137535' AND c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL
   AND c.observacao LIKE '%(ES_25) com C099427%';

UPDATE esc_vaga_equipe
   SET rep_code = 'M137535', motivo = LEFT(CONCAT('volta para o Marcos (ES_31); ', COALESCE(motivo, '')), 255)
 WHERE rep_code = 'C099427' AND motivo LIKE '%(ES_25)%' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');

-- 2. Matheus de volta ao núcleo de origem (U3 · A · 1) a partir de 01/11.
UPDATE esc_colaborador_config c
  JOIN esc_nucleo n ON n.unidade = 3 AND n.turno = 'A' AND n.numero = 1 AND n.vigente_ate IS NULL
   SET c.nucleo_id = n.id, c.unidade = n.unidade, c.turno = n.turno,
       c.observacao = LEFT(CONCAT('volta ao núcleo de origem: o par do ES_25 (R095006) virou OC no ES_29 (ES_31); ', COALESCE(c.observacao, '')), 255)
 WHERE c.rep_code = 'M060354' AND c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL
   AND c.observacao LIKE '%(ES_25) com R095006%';

UPDATE esc_vaga_equipe
   SET rep_code = 'M060354', motivo = LEFT(CONCAT('volta para o Matheus (ES_31); ', COALESCE(motivo, '')), 255)
 WHERE rep_code = 'R095006' AND motivo LIKE '%(ES_25)%' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');

-- 3. Configuração antiga do Raphael (OC) fecha na virada, como a dos outros OC.
UPDATE esc_colaborador_config
   SET vigente_ate = '2026-10-31',
       observacao = LEFT(CONCAT(COALESCE(CONCAT(observacao, '; '), ''), 'fechada na virada: virou OC (ES_31)'), 255)
 WHERE rep_code = 'R095006' AND vigente_desde < '2026-11-01' AND vigente_ate IS NULL;

-- Conferência
SELECT c.rep_code, c.funcao, c.nivel, CONCAT('U', n.unidade, ' ', n.turno, n.numero) AS nucleo,
       (SELECT COUNT(*) FROM esc_vaga_equipe v WHERE v.rep_code = c.rep_code AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-01')) AS vagas,
       CASE c.rep_code WHEN 'M137535' THEN 'U2 C2 · 1 vaga' WHEN 'M060354' THEN 'U3 A1 · 2 vagas' WHEN 'C099427' THEN 'U3 C2 (OS) · 0 vaga' END AS esperado
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.rep_code IN ('M137535', 'M060354', 'C099427') AND c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL;
SELECT 'vagas fixas abertas do Raphael (OC)' AS item, COUNT(*) AS tem, '0' AS esperado FROM esc_vaga_equipe WHERE rep_code = 'R095006' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01')
UNION ALL SELECT 'OS com vaga fixa aberta', COUNT(DISTINCT v.rep_code), '0' FROM esc_vaga_equipe v JOIN esc_colaborador_config c ON c.rep_code = v.rep_code AND c.funcao = 'DECURIO' AND c.vigente_ate IS NULL WHERE v.vigente_ate IS NULL
UNION ALL SELECT 'configurações antigas ainda abertas', COUNT(*), '0' FROM esc_colaborador_config WHERE vigente_desde < '2026-11-01' AND vigente_ate IS NULL;
