-- =====================================================================
-- 5C | Forecast de WFM — 20. Adotar o plano de turmas sugerido da versão 4 (capacity de 30/10)
-- Schema: 5c_planning        30/10/2026        Rodar depois do 19.
--
-- Decisão de 30/10: as turmas planejadas do plano oficial (fc_contratacoes) passam a ser as do plano
-- sugerido da versão 4 do capacity (precisa 291, sem a Skylar e a Rebecca OFTV): 202 pessoas em vez de 280.
-- O calendário de turmas não muda (07/12 e de 11/01 a 28/06, a cada 14 dias). As confirmadas
-- (02/11 e 16/11) ficam como estão. Até 08/03 as turmas continuam com 20; de 22/03 em diante, 8 a 17.
-- Depois recalcula o dia: a previsão de turnover passa a usar o plano novo e o capacity grava uma versão
-- nova de 30/10, já sem diferença entre o oficial e o sugerido.
-- Para adotar outra versão, troque o número em @v (a versão aparece na vw_fc_13_capacity_versoes).
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

SET @v = 4;

-- 1. Cópia de segurança (só na primeira vez)
CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_plano_v4 AS SELECT * FROM fc_contratacoes;

-- 2. Troca das turmas planejadas pelas do plano sugerido
START TRANSACTION;

DELETE FROM fc_contratacoes
 WHERE status = 'PLANEJADA'
   AND turma IN (SELECT turma FROM fc_plano_sugerido WHERE versao = @v AND planejada = 1);

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT s.turma, s.data_inicio_trn, s.data_inicio_operacao, s.turno, s.qtd_sugerida, 'PLANEJADA',
       CONCAT('Plano sugerido da versão ', @v, ' do capacity (precisa ', v.precisa, '), adotado em 30/10')
FROM fc_plano_sugerido s
JOIN fc_capacity_versao v ON v.versao = s.versao
WHERE s.versao = @v AND s.planejada = 1 AND s.qtd_sugerida > 0;

COMMIT;

-- 3. Recalcular o dia (previsão de turnover, capacity e versão nova)
CALL sp_fc_rodar_dia();

-- ---------- Conferência ----------
-- 3.1 Turma a turma: o oficial igual ao sugerido da versão 4 (todas 'ok')
--     Esperado A/C/D: 07/12 5/8/7 · 11/01 4/8/8 · 25/01 5/8/7 · 08/02 4/8/8 · 22/02 5/8/7 · 08/03 4/8/8 ·
--     22/03 4/6/7 · 05/04 2/4/3 · 19/04 2/3/4 · 03/05 1/4/4 · 17/05 2/4/4 · 31/05 2/4/4 · 14/06 2/3/3 · 28/06 2/4/4
SELECT s.turma AS `Turma`,
       SUM(IF(s.turno = 'A', c.qtd, 0)) AS `A`, SUM(IF(s.turno = 'C', c.qtd, 0)) AS `C`, SUM(IF(s.turno = 'D', c.qtd, 0)) AS `D`,
       SUM(COALESCE(c.qtd, 0)) AS `No oficial`, SUM(s.qtd_sugerida) AS `Sugerido v4`,
       IF(SUM(COALESCE(c.qtd, 0)) = SUM(s.qtd_sugerida) AND SUM(c.status = 'PLANEJADA') = COUNT(c.id), 'ok', 'DIFERENTE') AS `Confere`
FROM fc_plano_sugerido s
LEFT JOIN fc_contratacoes c ON c.turma = s.turma AND c.turno = s.turno AND c.status <> 'CANCELADA'
WHERE s.versao = @v AND s.planejada = 1
GROUP BY s.turma
ORDER BY s.turma;

-- 3.2 Totais: esperado CONFIRMADA 35 (2 turmas) · PLANEJADA 202 (14 turmas) · total 237
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 3.3 A última execução tem que estar 'OK' na etapa 'fim'
SELECT data_calculo, etapa, inicio, fim, status, mensagem
FROM fc_log_execucao ORDER BY id DESC LIMIT 1;

-- 3.4 Versão nova de 30/10: oficial 202, sugerido 202, diferença 0 e sem alerta
--     (se o cadastro de saídas mudou hoje, o turnover muda e o sugerido pode sair 1 ou 2 diferente)
SELECT `Versão`, `Data do cálculo`, `Precisa`, `Planejadas (oficial)`, `Planejadas (sugerido)`, `Diferença`,
       `Fecha (oficial)`, `Alerta`, `O que mudou`
FROM vw_fc_13_capacity_versoes LIMIT 1;

-- ---------------------------------------------------------------------
-- 4. Desfazer (só se precisar): volta as turmas planejadas de antes do 20
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes WHERE status = 'PLANEJADA';
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
-- SELECT turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
-- FROM fc_contratacoes_bkp_plano_v4 WHERE status = 'PLANEJADA';
-- COMMIT;
-- CALL sp_fc_rodar_dia();
