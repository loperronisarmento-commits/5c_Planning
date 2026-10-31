-- =====================================================================
-- ES_26_pedidos_ausencia_formulario.sql  (29/10)
-- Pedidos de ausência a partir das observações do formulário de preferência
-- (28–29/10): folga fixa num dia da semana vira pedido RECORRENTE e datas
-- pontuais viram pedido com data. Todos PENDENTE, para avaliar (e pedir
-- comprovação) em Indisponibilidade › Pedidos e aprovação. Recorrentes até 31/12.
-- O turno de cada data já é o de depois do ES_25 (rode o ES_25 antes).
-- Pode rodar de novo: apaga e recria só os pedidos PENDENTE deste formulário.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DELETE FROM esc_indisponibilidade WHERE solicitado_por = 'FORM_28OUT' AND status = 'PENDENTE';

-- G148855 Giorgio Skye Lima Moura · T6/T1 · 9 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('G148855', 'RECORRENTE', 'WFM', 'ESTUDO', '[Formulário 28-29/10] Universidade: aula o dia todo na quarta (pede folga na terça e/ou quarta).', CAST('{"dias_semana": [3], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-04', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-11', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-18', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-25', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-02', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-09', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-16', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-23', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-30', 'D', NULL, 'PENDENTE');
-- I710373 Italo Ramon de Souza Silva · T2/T3 · 17 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('I710373', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Pede folga às quintas e sextas (sem motivo informado: pedir comprovação).', CAST('{"dias_semana": [4, 5], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-05', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-06', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-12', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-13', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-19', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-20', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-26', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-27', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-03', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-04', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-10', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-11', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-17', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-18', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-24', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-25', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-31', 'A', NULL, 'PENDENTE');
-- C051206 Caroline Gonçalves Souza · T2/T3 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('C051206', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Filha de 5 anos com autismo precisa de atenção à noite e aos sábados.', CAST('{"dias_semana": [6], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'A', NULL, 'PENDENTE');
-- L055002 Luca Fortes Sgarbi · T4/T5 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L055002', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Pede o sábado de folga ("just Saturdays").', CAST('{"dias_semana": [6], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'C', NULL, 'PENDENTE');
-- N163267 Nathan da Silva Peres Santos · T6/T1 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('N163267', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Precisa do T6 de sábado de folga.', CAST('{"dias_semana": [6], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'D', NULL, 'PENDENTE');
-- I122095 Iago Nigri Saldanha · T6/T1 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('I122095', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Guarda compartilhada do filho: precisa de todo domingo de folga.', CAST('{"dias_semana": [7], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-08', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-15', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-22', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-29', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-06', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-13', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-20', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-27', 'D', NULL, 'PENDENTE');
-- T321412 Talita Carolina Borges · T6/T1 · 9 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('T321412', 'RECORRENTE', 'WFM', 'SAUDE', '[Formulário 28-29/10] Toda segunda de folga pelo acompanhamento médico da filha. Tem emprego CLT de segunda a quinta em horário comercial (por isso só T6/T1 nesses dias).', CAST('{"dias_semana": [1], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-02', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-09', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-16', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-23', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-30', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-07', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-14', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-21', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-28', 'D', NULL, 'PENDENTE');
-- L704704 Lucas Wallace Silva Ribeiro · T4/T5 · 16 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L704704', 'RECORRENTE', 'WFM', 'SAUDE', '[Formulário 28-29/10] Cuida da avó de 87 anos com câncer (único responsável): pede escala de segunda a quinta ou de segunda a sexta.', CAST('{"dias_semana": [6, 7], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-08', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-15', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-22', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-29', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-06', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-13', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-20', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-27', 'C', NULL, 'PENDENTE');
-- A099370 Amanda Gonçales Rodrigues Lopes · T4/T5 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('A099370', 'RECORRENTE', 'WFM', 'SAUDE', '[Formulário 28-29/10] Mãe internada em instituição psiquiátrica; o dia de visita é sábado (pede estar lá todo fim de semana).', CAST('{"dias_semana": [6], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'C', NULL, 'PENDENTE');
-- P628774 Pedro Emanuel Sena Fernandes · T6/T1 · 17 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('P628774', 'RECORRENTE', 'WFM', 'ESTUDO', '[Formulário 28-29/10] Aula toda quinta e sexta.', CAST('{"dias_semana": [4, 5], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-05', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-06', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-12', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-13', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-19', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-20', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-26', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-27', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-03', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-04', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-10', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-11', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-17', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-18', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-24', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-25', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-31', 'D', NULL, 'PENDENTE');
-- I468128 Ibrahim Tawffiq Leite Jaques · T2/T3 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('I468128', 'RECORRENTE', 'WFM', 'OUTRO', '[Formulário 28-29/10] Dá aula de esgrima todo sábado no clube (hobby / segundo trabalho).', CAST('{"dias_semana": [6], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'A', NULL, 'PENDENTE');
-- L173493 Leonardo Pereira de Meneses Colão · T6/T1 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L173493', 'RECORRENTE', 'WFM', 'OUTRO', '[Formulário 28-29/10] Trabalha aos sábados num curso de inglês das 8h às 17h: precisa da sexta de folga para descansar.', CAST('{"dias_semana": [5], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-06', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-13', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-20', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-27', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-04', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-11', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-18', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-25', 'D', NULL, 'PENDENTE');
-- M029962 Marina de Souza Silva · T6/T1 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('M029962', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Sextas de folga: leva o filho às consultas e à casa do pai (guarda compartilhada).', CAST('{"dias_semana": [5], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-06', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-13', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-20', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-27', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-04', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-11', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-18', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-25', 'D', NULL, 'PENDENTE');
-- L031271 Luiz Alfredo de Queiroz Souza · T4/T5 · 8 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L031271', 'RECORRENTE', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Domingo é o único dia com a família (mora fora e está voltando ao Brasil).', CAST('{"dias_semana": [7], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-08', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-15', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-22', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-29', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-06', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-13', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-20', 'C', NULL, 'PENDENTE'),
  (@id, '2026-12-27', 'C', NULL, 'PENDENTE');
-- I522137 Isabella Dias Domingues · T2/T3 · 16 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('I522137', 'RECORRENTE', 'WFM', 'SAUDE', '[Formulário 28-29/10] Pai com doença terminal (câncer, 4 infartos); nos fins de semana a mãe tem compromisso religioso e ele fica sozinho.', CAST('{"dias_semana": [6, 7], "frequencia": "semanal", "ate": "2026-12-31"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-08', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-14', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-15', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-21', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-22', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-29', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-05', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-06', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-12', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-13', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-19', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-20', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-26', 'A', NULL, 'PENDENTE'),
  (@id, '2026-12-27', 'A', NULL, 'PENDENTE');
-- L092466 Leiziane Lopes Ruiz · T6/T1 · 10 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L092466', 'RECORRENTE', 'WFM', 'ESTUDO', '[Formulário 28-29/10] Fim da faculdade: aula terça (7:30–17:30) e sexta de manhã; não consegue o T6/T1 de segunda e de quinta (poderia fazer T4/T5 nesses dias). Até o início de dezembro.', CAST('{"dias_semana": [1, 4], "frequencia": "semanal", "ate": "2026-12-05"}' AS JSON), 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-02', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-05', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-09', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-12', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-16', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-19', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-23', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-26', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-30', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-03', 'D', NULL, 'PENDENTE');
-- D008138 Skye Zyon Do Nascimento Dalla Vecchia · T6/T1 · 7 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('D008138', 'VARIOS_DIAS', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Mudança de cidade de 25/11 a 01/12 (já combinado com a liderança anterior).', NULL, 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-25', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-26', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-27', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-28', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-29', 'D', NULL, 'PENDENTE'),
  (@id, '2026-11-30', 'D', NULL, 'PENDENTE'),
  (@id, '2026-12-01', 'D', NULL, 'PENDENTE');
-- J102994 Joao Pedro Caetano da Silva Santos · T4/T5 · 2 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('J102994', 'VARIOS_DIAS', 'WFM', 'PESSOAL', '[Formulário 28-29/10] Viagem em 07 e 08/11.', NULL, 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-07', 'C', NULL, 'PENDENTE'),
  (@id, '2026-11-08', 'C', NULL, 'PENDENTE');
-- P065669 Paloma Aline de Queiroz Lopes · T2/T3 · 2 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('P065669', 'VARIOS_DIAS', 'WFM', 'PESSOAL', '[Formulário 28-29/10] 04/11: acompanha familiar em cirurgia; 05/11: volta de viagem (manda comprovante). Corrigiu a primeira resposta (02/11).', NULL, 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-04', 'A', NULL, 'PENDENTE'),
  (@id, '2026-11-05', 'A', NULL, 'PENDENTE');
-- L047261 Luiza Fialho Moscal · T2/T3 · 1 data(s)
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_por) VALUES ('L047261', 'UM_DIA', 'WFM', 'SAUDE', '[Formulário 28-29/10] Consulta médica em 03/11 às 12h30 (manda o comprovante depois da consulta).', NULL, 0, 'PENDENTE', 'FORM_28OUT');
SET @id = LAST_INSERT_ID();
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status) VALUES
  (@id, '2026-11-03', 'A', 'T3', 'PENDENTE');

-- Conferência
SELECT 'pedidos do formulário' AS item, COUNT(*) AS tem, '20' AS esperado FROM esc_indisponibilidade WHERE solicitado_por = 'FORM_28OUT'
UNION ALL SELECT 'datas', COUNT(*), '178' FROM esc_indisponibilidade_data d JOIN esc_indisponibilidade i ON i.id = d.indisponibilidade_id WHERE i.solicitado_por = 'FORM_28OUT';
