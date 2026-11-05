SET NAMES utf8mb4;
-- =====================================================================
-- ES_117_leo_castro_volta_u3.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 ("Sim, devolver"): Léo Victor Grimaldi de Castro (L147458, SR 3) saiu da
-- U3 A núcleo 3 em 03/11 pelo ES_80, como compensação do Eduardo Gois, que foi da U1 para a Fanvue. O
-- Eduardo voltou para a U1 em 04/11 (ES_97); o Léo volta para a U3 A núcleo 3 a partir de 05/11, mesmo
-- turno e nível. Na planilha do OM da U3 ele é cover da Ivy Vale no A (ES_114).
-- As vagas da U1 fecham em 04/11.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-04' WHERE id = 659 AND UPPER(rep_code) = 'L147458' AND vigente_ate IS NULL;

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-05', NULL,
       'ES_117: volta para a U3 A núcleo 3 a partir de 05/11/2026 (desfaz a compensação do ES_80); cover da Ivy Vale', 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = 3
 WHERE c.id = 659 AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-05');

UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Volta para a U3 (ES_117); ', IFNULL(motivo, '')), 255)
 WHERE id IN (3403, 3419) AND UPPER(rep_code) = 'L147458' AND vigente_desde <= '2026-11-04';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'L147458' AND vigente_desde >= '2026-11-05';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'estrutura.trocas', 'esc_colaborador_config', 'ES_117',
        '{"L147458":"U1 A n1"}', '{"L147458":"U3 A n3","desde":"2026-11-05"}', NULL);

COMMIT;

SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, c.nivel, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) = 'L147458' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03') ORDER BY c.vigente_desde;
SELECT COUNT(*) AS vagas_abertas_u1 FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
 WHERE UPPER(v.rep_code) = 'L147458' AND p.unidade <> 3 AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-05');
