-- =====================================================================
-- ES_52_equilibrio_pessoas_0211.sql  (31/10)
-- Primeiro ajuste da estrutura nova, depois do ES_51 (Mikayla U1 → U3, Nicole U2 → U1
-- a partir de 02/11): com o faturamento equilibrado, equilibra as pessoas. Cada unidade
-- fica com a mesma cobertura da necessidade do Capacity em cada turno (~74%), com a
-- necessidade já com as trocas (titulares = postos × 1,5). Saem 18 SRs da U2, a mais
-- coberta (92% → 74%); U1 67% → 74% e U3 65% → 74%:
--
--   Turno       U2 → U1                          U2 → U3
--   T6/T1 (D)   3 (I122095, F287215, F396793)    4 (J557703, M627182, D091331, M105176)
--   T2/T3 (A)   5 (T112874, S094121, C144167,    1 (I468128)
--                  R105360, I710373)
--   T4/T5 (C)   1 (L617722)                      4 (L099242, P176228, M032948, I801746)
--
-- Quem vai: para a U1, primeiro quem já trabalha na Nicole (dono, depois cobertura); para a
-- U3, quem tem menos páginas como dono na U2 (mexe menos na origem), sem nível 5 e sem
-- cadastro já marcado para depois de 02/11; a saída fica espalhada pelos 3 núcleos da U2.
-- Núcleo de destino: o de menos SRs por posto de dono no turno (sem os postos da Mikayla
-- na U1). Mesmo turno, mesmo nível e mesmo modelo de escala.
--
-- Só esta vez: nas próximas trocas de unidade as pessoas ficam e a página passa para o
-- time do destino (regra da versão 1.14.2).
--
-- O script: abre o cadastro novo em 02/11 (o atual fecha em 01/11) e fecha as vagas
-- fixas deles na U2 a partir de 02/11 (vaga que começava em 02/11 ou depois vai para a
-- data neutra, 29/10, como no sistema). As vagas no destino e as que ficaram vazias na U2
-- o "Gerar" completa (lib/equipes.php), primeiro com quem conhece as páginas: a Nicole
-- tende a ficar com quem já trabalhava nela.
-- Pode rodar antes ou depois de subir a 1.14.2, e de novo (não duplica).
-- Depois: subir o zip 1.14.2 → Gerar escala › semana de 02/11 › Gerar; conferir, publicar
-- e postar; Organograma em 02/11.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es52;
CREATE TEMPORARY TABLE tmp_es52 (
  rep_code VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  unidade TINYINT UNSIGNED NOT NULL,
  turno CHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  numero TINYINT UNSIGNED NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO tmp_es52 (rep_code, unidade, turno, numero) VALUES
  -- T6/T1 (D)
  ('I122095', 1, 'D', 1), ('F287215', 1, 'D', 1), ('F396793', 1, 'D', 3),
  ('J557703', 3, 'D', 2), ('M627182', 3, 'D', 2), ('D091331', 3, 'D', 2), ('M105176', 3, 'D', 3),
  -- T2/T3 (A)
  ('T112874', 1, 'A', 3), ('S094121', 1, 'A', 3), ('C144167', 1, 'A', 3), ('R105360', 1, 'A', 3), ('I710373', 1, 'A', 1),
  ('I468128', 3, 'A', 1),
  -- T4/T5 (C)
  ('L617722', 1, 'C', 1),
  ('L099242', 3, 'C', 2), ('P176228', 3, 'C', 2), ('M032948', 3, 'C', 2), ('I801746', 3, 'C', 2);

START TRANSACTION;

-- 1. Cadastro novo a partir de 02/11, no núcleo do destino (copia o que vale hoje).
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-02', NULL,
       CONCAT('Equilíbrio de pessoas (ES_52): U', c.unidade, ' → U', n.unidade, ' núcleo ', n.numero, ' a partir de 02/11/2026'), 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c
  JOIN tmp_es52 x ON x.rep_code = UPPER(c.rep_code)
  JOIN esc_nucleo n ON n.unidade = x.unidade AND n.turno = x.turno AND n.numero = x.numero
                   AND n.vigente_desde <= '2026-11-02' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-02')
 WHERE c.funcao = 'EQUITE' AND c.unidade = 2 AND c.turno = x.turno
   AND c.vigente_desde < '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
   AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE UPPER(f.rep_code) = x.rep_code AND f.vigente_desde >= '2026-11-02');

