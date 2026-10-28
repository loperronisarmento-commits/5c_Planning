-- =====================================================================
-- 5C | Forecast de WFM — 09. Calendário de turmas (26/10/2026)
-- Feitas: 02/11 C9 D6 · 16/11 A5 C9 D6
-- Plano:  07/12 A2 C10 D8 · 11/01 A3 C10 D7 · 25/01 A3 C9 D8 · 08/02 A4 C9 D7 · 22/02 A4 C7 D6
-- Substitui as turmas dos scripts 05, 07 e 08. Rodar uma vez no 5c_planning.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

DELETE FROM fc_contratacoes WHERE turma >= '2026-11-02';
INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs) VALUES
 ('2026-11-02','2026-11-02','2026-11-16','C', 9,'CONFIRMADA','Pedido feito'),
 ('2026-11-02','2026-11-02','2026-11-16','D', 6,'CONFIRMADA','Pedido feito'),
 ('2026-11-16','2026-11-16','2026-11-30','A', 5,'CONFIRMADA','Pedido feito'),
 ('2026-11-16','2026-11-16','2026-11-30','C', 9,'CONFIRMADA','Pedido feito'),
 ('2026-11-16','2026-11-16','2026-11-30','D', 6,'CONFIRMADA','Pedido feito'),
 ('2026-12-07','2026-12-07','2026-12-21','A', 2,'PLANEJADA','Plano de 26/10'),
 ('2026-12-07','2026-12-07','2026-12-21','C',10,'PLANEJADA','Plano de 26/10'),
 ('2026-12-07','2026-12-07','2026-12-21','D', 8,'PLANEJADA','Plano de 26/10'),
 ('2027-01-11','2027-01-11','2027-01-25','A', 3,'PLANEJADA','Plano de 26/10'),
 ('2027-01-11','2027-01-11','2027-01-25','C',10,'PLANEJADA','Plano de 26/10'),
 ('2027-01-11','2027-01-11','2027-01-25','D', 7,'PLANEJADA','Plano de 26/10'),
 ('2027-01-25','2027-01-25','2027-02-08','A', 3,'PLANEJADA','Plano de 26/10'),
 ('2027-01-25','2027-01-25','2027-02-08','C', 9,'PLANEJADA','Plano de 26/10'),
 ('2027-01-25','2027-01-25','2027-02-08','D', 8,'PLANEJADA','Plano de 26/10'),
 ('2027-02-08','2027-02-08','2027-02-22','A', 4,'PLANEJADA','Plano de 26/10'),
 ('2027-02-08','2027-02-08','2027-02-22','C', 9,'PLANEJADA','Plano de 26/10'),
 ('2027-02-08','2027-02-08','2027-02-22','D', 7,'PLANEJADA','Plano de 26/10'),
 ('2027-02-22','2027-02-22','2027-03-08','A', 4,'PLANEJADA','Plano de 26/10'),
 ('2027-02-22','2027-02-22','2027-03-08','C', 7,'PLANEJADA','Plano de 26/10'),
 ('2027-02-22','2027-02-22','2027-03-08','D', 6,'PLANEJADA','Plano de 26/10');

-- Recalcula a previsão com as turmas novas
CALL sp_fc_rodar_dia();

-- Conferência
SELECT turma AS `Turma`, data_inicio_operacao AS `Começa a operar`,
       SUM(IF(turno='A',qtd,0)) AS `A`, SUM(IF(turno='C',qtd,0)) AS `C`, SUM(IF(turno='D',qtd,0)) AS `D`,
       SUM(qtd) AS `Total`, MAX(status) AS `Status`
FROM fc_contratacoes GROUP BY turma, data_inicio_operacao ORDER BY turma;
