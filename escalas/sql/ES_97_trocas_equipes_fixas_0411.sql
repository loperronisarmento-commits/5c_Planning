SET NAMES utf8mb4;
-- =====================================================================
-- ES_97_trocas_equipes_fixas_0411.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11, valendo "já, no próximo turno" (turno D de 04/11):
--
-- 1. Desligados, fora a partir de 04/11 (cadastro e vagas fecham em 03/11):
--      W154869 Wallace de Oliveira Silva Rosa   U1 A n3 (config 533)
--      H185970 Hélio Guerra Souza Dos Santos    U1 C n3 (config 553)
--      E123445 Esley Michael N. de C. Silva     U2 A n1 (config 674; sai da equipe da Molly Stewart VIP)
--
-- 2. Trocas de unidade (mesmo turno, núcleo de mesmo número e nível; a partir de 04/11):
--      Com o Keller (U3 ↔ U2):
--        J058769 Jonas Amado        U3 C n1 → U2 C n1   ×  P098140 Paulo Demarchi   U2 C n1 → U3 C n1
--        L389704 Lucas Moraes       U3 D n1 → U2 D n1   ×  A070935 André Salloum    U2 D n3 → U3 D n3
--          (o Lucas sai da equipe fixa da Liv)
--        I522137 Isabella Dias      U3 A n1 → U2 A n1   ×  P065669 Paloma Lopes     U2 A n3 → U3 A n3
--      U3 ↔ U1:
--        J066864 Jonnas Drumond     U3 C n3 → U1 C n3   ×  H109391 Helenne Antoun   U1 A n2 → U3 A n2
--        (I801746 Ignacio Canelo já está na U1 C)       ×  A041809 Augusto Lima     U1 C n2 → U3 C n2
--        P611260 Pedro Tavares      U3 C n2 → U1 C n2   ×  L055002 Luca Sgarbi      U1 C n2 → U3 C n2
--      Com a Vic (U1 ↔ U2):
--        I122095 Iago Saldanha      U1 D n1 → U2 D n1   ×  J145594 João Beber       U2 D n1 → U1 D n2 (a de 09/11 vem para 04/11)
--        I710373 Ítalo Ramon        U1 A n1 → U2 A n1   ×  D014864 Diego Gosteinski U2 A n3 → U1 A n3
--        B163148 Breno Moura        U2 C n1 → U1 C n1   ×  F156592 Fernanda Souza   U1 C n1 → U2 C n1
--        C144167 Chris Dominy       U1 A n3 → U2 A n2   ×  A051183 Alan Ramsay      U2 A n2 → U1 A n1 (as de 09/11 vêm para 04/11)
--      E510906 Eduardo Gois volta para a U1 A n1 (estava na U3 A n2 pelo ES_80); segue na equipe da Fanvue,
--        que agora só usa quem é de outra unidade em último caso (motor, 1.19.9).
--
-- 3. Inegociáveis da U1 (não saem da U1 nesses turnos): A José Otávio, Cauan Beriba, Alan Ramsay,
--    Iasmim Feijó, Ana Carolina Giacomini, Santiago Pereira; C Jonas Santos, Jonnas Drumond, Mariana
--    Vilela, Luiz Alfredo, Lucas Giusti, Rafaela Gaspar; D João Beber, Koi Adonis, Angeline Butal,
--    Riza Barrera, Brian Kombe. A ida de Cauan (U3 A) e Koi (U3 D) em 09/11 (ES_73) é cancelada.
--
-- 4. Equipes fixas a partir de 04/11 (esc_pagina_prioridade; as antigas da mesma página fecham em 03/11):
--      Madison Moore VIP e Free: André Salloum, Paloma Lopes, Luca Sgarbi
--      Cynthia: Lucas Dalla Corte, Julian Gonçalves, Gabriela Storini
--      Elly Clutch: José Feliciano (já era), Gustavo Deda, Matteo Kellermann
--      Britt VIP: Donerio Neto, Marina Studzinski
--      Mikayla Free e Paid (as duas): A José Otávio, Camila Coelho, Cauan Beriba; C Jonas Santos,
--        Jonnas Drumond, Rafaela Gaspar (a melhor dos três inegociáveis da U1 C: 135% da meta, US$ 76/h,
--        3 anos); D Koi Adonis, Brian Kombe, Angeline Butal
--      Nicole Doshi: Ítalo Ramon, Iago Saldanha, Bruno Fonseca, Fernanda Souza, Marina Silva (M497745), Maria Brandelero
--      Molly Stewart VIP: a mesma equipe sem o Esley
--      Brandi Love e Brandi Love FREE: Isabella Dias, Vinicius Tadewald
--      CJ Miles e CJ VIP: Beatriz (já era), Chris Dominy, Maria Brandelero, Fernanda Sella
--      Marie Dee VIP: Thiago Arantes · Mo Saffari, Matty Caron e Sebi Barraza: Henrique Pasini
--      Holly Jane: Alan Ramsay, João Beber, Stephen (a de 09/11 vem para 04/11, sem Krizel e Angeline)
--      Sisi Rose: Diego Gosteinski, Ignacio Canelo · Natasha Nice VIP: Santiago Pereira, Lucas Giusti
--      Liv: só o Matteo (o Lucas Moraes foi para a U2)
--    Aline Novak (Karolayne) e Midwest Emma (Letícia) já estavam assim.
--
-- 5. André Salloum (A070935) proibido na Brandi Love e na Brandi Love FREE (esc_pagina_assistente, FORA).
--
-- Fica para o motor (1.19.9): Vanderson Garcia 50% Brandi Love / 50% Elly Clutch e Luan Oliveira 50%
-- Elly Clutch / 50% escala normal (esc_sr_divisao, ES_98).
-- Depois: recalcular o Capacity, "Recalcular tudo" na semana de 02/11 a partir do turno D de 04/11 e postar.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- 1. Desligados
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03', observacao = LEFT(CONCAT('Desligamento informado em 04/11 (ES_97); ', IFNULL(observacao, '')), 255)
 WHERE id IN (533, 553, 674) AND UPPER(rep_code) IN ('W154869', 'H185970', 'E123445') AND vigente_ate IS NULL;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Desligamento (ES_97); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) IN ('W154869', 'H185970', 'E123445') AND vigente_desde <= '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04');
