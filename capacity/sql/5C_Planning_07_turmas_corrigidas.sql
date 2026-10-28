-- =====================================================================
-- 5C | Forecast de WFM — 07. Turmas corrigidas (19/10/2026, 18h)
-- 02/11 = 9 C + 6 D (pedido feito). Plano: 19/11 A2 C11 D7 · 03/12 A3 C10 D7 · 17/12 A3 C9 D8 · 05/01 A4 C9 D7.
-- Substitui os scripts 06 e as turmas do 05. Pode rodar em qualquer ordem depois do 05.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

DELETE FROM fc_contratacoes WHERE turma >= '2026-11-02';
INSERT INTO fc_contratacoes (turma, data_inicio_trn, data_inicio_operacao, turno, qtd, status, obs) VALUES
 ('2026-11-02','2026-11-02','2026-11-16','C',9,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-02','2026-11-02','2026-11-16','D',6,'CONFIRMADA','Pedido feito (corrigido em 19/10: 9 C + 6 D em 02/11)'),
 ('2026-11-19','2026-11-19','2026-12-03','A',2,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','C',11,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-11-19','2026-11-19','2026-12-03','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','C',10,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-03','2026-12-03','2026-12-17','D',7,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','A',3,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','C',9,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2026-12-17','2026-12-17','2026-12-31','D',8,'PLANEJADA','Plano de 19/10 (limite 35, até 3 páginas, média do bloco)'),
 ('2027-01-05','2027-01-05','2027-01-19','A',4,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','C',9,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)'),
 ('2027-01-05','2027-01-05','2027-01-19','D',7,'PLANEJADA','Plano de 19/10 (TRN ocupado até 31/12)');

CALL sp_fc_rodar_dia();

SELECT turma, turno, qtd, status, data_inicio_operacao FROM fc_contratacoes ORDER BY turma, turno;
