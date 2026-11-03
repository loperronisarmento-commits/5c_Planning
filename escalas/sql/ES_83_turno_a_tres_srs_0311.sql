SET NAMES utf8mb4;
-- =====================================================================
-- ES_83_turno_a_tres_srs_0311.sql  (03/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: José Otávio, Marcus Cid e Esley Michael vão para o turno A (T2/T3), a partir de já.
-- Cada um fica na mesma unidade, no mesmo nível e no núcleo de mesmo número (mesmo ciclo de folgas).
--   - J104720 José Otávio de Melo       U1 C n3 → U1 A n3 (núcleo 21), nível 5
--   - M137535 Marcos Vinicius Cid Mélo  U2 D n2 → U2 A n2 (núcleo 11), nível 5
--   - E123445 Esley Michael             U2 C n1 → U2 A n1 (núcleo 10), nível 1
-- Sem compensação (não pedida). As vagas fixas do turno antigo fecham em 02/11 (o motor cobre).
-- As equipes fixas continuam (Mikayla, Aline Novak/Andressa Urach, Molly Stewart VIP): eles passam
-- a fazer essas páginas no turno A.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-02' WHERE id IN (406, 631, 625) AND vigente_ate IS NULL;

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-03', NULL,
       LEFT(CONCAT('ES_83: turno ', c.turno, ' → A (T2/T3) a partir de 03/11/2026, núcleo ', n.numero), 255), 'CARGA_ESTRUTURA'
  FROM (SELECT 406 AS id, 21 AS nuc UNION ALL SELECT 631, 11 UNION ALL SELECT 625, 10) x
  JOIN esc_colaborador_config c ON c.id = x.id
  JOIN esc_nucleo n ON n.id = x.nuc
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-03');

UPDATE esc_vaga_equipe
   SET vigente_ate = '2026-11-02', motivo = LEFT(CONCAT('ES_83: foi para o turno A; ', COALESCE(motivo, '')), 255)
 WHERE UPPER(rep_code) IN ('J104720', 'M137535', 'E123445')
   AND vigente_desde < '2026-11-03' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-03');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'colaborador.turno', 'esc_colaborador_config', 'J104720/M137535/E123445',
       '{"J104720":"U1 C n3","M137535":"U2 D n2","E123445":"U2 C n1"}',
       '{"script":"ES_83","desde":"2026-11-03","J104720":"U1 A n3","M137535":"U2 A n2","E123445":"U2 A n1"}', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_log WHERE acao = 'colaborador.turno' AND entidade_id = 'J104720/M137535/E123445');

COMMIT;

-- Conferência
SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, n.numero AS nucleo, c.nivel, c.funcao, c.vigente_desde, c.vigente_ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('J104720', 'M137535', 'E123445') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
 ORDER BY rep, c.vigente_desde;
