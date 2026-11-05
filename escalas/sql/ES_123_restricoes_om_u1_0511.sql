SET NAMES utf8mb4;
-- =====================================================================
-- ES_123_restricoes_om_u1_0511.sql  (05/11, aplicado pelo Claude via mysql.exe)
--
-- Restrições adicionais do OM da U1 (pedido do usuário em 05/11, mesma prioridade das anteriores), a partir de 05/11:
-- 1. Mikayla Paid (fotos 1 e 3): no C (T4/T5) entra Jonnas Silva (J066864) ou Rafaela Gaspar (R107827); no D (T6/T1)
--    Brian Kombe (B387230) ou Koi Adonis (K616148) → papel MAIN na equipe da Mikayla Paid (vão antes de todos;
--    o outro do par segue na Mikayla Free). Os marcados nas fotos ficam FORA da Mikayla Paid: Nicolas Kulik
--    (N087021), Victor Stangl (V088215) e Julia Bonin (J177988); a Erika Souza já estava (ES_111) e o Veronio vai
--    para a U2 (item 3).
-- 2. Carolina Sousa (C101323, U1 D): afastada por tempo indeterminado → indisponibilidade aprovada, dia a dia,
--    de 05/11 a 31/12 (como a Mariana Vilela, ES_94). Quando voltar, cancelar os dias que sobrarem.
-- 3. Veronio Junior (V412402): fica na U2 D núcleo 1 (desfeita a ida para a U1 C em 09/11 do ES_48).
--    John Balasta (J010257) já está na U3 C n3 desde 05/11 (ES_111).
-- 4. Solo, nunca como assistente nem com assistente (v4_sr_solo, novo): Bruno Castro (B166766) e Susan Kamau
--    (S373161) até 30/11 ("o mês inteiro"); Thallita Freitas (T112874) e Flora-Vie Mancia (F287215) sempre.
-- 5. Levi Paid, Levi Free e Sumner: já são um posto só (com a Taylor Ballard) em todos os turnos da U1 C1;
--    a mesma pessoa faz as três. Nada a gravar.
-- 6. Preferências: página masculina para Susan Kamau e Ana Giacomini (A070723), em v4_sr_pref_masculinas;
--    Bruno Castro e Susan Kamau com preferência em Diana Rider, Natasha Nice VIP e Rebecca (v4_sr_pref_paginas, novo).
-- 7. Tier: Skye Nascimento (D008138) e Breno Moura (B163148) só tier médio ou alto (v4_sr_tier_medio_alto, novo;
--    tiers A a D). A Skye pode a Mikayla Paid e só entra de assistente na Mikayla Free: sai da equipe da Free e
--    fica "só assistente" nela. Como a Silvia Saige Free é tier F (mesmo posto da VIP), sai a equipe fixa da Skye
--    na Silvia Saige (ES_111): vale a regra mais recente. Miranda Kyle (I061456, nome social; cadastro Ian Lucas):
--    só páginas pequenas → v4_sr_baixo_fluxo_estrito (nunca posto acima de 12 fãs/h, nem de assistente).
-- 8. Sem double: Mikayla Free, Mikayla Paid, Sisi Rose e Holly Jane já são exclusivas (ES_82, ES_87). A Rocket
--    Reyna VIP passa a exclusiva com a OFTV (as duas são o mesmo posto): o SR delas não acumula nem cobre outra.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- Parâmetros
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
 ('v4_sr_solo', 'B166766:2026-11-30,S373161:2026-11-30,T112874,F287215', 'TEXTO', 'v4',
  'Trabalham solo, nunca como assistente nem em posto com assistente ("CÓDIGO" ou "CÓDIGO:até AAAA-MM-DD"): Bruno Castro e Susan Kamau até 30/11, Thallita Freitas e Flora-Vie Mancia'),
 ('v4_sr_tier_medio_alto', 'D008138,B163148', 'TEXTO', 'v4', 'Só páginas de tier médio ou alto (v4_tier_medio_alto): Skye Nascimento, Breno Moura'),
 ('v4_tier_medio_alto', 'A,B,C,D', 'TEXTO', 'v4', 'Tiers do cadastro de páginas (5c_pages) que contam como médio ou alto'),
 ('v4_sr_pref_paginas', 'B166766:Diana Rider|Natasha Nice VIP|Rebecca;S373161:Diana Rider|Natasha Nice VIP|Rebecca', 'TEXTO', 'v4',
  'Preferência nos postos destas páginas ("CÓDIGO:página|página;..."): Bruno Castro e Susan Kamau em Diana Rider, Natasha Nice e Rebecca')
AS n ON DUPLICATE KEY UPDATE valor = n.valor, tipo = n.tipo, descricao = n.descricao;

UPDATE esc_parametro SET valor = 'J161527,T112874,S373161,A070723',
       descricao = 'Preferência nos postos com página masculina (v4_paginas_masculinas): João Kienen, Thallita Freitas, Susan Kamau, Ana Giacomini'
 WHERE chave = 'v4_sr_pref_masculinas' AND valor = 'J161527,T112874';
UPDATE esc_parametro SET valor = 'G386564,A101727,O030503,I061456'
 WHERE chave = 'v4_sr_baixo_fluxo_estrito' AND valor = 'G386564,A101727,O030503';