-- (acrescentado depois da primeira execução, via MCP) vagas que só começariam em 04/11: data neutra.
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) IN ('W154869', 'H185970', 'E123445') AND vigente_desde >= '2026-11-04';

-- 2. Trocas de unidade: config atual → novo núcleo a partir de 04/11
DROP TEMPORARY TABLE IF EXISTS tmp_es97;
CREATE TEMPORARY TABLE tmp_es97 (rep CHAR(7) PRIMARY KEY, cfg INT NOT NULL, nuc INT NOT NULL);
INSERT INTO tmp_es97 VALUES
  ('J058769', 401, 13), ('P098140', 496, 4), ('L389704', 630, 16), ('A070935', 304, 9), ('I522137', 392, 10), ('P065669', 495, 3),
  ('J066864', 403, 24), ('H109391', 380, 2), ('A041809', 298, 5), ('P611260', 500, 23), ('L055002', 432, 5),
  ('I122095', 590, 16), ('I710373', 592, 10), ('D014864', 341, 21), ('B163148', 319, 22), ('F156592', 356, 13),
  ('E510906', 655, 19);

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-04', NULL,
       LEFT(CONCAT('ES_97: U', c.unidade, ' → U', n.unidade, ' ', n.turno, ' núcleo ', n.numero, ' a partir de 04/11/2026 (troca pedida pelo Planejamento)'), 255), 'CARGA_ESTRUTURA'
  FROM tmp_es97 x JOIN esc_colaborador_config c ON c.id = x.cfg JOIN esc_nucleo n ON n.id = x.nuc
 WHERE c.vigente_ate IS NULL AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-04');
UPDATE esc_colaborador_config c JOIN tmp_es97 x ON x.cfg = c.id SET c.vigente_ate = '2026-11-03' WHERE c.vigente_ate IS NULL;

