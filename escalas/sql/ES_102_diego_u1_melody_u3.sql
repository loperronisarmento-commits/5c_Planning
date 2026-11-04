SET NAMES utf8mb4;
-- =====================================================================
-- ES_102_diego_u1_melody_u3.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 ("troca o Diego Gosteinski pela Melody Vale"; Melody para a U3 do Keller,
-- Diego para a U1 da Victoria), a partir de 04/11, mesmo turno, núcleo e nível:
--   D014864 Diego Gosteinski  U2 A núcleo 3 → U1 A núcleo 3 (núcleo 21)
--   M203717 Melody Vale       U1 D núcleo 3 → U3 D núcleo 3 (núcleo 9)
-- A Melody segue na equipe da Rocket Reyna VIP (U1), agora como última opção (regra de 04/11).
-- As vagas da unidade antiga fecham em 03/11; as que só começariam depois vão para a data neutra.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-03' WHERE id IN (341, 475) AND UPPER(rep_code) IN ('D014864', 'M203717') AND vigente_ate IS NULL;

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-04', NULL,
       LEFT(CONCAT('ES_102: U', c.unidade, ' → U', n.unidade, ' ', n.turno, ' núcleo ', n.numero, ' a partir de 04/11/2026'), 255), 'CARGA_ESTRUTURA'
  FROM (SELECT 341 AS id, 21 AS nuc UNION ALL SELECT 475, 9) x
  JOIN esc_colaborador_config c ON c.id = x.id
  JOIN esc_nucleo n ON n.id = x.nuc
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-04');

UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('Troca de unidade (ES_102); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) IN ('D014864', 'M203717') AND vigente_desde <= '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04');
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) IN ('D014864', 'M203717') AND vigente_desde >= '2026-11-04';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'estrutura.trocas', 'esc_colaborador_config', 'ES_102',
        '{"D014864":"U2 A n3","M203717":"U1 D n3"}', '{"D014864":"U1 A n3","M203717":"U3 D n3","desde":"2026-11-04"}', NULL);

COMMIT;

SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, c.nivel, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('D014864', 'M203717') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03') ORDER BY rep, c.vigente_desde;
SELECT COUNT(*) AS vagas_abertas_unidade_antiga FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
 WHERE UPPER(v.rep_code) IN ('D014864', 'M203717') AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-04')
   AND p.unidade <> (CASE UPPER(v.rep_code) WHEN 'D014864' THEN 1 ELSE 3 END);
