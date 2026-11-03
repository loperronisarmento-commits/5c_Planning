SET NAMES utf8mb4;
-- =====================================================================
-- ES_81_fanvue_nathalia_0311.sql  (02/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: as equipes fixas da Fanvue e da Privacy têm prioridade sobre as outras.
--   - Nathalia Macedo Scalercio (N176385), da lista da Fanvue, sai da equipe fixa da CJ Miles
--     (OnlyFans, U2) e vai para a U3 A núcleo 1 desde 03/11, no mesmo nível (1). A Beatriz
--     (B183426) continua de assistente na CJ Miles.
--   - Compensação: M028299 (U3 A n1, nível 1, owner da Savannah e da Ivy Vale até 02/11) vai para
--     o lugar dela na U2 A núcleo 2.
--   - A Nathalia entra na equipe fixa da Savannah e da Ivy Vale (backup no A, com o Guilherme).
--   - As prioridades de um dia de fora da equipe na Fanvue saem (eram "no lugar do Caetano Junior"):
--       id 16 Ivy Vale, A007546, 05/11
--       id 17 Savannah, J010257, 04 a 05/11 (ele já tinha ido para a U1 pelo ES_80)
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-02' WHERE id IN (622, 455) AND vigente_ate IS NULL;

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-03', NULL,
       LEFT(CONCAT('Equipe da Fanvue (ES_81): U', c.unidade, ' → U', n.unidade, ' núcleo ', n.numero, ' a partir de 03/11/2026; ', x.motivo), 255), 'CARGA_ESTRUTURA'
  FROM (SELECT 622 AS id, 1 AS nuc, 'equipe fixa da Fanvue (sai da CJ Miles)' AS motivo
        UNION ALL SELECT 455, 11, 'compensação da Nathalia') x
  JOIN esc_colaborador_config c ON c.id = x.id
  JOIN esc_nucleo n ON n.id = x.nuc
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-03');

-- Vagas fixas: a Nathalia perde as de fora da U3; o M028299, as da U3.
UPDATE esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
   SET v.vigente_ate = '2026-11-02', v.motivo = LEFT(CONCAT('Equipe da Fanvue (ES_81); ', COALESCE(v.motivo, '')), 255)
 WHERE ((UPPER(v.rep_code) = 'N176385' AND p.unidade <> 3) OR (UPPER(v.rep_code) = 'M028299' AND p.unidade = 3))
   AND v.vigente_desde < '2026-11-03' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-03');

-- Owners da Savannah e da Ivy Vale no A: backups Guilherme e Nathalia.
UPDATE esc_vaga_equipe SET fatia = 0.500 WHERE posto_id = 931 AND rep_code = 'G126656' AND vaga = 'COBERTURA' AND vigente_desde = '2026-11-03';
INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT 931, 'COBERTURA', 'N176385', 0.500, 2, 'CONFIRMADA', 'Equipe fixa da Fanvue (ES_81)', '2026-11-03', NULL, 'TELA'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe WHERE posto_id = 931 AND rep_code = 'N176385' AND vigente_desde = '2026-11-03');

-- Equipe fixa da Fanvue com a Nathalia.
UPDATE esc_pagina_prioridade SET rep_codes = CONCAT(rep_codes, ',N176385')
 WHERE id IN (28, 29) AND NOT FIND_IN_SET('N176385', rep_codes);

-- CJ Miles: fica só a Beatriz de assistente no T2/T3, desde 03/11.
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-02' WHERE id = 13 AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'CJ Miles', 'B183426', 'Manager: Beatriz de assistente no T2/T3 (a Nathalia foi para a equipe da Fanvue em 03/11, ES_81)', '2026-11-03', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'CJ Miles' AND vigente_desde = '2026-11-03');

-- Prioridades de um dia de fora da equipe na Fanvue (A007546 na Ivy Vale em 05/11; J010257 na Savannah em 04 e 05/11).
DELETE FROM esc_pagina_prioridade WHERE id IN (16, 17) AND pagina IN ('Ivy Vale', 'Savannah');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'equipe.fixa', 'esc_pagina_prioridade', 'Savannah/Ivy Vale/CJ Miles',
       '{"cj_miles":"N176385,B183426","removidas":[{"id":16,"pagina":"Ivy Vale","rep":"A007546","dia":"2026-11-05"},{"id":17,"pagina":"Savannah","rep":"J010257","de":"2026-11-04","ate":"2026-11-05"}]}',
       '{"script":"ES_81","fanvue":"+N176385","cj_miles":"B183426","compensacao":"M028299 U3 A → U2 A"}', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_log WHERE acao = 'equipe.fixa' AND entidade_id = 'Savannah/Ivy Vale/CJ Miles');

COMMIT;

-- Conferência
SELECT UPPER(c.rep_code) AS rep, c.unidade, n.numero AS nucleo, c.turno, c.nivel, c.vigente_desde
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('N176385', 'M028299') AND c.vigente_desde <= '2026-11-03' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03');
SELECT id, pagina, rep_codes, vigente_desde, vigente_ate FROM esc_pagina_prioridade WHERE pagina IN ('Savannah', 'Ivy Vale', 'CJ Miles') ORDER BY pagina, id;
