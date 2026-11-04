SET NAMES utf8mb4;
-- =====================================================================
-- ES_101_diogo_sisi_diego_volta.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11:
--   1. Diogo Ciesielski (D091331, SR 3, brasileiro) na Sisi Rose (U1C2), "pode mover ele de unidade":
--      U3 D núcleo 2 → U1 D núcleo 2 (núcleo 26) a partir de 04/11, mesmo turno e nível. Vagas da U3 fecham em 03/11.
--   2. Diego Gosteinski (D014864): "reverte a troca" do ES_97. Volta para a U2 A núcleo 3 (config 341 reaberta;
--      a de 04/11 na U1, id 685, é apagada) e as vagas da U2 voltam a valer. Sai da equipe da Sisi Rose
--      (ele só ia para a U1 por causa dela); o Diogo entra no lugar.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- 1. Diogo
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03' WHERE id = 587 AND UPPER(rep_code) = 'D091331' AND vigente_ate IS NULL;
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-04', NULL, 'ES_101: U3 → U1 D núcleo 2 a partir de 04/11/2026, para a Sisi Rose', 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = 26
 WHERE c.id = 587 AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE UPPER(f.rep_code) = 'D091331' AND f.vigente_desde = '2026-11-04');
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Troca de unidade (ES_101); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'D091331' AND vigente_desde <= '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04');

-- 2. Diego volta
DELETE FROM esc_colaborador_config WHERE id = 685 AND UPPER(rep_code) = 'D014864' AND vigente_desde = '2026-11-04';
UPDATE esc_colaborador_config SET vigente_ate = NULL, observacao = LEFT(CONCAT('ES_101: troca do ES_97 revertida; ', IFNULL(observacao, '')), 255)
 WHERE id = 341 AND UPPER(rep_code) = 'D014864' AND vigente_ate = '2026-11-03';
UPDATE esc_vaga_equipe SET vigente_ate = NULL, motivo = LEFT(REGEXP_REPLACE(motivo, '^Troca de unidade \\(ES_97\\); ', ''), 255)
 WHERE id IN (2742, 2748, 3172) AND UPPER(rep_code) = 'D014864' AND vigente_ate = '2026-11-03';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'D014864' AND vigente_desde >= '2026-11-04';

-- Sisi Rose: Diogo no lugar do Diego
UPDATE esc_pagina_prioridade SET rep_codes = 'D091331,I801746', motivo = 'Manager: Diogo Ciesielski e Ignacio Canelo (ES_101; o Diego Gosteinski voltou para a U2)'
 WHERE pagina = 'Sisi Rose' AND vigente_desde = '2026-11-04' AND vigente_ate IS NULL;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'estrutura.trocas', 'esc_colaborador_config', 'ES_101',
        '{"D091331":"U3 D n2","D014864":"U1 A n3 (ES_97)","Sisi Rose":"D014864,I801746"}', '{"D091331":"U1 D n2","D014864":"U2 A n3","Sisi Rose":"D091331,I801746"}', NULL);

COMMIT;

SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('D091331', 'D014864') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03') ORDER BY rep, c.vigente_desde;
SELECT id, UPPER(rep_code) AS rep, posto_id, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('D091331', 'D014864') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-03');
SELECT pagina, rep_codes FROM esc_pagina_prioridade WHERE pagina = 'Sisi Rose' AND vigente_ate IS NULL;
