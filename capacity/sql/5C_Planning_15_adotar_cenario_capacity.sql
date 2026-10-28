-- =====================================================================
-- 5C | Forecast de WFM — 15. Adotar o cenário CAPACITY como plano oficial de contratação
-- Schema: 5c_planning        Decisão de 28/10/2026        Rodar depois do 14.
--
-- Troca as turmas PLANEJADAS a partir de 07/12 em fc_contratacoes (o "Plano de 26/10") pelas
-- turmas do cenário CAPACITY simulado em 27/10 (alvo de 327: titulares + buffer por turno).
-- As turmas de 02/11 e 16/11 (CONFIRMADAS) não mudam.
--
-- Antes de mexer, copia fc_contratacoes para fc_contratacoes_bkp_20261028.
-- Para desfazer: bloco 4 (comentado).
--
-- Esperado depois de rodar:
--   turmas de 07/12/2026 a 28/06/2027, a cada 14 dias a partir de 11/01; 315 pessoas no total do cenário
--   (35 já confirmadas em 02/11 e 16/11 + 280 planejadas); a falta fica abaixo de 5 no fim de junho de 2027.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------- 1. Cópia de segurança (só na primeira vez) ----------
CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_20261028 AS SELECT * FROM fc_contratacoes;

-- ---------- 2. Troca das turmas planejadas ----------
START TRANSACTION;

DELETE FROM fc_contratacoes
WHERE turma >= '2026-12-07' AND status = 'PLANEJADA';

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT c.turma, c.data_inicio_trn, c.data_inicio_operacao, c.turno, c.qtd, c.status,
       'Cenário CAPACITY de 27/10 (adotado em 28/10)'
FROM fc_contratacoes_cenario c
WHERE c.cenario = 'CAPACITY' AND c.turma >= '2026-12-07'
  AND c.simulado_em = (SELECT MAX(simulado_em) FROM fc_contratacoes_cenario)
  AND NOT EXISTS (SELECT 1 FROM fc_contratacoes o WHERE o.turma = c.turma AND o.turno = c.turno);

COMMIT;

-- ---------- 3. Recalcular a previsão com o plano novo ----------
CALL sp_fc_rodar_dia();

-- ---------- Conferência ----------
-- 3.1 A última execução tem que estar 'OK' na etapa 'fim'
SELECT data_calculo, etapa, inicio, fim, status, mensagem
FROM fc_log_execucao ORDER BY id DESC LIMIT 1;

-- 3.2 Plano oficial × cenário: todas as linhas 'ok'
SELECT x.turma AS `Turma`, x.turno AS `Turno`, x.oficial AS `Oficial`, x.cenario AS `Cenário`,
       IF(x.oficial = x.cenario, 'ok', 'DIFERENTE') AS `Confere`
FROM (
  SELECT c.turma, c.turno, IFNULL(o.qtd, 0) AS oficial, c.qtd AS cenario
  FROM fc_contratacoes_cenario c
  LEFT JOIN fc_contratacoes o ON o.turma = c.turma AND o.turno = c.turno AND o.status <> 'CANCELADA'
  WHERE c.cenario = 'CAPACITY'
    AND c.simulado_em = (SELECT MAX(simulado_em) FROM fc_contratacoes_cenario)
) x
ORDER BY x.turma, x.turno;

-- 3.3 Totais: esperado 315 no oficial (35 confirmadas + 280 planejadas)
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- ---------------------------------------------------------------------
-- 4. Desfazer (só se precisar): volta fc_contratacoes como estava antes do 15
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes;
-- INSERT INTO fc_contratacoes SELECT * FROM fc_contratacoes_bkp_20261028;
-- COMMIT;
-- CALL sp_fc_rodar_dia();