-- Beber, Chris e Alan: a configuração de 09/11 passa a valer em 04/11.
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03' WHERE id IN (410, 586, 301) AND vigente_ate = '2026-11-08';
UPDATE esc_colaborador_config SET vigente_desde = '2026-11-04', observacao = LEFT(CONCAT('ES_97: antecipada de 09/11 para 04/11; ', IFNULL(observacao, '')), 255)
 WHERE id IN (635, 652, 647) AND vigente_desde = '2026-11-09';

-- Vagas fixas de quem mudou de unidade: as do núcleo antigo fecham em 03/11; as que só começariam
-- depois vão para a data neutra (30/10). O Gerar completa as vagas no destino.
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Troca de unidade (ES_97); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) IN (SELECT rep FROM tmp_es97 UNION ALL SELECT 'J145594' UNION ALL SELECT 'C144167' UNION ALL SELECT 'A051183')
   AND vigente_desde <= '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04');
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) IN (SELECT rep FROM tmp_es97) AND vigente_desde >= '2026-11-04';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-11-04'
 WHERE UPPER(rep_code) IN ('J145594', 'C144167', 'A051183') AND vigente_desde = '2026-11-09';

-- 3. Cauan e Koi ficam na U1: a ida para a U3 em 09/11 é cancelada.
DELETE FROM esc_colaborador_config WHERE id IN (632, 649) AND UPPER(rep_code) IN ('C444210', 'K616148') AND vigente_desde = '2026-11-09';
UPDATE esc_colaborador_config SET vigente_ate = NULL, observacao = LEFT(CONCAT('Inegociável na U1 (04/11, ES_97); ', IFNULL(observacao, '')), 255)
 WHERE id IN (338, 426) AND vigente_ate = '2026-11-08';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) IN ('C444210', 'K616148') AND vigente_desde >= '2026-11-09';
UPDATE esc_vaga_equipe SET vigente_ate = NULL
 WHERE UPPER(rep_code) IN ('C444210', 'K616148') AND vigente_ate = '2026-11-08';
UPDATE esc_colaborador_config SET observacao = LEFT(CONCAT('Inegociável na U1 (04/11, ES_97); ', IFNULL(observacao, '')), 255)
 WHERE vigente_ate IS NULL AND UPPER(rep_code) IN ('J104720', 'A051183', 'I433559', 'A070723', 'S094121', 'J432005', 'J066864', 'M443425',
       'L031271', 'L007270', 'R107827', 'J145594', 'A283671', 'R271485', 'B387230')
   AND IFNULL(observacao, '') NOT LIKE '%Inegociável na U1%';

-- 4. Equipes fixas
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-03'
 WHERE id IN (3, 6, 7, 8, 10, 11, 14, 31) AND vigente_ate IS NULL AND vigente_desde <= '2026-11-03';
