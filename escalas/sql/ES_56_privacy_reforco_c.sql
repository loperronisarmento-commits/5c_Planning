-- =====================================================================
-- ES_56_privacy_reforco_c.sql  (31/10)
-- Privacy no turno C (T4/T5) a partir de 09/11: o G045315 vai para a U1 A (ES_48) e o
-- Marcos para o D (Folga juntos), e na equipe da Aline Novak e da Andressa Urach fica
-- só o Loik (L052002). Pedido do usuário: primeiro os membros da Aline (quem fez a
-- página nas últimas 4 semanas, ES_54), depois membros novos pela métrica padrão.
--
-- 1. O Loik continua, com a maior fatia (0,5 em cada vaga): é a prioridade do motor.
-- 2. Membros novos pela métrica padrão do completar equipes (lib/equipes.php), com SRs
--    da U2 C em 09/11: núcleo da vaga e folga na fatia primeiro; ninguém com folga,
--    níveis 0–2 até 1; depois quem conhece as páginas do posto (8 semanas), o nível e o
--    mérito. A U2 C está cheia (quase todos com a fatia toda usada), e saem 3, um por
--    núcleo, para que todo dia trabalhem pelo menos 2 da equipe:
--      J572595 (núcleo 3, nível 3): o único com folga; chega à U2 C em 09/11 (ES_48);
--      M175553 (núcleo 2, nível 1): 17 dias na Charlie 306m, página do posto da Aline;
--      N027675 (núcleo 1, nível 1): empate com o J703805 (sem histórico nem mérito),
--               desempatado por ter menos vagas de dono.
--    Fatia 0,167 cada (o resto da vaga depois do Loik).
-- Nos postos do C das duas páginas (Aline: 677, com Charlie 306m e Sarah H 329m;
-- Andressa: 435), como dono e como cobertura. Pode rodar de novo.
-- Rodar depois do ES_55. Depois: Gerar as semanas de 09/11 em diante.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es56_postos;
CREATE TEMPORARY TABLE tmp_es56_postos (posto_id INT NOT NULL PRIMARY KEY) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_es56_postos (posto_id)
SELECT DISTINCT p.id FROM esc_posto p JOIN esc_posto_pagina pp ON pp.posto_id = p.id
 WHERE pp.pagina IN ('Aline Novak', 'Andressa Urach') AND p.unidade = 2 AND p.turno = 'C'
   AND p.vigente_desde <= '2026-11-09' AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-09');

DROP TEMPORARY TABLE IF EXISTS tmp_es56_time;
CREATE TEMPORARY TABLE tmp_es56_time (
  rep_code VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  fatia DECIMAL(5,3) NOT NULL,
  ordem TINYINT NOT NULL,
  motivo VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_es56_time VALUES
  ('L052002', 0.500, 1, 'Privacy (ES_56): membro da Aline (últimas 4 semanas), prioridade no C a partir de 09/11'),
  ('J572595', 0.167, 2, 'Privacy (ES_56): reforço do C a partir de 09/11, métrica padrão (núcleo 3, único com folga)'),
  ('M175553', 0.167, 2, 'Privacy (ES_56): reforço do C a partir de 09/11, métrica padrão (núcleo 2, conhece a Charlie 306m)'),
  ('N027675', 0.167, 2, 'Privacy (ES_56): reforço do C a partir de 09/11, métrica padrão (núcleo 1)');

START TRANSACTION;

-- Só entra quem está na U2 C em 09/11.
DELETE t FROM tmp_es56_time t
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config c WHERE UPPER(c.rep_code) = t.rep_code AND c.funcao = 'EQUITE'
                     AND c.unidade = 2 AND c.turno = 'C' AND c.vigente_desde <= '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09'));

-- A linha da equipe que vem de antes de 09/11 fecha em 08/11; a nova começa em 09/11 com a fatia nova.
UPDATE esc_vaga_equipe e
  JOIN tmp_es56_postos q ON q.posto_id = e.posto_id
  JOIN tmp_es56_time t ON t.rep_code = UPPER(e.rep_code)
   SET e.vigente_ate = '2026-11-08'
 WHERE e.vigente_desde < '2026-11-09' AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-09');

INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT q.posto_id, g.vaga, t.rep_code, t.fatia, t.ordem, 'CONFIRMADA', t.motivo, '2026-11-09', NULL, 'TELA'
  FROM tmp_es56_postos q
  CROSS JOIN tmp_es56_time t
  JOIN (SELECT 'DONO' AS vaga UNION ALL SELECT 'COBERTURA') g
 WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = q.posto_id AND e.vaga = g.vaga COLLATE utf8mb4_unicode_ci
                     AND UPPER(e.rep_code) = t.rep_code AND e.vigente_desde = '2026-11-09');

COMMIT;

-- Conferência: equipe do C das duas páginas em 09/11 (esperado em cada vaga: L052002 0,5 e
-- J572595, M175553, N027675 0,167)
SELECT e.posto_id, (SELECT GROUP_CONCAT(pp.pagina SEPARATOR ' + ') FROM esc_posto_pagina pp WHERE pp.posto_id = e.posto_id) AS paginas, e.vaga,
       GROUP_CONCAT(CONCAT(UPPER(e.rep_code), ' ', e.fatia) ORDER BY e.ordem, e.rep_code SEPARATOR ', ') AS equipe
  FROM esc_vaga_equipe e JOIN tmp_es56_postos q ON q.posto_id = e.posto_id
 WHERE e.vigente_desde <= '2026-11-09' AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-09')
 GROUP BY e.posto_id, e.vaga ORDER BY e.posto_id, e.vaga;

DROP TEMPORARY TABLE IF EXISTS tmp_es56_time;
DROP TEMPORARY TABLE IF EXISTS tmp_es56_postos;
