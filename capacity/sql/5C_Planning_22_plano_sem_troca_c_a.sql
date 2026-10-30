-- =====================================================================
-- 5C | Forecast de WFM — 22. Plano oficial sem a troca C → A do script 21
-- Schema: 5c_planning        30/10/2026        Rodar depois do 21.
--
-- Correção de 30/10: os 5 reps que passaram do C para o A já estão no cadastro da estrutura
-- (fc_estrutura_rep: A 76 · C 69 · D 75 é o valor correto, confirmado pelo usuário). O 21 somou a troca
-- de novo no plano. Este script desliga a troca, refaz o plano sugerido da última versão (teto de 10 por
-- turno continua) e adota como oficial.
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- 1. Desligar a troca (a linha fica guardada)
UPDATE fc_plano_troca_turno
   SET ativo = 0, obs = 'Script 21: 5 reps do C para o A (desligada no 22: já estava no cadastro)'
 WHERE obs LIKE 'Script 21: 5 reps do C para o A%';

-- 2. Plano sugerido da última versão sem a troca, adotado como oficial
SET @v = (SELECT MAX(versao) FROM fc_capacity_versao);
CALL sp_fc_plano_sugerido(@v);

CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_plano_22 AS SELECT * FROM fc_contratacoes;

START TRANSACTION;

DELETE FROM fc_contratacoes
 WHERE status = 'PLANEJADA'
   AND turma IN (SELECT turma FROM fc_plano_sugerido WHERE versao = @v AND planejada = 1);

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT s.turma, s.data_inicio_trn, s.data_inicio_operacao, s.turno, s.qtd_sugerida, 'PLANEJADA',
       CONCAT('Plano sugerido da versão ', @v, ' (precisa ', v.precisa, '), teto 10 por turno, adotado em 30/10')
FROM fc_plano_sugerido s
JOIN fc_capacity_versao v ON v.versao = s.versao
WHERE s.versao = @v AND s.planejada = 1;   -- turno com 0 fica com linha 0: o calendário da turma não se perde

COMMIT;

-- previsão de turnover com o plano novo e comparação da versão (oficial = sugerido, sem alerta)
CALL sp_fc_prev_to(CURDATE());
CALL sp_fc_plano_sugerido(@v);
UPDATE fc_capacity_versao
   SET alerta = 0,
       motivo = LEFT(CONCAT_WS(' · ', motivo, 'Troca C→A desligada (já estava no cadastro), plano readotado (script 22)'), 2000)
 WHERE versao = @v;

-- ---------- Conferência (rodando em 30/10, versão 5) ----------
-- 3.1 Troca desligada: esperado ativo = 0
SELECT data, turno_de, turno_para, qtd, ativo, obs FROM fc_plano_troca_turno;

-- 3.2 Turmas: oficial igual ao sugerido, nenhum turno acima de 10 (todas 'ok').
--     Esperado A/C/D: 07/12 10/10/10 · 11/01 10/10/10 · 25/01 4/10/10 · 08/02 2/10/10 · 22/02 2/8/8 · 08/03 2/3/4 ·
--     22/03 2/4/3 · 05/04 2/4/4 · 19/04 2/4/4 · 03/05 1/3/4 · 17/05 2/4/4 · 31/05 2/4/4 · 14/06 2/4/4 · 28/06 2/3/3
SELECT `Turma`, `A oficial`, `C oficial`, `D oficial`, `Total oficial`, `Total sugerido`,
       IF(`Total oficial` = `Total sugerido` AND GREATEST(`A oficial`, `C oficial`, `D oficial`) <= 10, 'ok', 'DIFERENTE') AS `Confere`
FROM vw_fc_16_plano_oficial_vs_sugerido;

-- 3.3 Totais: esperado CONFIRMADA 35 (2 turmas) · PLANEJADA 208 (14 turmas) · total 243
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 3.4 Versão: esperado versão 5, precisa 293, oficial 208, sugerido 208, diferença 0, falta fecha em 01/03/2027
SELECT `Versão`, `Precisa`, `Planejadas (oficial)`, `Planejadas (sugerido)`, `Diferença`,
       `Fecha (oficial)`, `Fecha (sugerido)`, `Alerta`, `O que mudou`
FROM vw_fc_13_capacity_versoes LIMIT 1;

-- 3.5 Quadro projetado em 01/03/2027 (esperado 95,15 / 98,59 / 97,58)
SELECT turno AS `Turno`, hc_oficial AS `Quadro`
FROM fc_plano_projecao WHERE versao = @v AND inicio_semana = '2027-03-01'
ORDER BY FIELD(turno, 'A', 'C', 'D');

-- ---------------------------------------------------------------------
-- 4. Desfazer (só se precisar): volta o plano do script 21, com a troca
-- ---------------------------------------------------------------------
-- UPDATE fc_plano_troca_turno SET ativo = 1 WHERE obs LIKE 'Script 21: 5 reps do C para o A%';
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes WHERE status = 'PLANEJADA';
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
-- SELECT turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
-- FROM fc_contratacoes_bkp_plano_22 WHERE status = 'PLANEJADA';
-- COMMIT;
-- CALL sp_fc_rodar_dia();
