-- =====================================================================
-- 5C | Forecast de WFM — 06. Turma de 31/12 passa para 05/01/2027 (19/10/2026)
-- Rodar só se o arquivo 05 já foi executado antes desta mudança.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

DELETE FROM fc_contratacoes WHERE turma IN ('2026-12-31','2027-01-05');
INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs) VALUES
 ('2027-01-05','2027-01-05','2027-01-19','A',5,'PLANEJADA','Plano de 19/10 (31/12 não é possível; TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','C',8,'PLANEJADA','Plano de 19/10 (31/12 não é possível; TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','D',7,'PLANEJADA','Plano de 19/10 (31/12 não é possível; TRN ocupado até 31/12)');

CALL sp_fc_rodar_dia();

SELECT turma, turno, qtd, status, data_inicio_operacao FROM fc_contratacoes ORDER BY turma, turno;
