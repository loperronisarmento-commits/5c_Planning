SET NAMES utf8mb4;
-- =====================================================================
-- ES_212 · Núcleo de 3 SRs da U2 igual ao app do OM da U2, desde 30/11 (24/11, pedido do usuário: "Corrija os núcleos").
--   No comparativo do OM com a v1.5 (plano 167), os dias que o app dá de folga para eles só batem com outro núcleo:
--   - Peter Nyangau P291469: U2 D n3 → U2 D n1 (folga seg 30/11 e sex 04/12; o ES_196 o tinha posto no n3).
--   - Juliana Simões J557703: U2 D n2 → U2 D n3 (folga qua 02 e qui 03/12; o n2 vinha da U3, ES_196).
--   - Wilson Vinicius W175160: U2 C n3 → U2 C n2 (fim de semana 05 e 06/12; o n3 vinha da U3, ES_170).
--   As linhas de 23/11 (ids 920, 943 e 910) fecham em 29/11 (a semana de 23/11 fica como os OM escalaram) e abrem
--   linhas novas em 30/11 (o resto igual). As vagas de equipe propostas deles fecham em 29/11 (postos do núcleo de antes);
--   o próximo Gerar refaz. O dia de Overlap fixo (esc_sr_overlap: 17, 17 e 16) e a EQUIPE da Molly (Juliana) ficam.
--   Via mysql.exe (transação).
-- =====================================================================
USE `5c_escalas`;

SELECT 'antes' AS momento, c.id, UPPER(c.rep_code) rep, c.unidade, c.turno, n.numero, c.nivel, c.vigente_desde, c.vigente_ate FROM esc_colaborador_config c
  LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('P291469','J557703','W175160') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-23') ORDER BY rep, c.vigente_desde;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_mv;
CREATE TEMPORARY TABLE tmp_mv (id INT PRIMARY KEY, nucleo_id INT, obs VARCHAR(255)) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_mv VALUES
 (920, 16, 'U2 D núcleo 1 desde 30/11, igual ao app do OM da U2 (ES_212; antes U2 D n3, ES_196)'),
 (943, 18, 'U2 D núcleo 3 desde 30/11, igual ao app do OM da U2 (ES_212; antes U2 D n2, ES_196)'),
 (910, 14, 'U2 C núcleo 2 desde 30/11, igual ao app do OM da U2 (ES_212; antes U2 C n3, ES_170)');
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, c.unidade, m.nucleo_id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora, c.folgas_fixas, c.pode_assistente, '2026-11-30', NULL, m.obs, 'TELA'
  FROM esc_colaborador_config c JOIN tmp_mv m ON m.id = c.id
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-30';
UPDATE esc_colaborador_config c JOIN tmp_mv m ON m.id = c.id SET c.vigente_ate = '2026-11-29' WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-30';

-- Vagas de equipe propostas (postos do núcleo de antes)
DELETE FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('P291469','J557703','W175160') AND vigente_desde >= '2026-11-30';
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-29', motivo = LEFT(CONCAT('ES_212: mudou de núcleo; ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) IN ('P291469','J557703','W175160') AND (vigente_ate IS NULL OR vigente_ate > '2026-11-29');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'colaborador.mover', 'esc_colaborador_config', 'ES_212', NULL,
       JSON_OBJECT('script', 'ES_212', 'desde', '2026-11-30', 'srs', 3), NULL;

COMMIT;

SELECT 'depois' AS momento, c.id, UPPER(c.rep_code) rep, c.unidade, c.turno, n.numero, c.nivel, c.vigente_desde, c.vigente_ate FROM esc_colaborador_config c
  LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('P291469','J557703','W175160') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-23') ORDER BY rep, c.vigente_desde;
SELECT 'depois' AS momento, COUNT(*) AS vagas_abertas FROM esc_vaga_equipe WHERE UPPER(rep_code) IN ('P291469','J557703','W175160') AND (vigente_ate IS NULL OR vigente_ate > '2026-11-29');
