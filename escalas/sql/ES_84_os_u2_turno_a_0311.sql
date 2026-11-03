SET NAMES utf8mb4;
-- =====================================================================
-- ES_84_os_u2_turno_a_0311.sql  (03/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: os OS do Ricardo Cunico (OC da U2, turno A) passam a ser, a partir de já:
--   OS 1 = Vinicius Kodama (V098223)            antes OS 2
--   OS 2 = Julio Candia (J621606)               antes SR nível 5 na U2 D n3
--   OS 3 = Lucas Caetano (L124965)              sem mudança
-- João Paulo Caial (J110041), que era OS 1, vira SR (nível 5, no mesmo núcleo 1, como no ES_21).
-- Mesmo caminho da tela Roster: o OS do dia é a configuração DECURIO no núcleo; o esc_nucleo
-- guarda o OS atual. As vagas fixas do Julio no turno D fecham em 02/11 (o motor cobre).
-- O espelho no Capacity (fc_estrutura_lider) está no 5C_Planning_31.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- 527 Kodama (OS n2), 408 Caial (OS n1), 421 Julio (SR U2 D n3)
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-02' WHERE id IN (527, 408, 421) AND vigente_ate IS NULL;

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, 'NUCLEO', n.turno, n.unidade, n.id, x.nivel, x.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-03', NULL, LEFT(x.obs, 255), 'TELA'
  FROM (SELECT 527 AS id, 10 AS nuc, 'DECURIO' AS funcao, CAST(NULL AS SIGNED) AS nivel, 'ES_84: OS do núcleo 1 da U2 A (era o 2) a partir de 03/11/2026' AS obs
        UNION ALL SELECT 421, 11, 'DECURIO', NULL, 'ES_84: OS do núcleo 2 da U2 A (era SR nível 5 na U2 D n3) a partir de 03/11/2026'
        UNION ALL SELECT 408, 10, 'EQUITE', 5, 'ES_84: saiu de OS do núcleo 1 da U2 A; SR nível 5 no mesmo núcleo a partir de 03/11/2026') x
  JOIN esc_colaborador_config c ON c.id = x.id
  JOIN esc_nucleo n ON n.id = x.nuc
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-03');

UPDATE esc_nucleo SET decurio_code = 'V098223', decurio_nome = 'Vinicius Kodama Yoshinaga' WHERE id = 10;
UPDATE esc_nucleo SET decurio_code = 'J621606', decurio_nome = 'Julio Eduardo Candia Antezana' WHERE id = 11;

UPDATE esc_vaga_equipe
   SET vigente_ate = '2026-11-02', motivo = LEFT(CONCAT('ES_84: virou OS da U2 A; ', COALESCE(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'J621606' AND vigente_desde < '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-03');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'roster.trocar', 'esc_nucleo', '10/11',
       '{"n1":"J110041","n2":"V098223","n3":"L124965"}',
       '{"script":"ES_84","desde":"2026-11-03","n1":"V098223","n2":"J621606","n3":"L124965","J110041":"SR nível 5 n1"}', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_log WHERE acao = 'roster.trocar' AND entidade_id = '10/11' AND depois LIKE '%ES_84%');

COMMIT;

-- Conferência
SELECT n.id, n.unidade, n.turno, n.numero, n.decurio_code, n.decurio_nome FROM esc_nucleo n WHERE n.id IN (10, 11, 12);
SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.funcao, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('V098223', 'J621606', 'J110041') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
 ORDER BY rep, c.vigente_desde;
