SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_31_os_u2_turno_a_0311.sql  (03/11, aplicado pelo Claude)
-- Espelho do ES_84 no fc_estrutura_lider (a Nora lê esta tabela).
-- U2 turno A (OC Ricardo Cunico): Julio Candia entra como OS no lugar do João Paulo Caial,
-- que vira SR. Kodama e Caetano continuam (a tabela não guarda o número do núcleo).
-- As linhas valem desde 03/11 (script 30), então a troca é no lugar.
-- =====================================================================

USE `5c_planning`;

UPDATE fc_estrutura_lider
   SET code = 'J621606', nome = 'Julio Eduardo Candia Antezana'
 WHERE code = 'J110041' AND nivel = 'OS' AND unidade = 2 AND turno = 'A' AND vigente_desde = '2026-11-03' AND vigente_ate IS NULL;

-- Conferência
SELECT nome, code, nivel, unidade, turno, oc, vigente_desde FROM fc_estrutura_lider
 WHERE unidade = 2 AND turno = 'A' AND vigente_ate IS NULL ORDER BY nivel, nome;