-- Equipes da Mikayla: a Free sem a Skye; a Paid com main no C e no D
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Refeita no ES_123; ', IFNULL(motivo, '')), 255)
 WHERE id IN (37, 38) AND pagina IN ('Mikayla Free', 'Mikayla Paid') AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.reps, x.papel, x.motivo, '2026-11-05', NULL
  FROM (SELECT 'Mikayla Free' AS pagina, 'J104720,C019181,C444210,J432005,J066864,R107827,K616148,B387230,A283671' AS reps, 'EQUIPE' AS papel,
               'Equipe da Mikayla (ES_97), sem a Skye: só assistente na Free (OM U1, ES_123)' AS motivo
        UNION ALL SELECT 'Mikayla Paid', 'J104720,C019181,C444210,J432005,A283671', 'EQUIPE', 'Equipe da Mikayla (ES_97); main no C e no D à parte (OM U1, ES_123)'
        UNION ALL SELECT 'Mikayla Paid', 'J066864,R107827,B387230,K616148', 'MAIN', 'OM U1 (ES_123): no C Jonnas ou Rafaela, no D Brian ou Koi') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND p.papel = x.papel AND p.motivo LIKE '%ES_123%' AND p.vigente_desde = '2026-11-05');

-- Skye fora da equipe da Silvia Saige (tier F na Free do mesmo posto; regra mais recente)
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Skye só tier médio ou alto (ES_123); ', IFNULL(motivo, '')), 255)
 WHERE id IN (108, 109) AND rep_codes = 'D008138' AND vigente_ate IS NULL;

-- Proibidos na Mikayla Paid e Skye só de assistente na Mikayla Free
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.rep, x.papel, 'Restrição do OM U1 (ES_123)', '2026-11-05', NULL
  FROM (SELECT 'Mikayla Paid' AS pagina, 'N087021' AS rep, 'FORA' AS papel
        UNION ALL SELECT 'Mikayla Paid', 'V088215', 'FORA'
        UNION ALL SELECT 'Mikayla Paid', 'J177988', 'FORA'
        UNION ALL SELECT 'Mikayla Free', 'D008138', 'ASSISTENTE') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND UPPER(a.rep_code) = x.rep AND a.papel = x.papel AND a.vigente_ate IS NULL);

-- Rocket Reyna VIP e OFTV exclusivas (o double só entre elas)
UPDATE esc_pagina_config SET alocacao = 'EXCLUSIVA', atualizado_em = NOW(), atualizado_por = NULL
 WHERE pagina IN ('Rocket Reyna VIP', 'Rocket Reyna OFTV') AND alocacao = 'EQUIPE';

-- Veronio fica na U2 D núcleo 1
UPDATE esc_colaborador_config c JOIN esc_nucleo n ON n.unidade = 2 AND n.turno = 'D' AND n.numero = 1
   SET c.unidade = 2, c.turno = 'D', c.nucleo_id = n.id,
       c.observacao = 'Fica na U2 D núcleo 1 (OM U1, ES_123): desfeita a ida para a U1 C em 09/11 (ES_48)'
 WHERE c.id = 576 AND UPPER(c.rep_code) = 'V412402' AND c.vigente_desde = '2026-11-09' AND c.unidade = 1;

-- Carolina Sousa afastada
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status,
                                   solicitado_em, solicitado_por, decidido_em, decidido_por, comentario_decisao)
SELECT 'C101323', 'VARIOS_DIAS', 'WFM', 'OUTRO', 'Afastada por tempo indeterminado (pedido do OM U1 em 05/11; ES_123)', NULL, 1, 'APROVADO',
       NOW(), NULL, NOW(), NULL, 'Registrado pelo Claude a pedido do Planejamento; revisar quando ela voltar'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_indisponibilidade WHERE rep_code = 'C101323' AND motivo_texto LIKE '%ES_123%');
SET @ind = (SELECT id FROM esc_indisponibilidade WHERE rep_code = 'C101323' AND motivo_texto LIKE '%ES_123%');
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em, decidido_por, comentario)
WITH RECURSIVE d (dia) AS (SELECT DATE('2026-11-05') UNION ALL SELECT dia + INTERVAL 1 DAY FROM d WHERE dia < '2026-12-31')
SELECT @ind, d.dia, 'D', NULL, 'APROVADO', NOW(), NULL, 'afastada (ES_123)'
  FROM d WHERE NOT EXISTS (SELECT 1 FROM esc_indisponibilidade_data x WHERE x.indisponibilidade_id = @ind AND x.dia = d.dia);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_parametro', 'ES_123', NULL,
        '{"om_u1":"restrições de 05/11","mikayla_paid":"main C Jonnas/Rafaela, D Brian/Koi","C101323":"afastada","V412402":"fica U2 D n1","solo":"Bruno, Susan (30/11), Thallita, Flora","rocket":"exclusiva com a OFTV"}', NULL);

COMMIT;

-- Conferência
SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_solo', 'v4_sr_tier_medio_alto', 'v4_tier_medio_alto', 'v4_sr_pref_paginas', 'v4_sr_pref_masculinas', 'v4_sr_baixo_fluxo_estrito');
SELECT id, pagina, rep_codes, papel, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_prioridade WHERE id IN (37, 38, 108, 109) OR motivo LIKE '%ES_123%' ORDER BY id;
SELECT pagina, UPPER(rep_code) AS rep, papel, DATE(vigente_desde) AS de FROM esc_pagina_assistente WHERE motivo LIKE '%ES_123%';
SELECT pagina, alocacao FROM esc_pagina_config WHERE pagina LIKE 'Rocket Reyna%';
SELECT c.id, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id WHERE UPPER(c.rep_code) = 'V412402' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-05');
SELECT i.id, i.rep_code, i.status, MIN(d.dia) AS de, MAX(d.dia) AS ate, COUNT(*) AS dias
  FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id WHERE i.id = @ind GROUP BY i.id;
