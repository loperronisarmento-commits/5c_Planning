-- =====================================================================
-- 5C | Forecast de WFM — 17. Turma de 02/11/2026 com o número real de contratados
-- Schema: 5c_planning        28/10/2026
--
-- Informado pelo usuário em 28/10: "2/11 já foram contratados 4A | 7C | 4D".
-- O banco tinha 02/11 = C 9 · D 6, sem turno A. Este script corrige só a turma de 02/11:
--   A: linha nova com 4 · C: 9 → 7 · D: 6 → 4  (status CONFIRMADA, opera em 16/11)
-- Não mexe na turma de 16/11 nem nas planejadas a partir de 07/12.
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Cópia de segurança (só na primeira vez)
CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_turma0211 AS SELECT * FROM fc_contratacoes;

-- 2. Correção
START TRANSACTION;

UPDATE fc_contratacoes SET qtd = 7, obs = 'Contratados: 4A | 7C | 4D (informado em 28/10)'
WHERE turma = '2026-11-02' AND turno = 'C';

UPDATE fc_contratacoes SET qtd = 4, obs = 'Contratados: 4A | 7C | 4D (informado em 28/10)'
WHERE turma = '2026-11-02' AND turno = 'D';

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT '2026-11-02', '2026-11-02', '2026-11-16', 'A', 4, 'CONFIRMADA', 'Contratados: 4A | 7C | 4D (informado em 28/10)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM fc_contratacoes WHERE turma = '2026-11-02' AND turno = 'A');

UPDATE fc_contratacoes SET qtd = 4
WHERE turma = '2026-11-02' AND turno = 'A';

COMMIT;

-- 3. Recalcular a previsão
CALL sp_fc_rodar_dia();

-- ---------- Conferência ----------
-- 3.1 Turma de 02/11: esperado A 4 · C 7 · D 4 = 15 (todas 'ok')
SELECT c.turno AS `Turno`, c.qtd AS `No banco`, e.qtd AS `Esperado`, c.status AS `Status`,
       c.data_inicio_operacao AS `Opera em`, IF(c.qtd = e.qtd, 'ok', 'DIFERENTE') AS `Confere`
FROM fc_contratacoes c
JOIN (SELECT 'A' AS t, 4 AS qtd UNION ALL SELECT 'C', 7 UNION ALL SELECT 'D', 4) e ON e.t = c.turno
WHERE c.turma = '2026-11-02'
ORDER BY c.turno;

-- 3.2 Totais do plano: não mudam (a turma de 02/11 continua com 15; muda só a divisão por turno)
--     esperado CONFIRMADA 35 (15 + 20) · PLANEJADA 280 · total 315
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 3.3 A última execução tem que estar 'OK' na etapa 'fim'
SELECT data_calculo, etapa, inicio, fim, status, mensagem
FROM fc_log_execucao ORDER BY id DESC LIMIT 1;

-- ---------------------------------------------------------------------
-- 4. Desfazer (só se precisar): volta a turma de 02/11 como estava antes do 17
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes WHERE turma = '2026-11-02';
-- INSERT INTO fc_contratacoes SELECT * FROM fc_contratacoes_bkp_turma0211 WHERE turma = '2026-11-02';
-- COMMIT;
-- CALL sp_fc_rodar_dia();