UPDATE esc_pagina_prioridade SET rep_codes = 'A051183,J145594,S799456', vigente_desde = '2026-11-04',
       motivo = 'Manager: equipe fixa da Holly Jane (Alan Ramsay, João Beber e Stephen), pedido de 04/11 (ES_97)'
 WHERE id = 25 AND pagina = 'Holly Jane';

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.reps, x.motivo, '2026-11-04', NULL FROM (
  SELECT 'Madison Moore VIP 115m' AS pagina, 'A070935,P065669,L055002' AS reps, 'Manager: André Salloum, Paloma Lopes e Luca Sgarbi (ES_97)' AS motivo
  UNION ALL SELECT 'Madison Moore Free 115s', 'A070935,P065669,L055002', 'Manager: André Salloum, Paloma Lopes e Luca Sgarbi (ES_97)'
  UNION ALL SELECT 'Cynthia', 'L086668,J058241,G092815', 'Manager: Lucas Dalla Corte, Julian Gonçalves e Gabriela Storini (ES_97)'
  UNION ALL SELECT 'Elly Clutch 100m', 'J337675,G147453,M036514', 'Manager: José Feliciano, Gustavo Deda e Matteo Kellermann (ES_97)'
  UNION ALL SELECT 'Britt VIP', 'D103456,M059686', 'Manager: Donerio Neto e Marina Studzinski (ES_97)'
  UNION ALL SELECT 'Mikayla Free', 'J104720,C019181,C444210,J432005,J066864,R107827,K616148,B387230,A283671', 'Manager: equipe da Mikayla Free e VIP, 3 por turno (ES_97)'
  UNION ALL SELECT 'Mikayla Paid', 'J104720,C019181,C444210,J432005,J066864,R107827,K616148,B387230,A283671', 'Manager: equipe da Mikayla Free e VIP, 3 por turno (ES_97)'
  UNION ALL SELECT 'Nicole Doshi', 'I710373,I122095,B163774,F156592,M497745,M114958', 'Manager: equipe da Nicole (ES_97)'
  UNION ALL SELECT 'Molly Stewart VIP', 'M497745,T102316,L173493,B163774,D422040,F333185,G386564', 'Manager: equipe fixa da Molly (sem o Esley, desligado em 04/11; ES_97)'
  UNION ALL SELECT 'Brandi Love', 'I522137,V848567', 'Manager: Isabella Dias e Vinicius Tadewald (ES_97)'
  UNION ALL SELECT 'Brandi Love FREE', 'I522137,V848567', 'Manager: Isabella Dias e Vinicius Tadewald (ES_97)'
  UNION ALL SELECT 'CJ Miles', 'B183426,C144167,M114958,F092951', 'Manager: Beatriz, Chris Dominy, Maria Brandelero e Fernanda Sella (ES_97)'
  UNION ALL SELECT 'CJ VIP', 'B183426,C144167,M114958,F092951', 'Manager: Beatriz, Chris Dominy, Maria Brandelero e Fernanda Sella (ES_97)'
  UNION ALL SELECT 'Marie Dee VIP', 'T143032', 'Manager: Thiago Arantes (ES_97)'
  UNION ALL SELECT 'Mo Saffari', 'H106569', 'Manager: Henrique Pasini (ES_97)'
  UNION ALL SELECT 'Matty Caron', 'H106569', 'Manager: Henrique Pasini (ES_97)'
  UNION ALL SELECT 'Sebi Barraza', 'H106569', 'Manager: Henrique Pasini (ES_97)'
  UNION ALL SELECT 'Sisi Rose', 'D014864,I801746', 'Manager: Diego Gosteinski e Ignacio Canelo (ES_97)'
  UNION ALL SELECT 'Natasha Nice VIP', 'S094121,L007270', 'Manager: Santiago Pereira e Lucas Giusti (ES_97)'
  UNION ALL SELECT 'Liv', 'M036514', 'Manager: Matteo na Liv (o Lucas Moraes foi para a U2; ES_97)'
) x WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND p.vigente_desde = '2026-11-04' AND p.motivo LIKE '%ES_97%');

-- 5. André Salloum fora da Brandi Love
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, vigente_desde, vigente_ate, motivo)
SELECT x.pagina, 'A070935', 'FORA', '2026-11-04', NULL, 'Manager: André Salloum proibido na Brandi Love (04/11, ES_97)'
  FROM (SELECT 'Brandi Love' AS pagina UNION ALL SELECT 'Brandi Love FREE') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND a.rep_code = 'A070935' AND a.papel = 'FORA');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'estrutura.trocas', 'esc_colaborador_config', 'ES_97', NULL,
        '{"script":"ES_97","desde":"2026-11-04","desligados":["W154869","H185970","E123445"],"trocas":20,"equipes_fixas":20,"fora":"A070935 Brandi Love"}', NULL);

COMMIT;

-- Conferência
SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, c.nivel, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN (SELECT rep FROM tmp_es97 UNION ALL SELECT 'J145594' UNION ALL SELECT 'C144167' UNION ALL SELECT 'A051183'
                             UNION ALL SELECT 'C444210' UNION ALL SELECT 'K616148' UNION ALL SELECT 'W154869' UNION ALL SELECT 'H185970' UNION ALL SELECT 'E123445')
   AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03')
 ORDER BY rep, c.vigente_desde;
SELECT pagina, rep_codes, DATE(vigente_desde) AS de FROM esc_pagina_prioridade WHERE vigente_ate IS NULL AND vigente_desde = '2026-11-04' ORDER BY pagina;
DROP TEMPORARY TABLE IF EXISTS tmp_es97;
