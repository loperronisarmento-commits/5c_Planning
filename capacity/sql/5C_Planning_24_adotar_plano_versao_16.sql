-- =====================================================================
-- 5C | Forecast de WFM — 24. Adotar o plano de turmas sugerido da versão 16 (capacity de 31/10 com as regras do 23)
-- Schema: 5c_planning        31/10/2026        Rodar depois do 23.
--
-- Decisão de 31/10: as turmas planejadas do plano oficial (fc_contratacoes) passam a ser as do plano sugerido da
-- versão 16 (precisa 315: estrutura do Planning tool de 30/10, regra de faturamento, zOFTV sem escala e alívio da
-- Mass), com teto de 10 por turno. São 235 pessoas nas 14 turmas planejadas, em vez de 208.
-- Com as 208 de antes, a falta não fechava até o fim da projeção; com as 235, fecha em 15/03/2027.
-- O calendário não muda. As confirmadas (02/11 e 16/11) ficam como estão.
-- Para adotar outra versão, troque o número em @v (a versão aparece na vw_fc_13_capacity_versoes).
-- Pode rodar mais de uma vez: o resultado é o mesmo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

SET @v = 16;

-- 1. Cópia de segurança (só na primeira vez)
CREATE TABLE IF NOT EXISTS fc_contratacoes_bkp_plano_24 AS SELECT * FROM fc_contratacoes;

-- 2. Troca das turmas planejadas pelas do plano sugerido
START TRANSACTION;

DELETE FROM fc_contratacoes
 WHERE status = 'PLANEJADA'
   AND turma IN (SELECT turma FROM fc_plano_sugerido WHERE versao = @v AND planejada = 1);

INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
SELECT s.turma, s.data_inicio_trn, s.data_inicio_operacao, s.turno, s.qtd_sugerida, 'PLANEJADA',
       CONCAT('Plano sugerido da versão ', @v, ' (precisa ', v.precisa, '), teto 10 por turno, adotado em 31/10')
FROM fc_plano_sugerido s
JOIN fc_capacity_versao v ON v.versao = s.versao
WHERE s.versao = @v AND s.planejada = 1;   -- turno com 0 fica com linha 0: o calendário da turma não se perde

COMMIT;

-- 3. Previsão de turnover com o plano novo e comparação da versão (oficial = sugerido, sem alerta de plano)
CALL sp_fc_prev_to(CURDATE());
CALL sp_fc_plano_sugerido(@v);
UPDATE fc_capacity_versao
   SET alerta = 0,
       motivo = LEFT(CONCAT_WS(' · ', motivo, 'Plano sugerido adotado como oficial (script 24)'), 2000)
 WHERE versao = @v;

-- ---------- Conferência ----------
-- 4.1 Turmas: oficial igual ao sugerido, nenhum turno acima de 10 (todas 'ok').
--     Esperado A/C/D: 07/12 10/10/10 · 11/01 10/10/10 · 25/01 10/10/10 · 08/02 4/10/10 · 22/02 2/10/10 ·
--     08/03 2/7/10 · 22/03 2/4/4 · 05/04 2/4/4 · 19/04 2/4/5 · 03/05 2/4/4 · 17/05 2/4/4 · 31/05 2/3/4 ·
--     14/06 2/4/4 · 28/06 2/4/4
SELECT `Turma`, `A oficial`, `C oficial`, `D oficial`, `Total oficial`, `Total sugerido`,
       IF(`Total oficial` = `Total sugerido` AND GREATEST(`A oficial`, `C oficial`, `D oficial`) <= 10, 'ok', 'DIFERENTE') AS `Confere`
FROM vw_fc_16_plano_oficial_vs_sugerido;

-- 4.2 Totais: esperado CONFIRMADA 35 (2 turmas) · PLANEJADA 235 (14 turmas) · total 270
SELECT status AS `Status`, COUNT(DISTINCT turma) AS `Turmas`, SUM(qtd) AS `Pessoas`
FROM fc_contratacoes WHERE status <> 'CANCELADA'
GROUP BY status WITH ROLLUP;

-- 4.3 Versão 16: oficial 235, sugerido 235, diferença 0, falta fecha em 15/03/2027
SELECT versao AS `Versão`, precisa AS `Precisa`, contratar_oficial AS `Planejadas (oficial)`,
       contratar_sugerido AS `Planejadas (sugerido)`, dif_plano AS `Diferença`,
       fecha_oficial AS `Fecha (oficial)`, fecha_sugerido AS `Fecha (sugerido)`, alerta AS `Alerta`
FROM fc_capacity_versao WHERE versao = @v;

-- 4.4 Quadro projetado em 01/03/2027 (esperado 102,79 / 99,58 / 97,58)
SELECT turno AS `Turno`, hc_oficial AS `Quadro`
FROM fc_plano_projecao WHERE versao = @v AND inicio_semana = '2027-03-01'
ORDER BY FIELD(turno, 'A', 'C', 'D');

-- ---------------------------------------------------------------------
-- 5. Desfazer (só se precisar): volta as turmas planejadas de antes do 24 (208)
-- ---------------------------------------------------------------------
-- START TRANSACTION;
-- DELETE FROM fc_contratacoes WHERE status = 'PLANEJADA';
-- INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs)
-- SELECT turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs
-- FROM fc_contratacoes_bkp_plano_24 WHERE status = 'PLANEJADA';
-- COMMIT;
-- CALL sp_fc_rodar_dia();