-- 2. O cadastro de antes fecha em 01/11 (só de quem já tem o novo).
UPDATE esc_colaborador_config c
  JOIN tmp_es52 x ON x.rep_code = UPPER(c.rep_code)
   SET c.vigente_ate = '2026-11-01'
 WHERE c.vigente_desde < '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
   AND EXISTS (SELECT 1 FROM (SELECT DISTINCT UPPER(rep_code) AS rep FROM esc_colaborador_config WHERE vigente_desde = '2026-11-02' AND observacao LIKE 'Equilíbrio de pessoas (ES_52)%') f
                WHERE f.rep = x.rep_code);

-- 3. Vagas fixas na U2 fecham a partir de 02/11.
UPDATE esc_vaga_equipe v
  JOIN tmp_es52 x ON x.rep_code = UPPER(v.rep_code)
  JOIN esc_posto p ON p.id = v.posto_id AND p.unidade = 2
   SET v.vigente_ate = '2026-11-01', v.motivo = LEFT(CONCAT('Equilíbrio de pessoas (ES_52): foi para a U', x.unidade, '; ', COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde < '2026-11-02' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-02');

UPDATE esc_vaga_equipe v
  JOIN tmp_es52 x ON x.rep_code = UPPER(v.rep_code)
  JOIN esc_posto p ON p.id = v.posto_id AND p.unidade = 2
   SET v.vigente_desde = '2026-10-29', v.vigente_ate = '2026-10-29',
       v.motivo = LEFT(CONCAT('Equilíbrio de pessoas (ES_52): foi para a U', x.unidade, '; ', COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde >= '2026-11-02'
   AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT posto_id, vaga, rep_code FROM esc_vaga_equipe WHERE vigente_desde = '2026-10-29') z
                    WHERE z.posto_id = v.posto_id AND z.vaga = v.vaga AND z.rep_code = v.rep_code);

COMMIT;

-- Conferência 1: os 18, com o cadastro de 01/11 e o de 02/11 (deve sair 18 linhas, todas com destino)
SELECT x.rep_code, a.unidade AS de_unidade, an.numero AS de_nucleo, b.unidade AS para_unidade, bn.numero AS para_nucleo, x.turno, b.nivel,
       (SELECT COUNT(*) FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
         WHERE UPPER(v.rep_code) = x.rep_code AND p.unidade = 2 AND v.vigente_desde <= '2026-11-02' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-02')) AS vagas_u2_em_0211
  FROM tmp_es52 x
  LEFT JOIN esc_colaborador_config a ON UPPER(a.rep_code) = x.rep_code AND a.vigente_desde <= '2026-11-01' AND (a.vigente_ate IS NULL OR a.vigente_ate >= '2026-11-01')
  LEFT JOIN esc_nucleo an ON an.id = a.nucleo_id
  LEFT JOIN esc_colaborador_config b ON UPPER(b.rep_code) = x.rep_code AND b.vigente_desde <= '2026-11-02' AND (b.vigente_ate IS NULL OR b.vigente_ate >= '2026-11-02')
  LEFT JOIN esc_nucleo bn ON bn.id = b.nucleo_id
 ORDER BY x.turno, x.unidade, x.rep_code;

-- Conferência 2: SRs por unidade e turno em 02/11 (esperado D 33/24/28, A 30/22/26, C 29/21/25)
SELECT n.unidade, n.turno, COUNT(*) AS srs
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE c.funcao = 'EQUITE' AND c.vigente_desde <= '2026-11-02' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02')
 GROUP BY n.unidade, n.turno ORDER BY FIELD(n.turno, 'D', 'A', 'C'), n.unidade;

DROP TEMPORARY TABLE IF EXISTS tmp_es52;
