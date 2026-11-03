SET NAMES utf8mb4;
-- =====================================================================
-- ES_80_fanvue_equipe_0311.sql  (02/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: as páginas da Fanvue (Savannah e Ivy Vale, U3C2, um posto por turno) só com
-- os SRs que têm experiência na Fanvue, a partir de agora (03/11). Equipe fixa nas duas páginas:
-- outras pessoas só entram se ninguém da equipe estiver disponível. O motor faz isso pela
-- esc_pagina_prioridade, só com quem está na unidade e no turno da página; por isso quem está fora
-- vai para a U3.
-- Da lista: Luís Leitão foi desligado (ES_76); Caio Limongi é OS da U3 A, não SR; "Natalia
-- Macedo" = Nathalia Macedo Scalercio (N176385), que fica na U2 por enquanto: ela já é da
-- equipe fixa da CJ Miles (U2) desde 02/11, e as duas não cabem juntas.
-- Já na U3: G126656 Guilherme Watanabe (A, n3), M627182 Melody Joyce e Y099118 Yasmim Barbosa (D, n2).
--
-- Mudanças, no mesmo turno e nível:
--   E510906 Eduardo Gois      U1 A n1 → U3 A n2  desde 03/11 (dono do posto da Fanvue no A)
--   F081583 Francisca Mylla   U1 C n3 → U3 C n3  desde 03/11
--   G031149 Gabriel Pereira   U1 C n1 → U3 C n2  desde 03/11
--   F472566 Francisco Joshua  U1 D n2 → U3 D n3  desde 03/11
--   R152507 Rachel Grace      U2 D n1 → U3 D n1  desde 03/11 (o ES_48 a levava para a U1 D em 09/11)
--   J572595 Jean Larraine     U2 A até 08/11 → U3 C n2 desde 09/11 (o ES_48 já o passava do A para o
--                             C em 09/11; nesta semana ele segue no A da U2)
-- Compensação: os owners de hoje da Savannah e da Ivy Vale, que deixam de ser owners, vão para o
-- lugar de quem sai (mesma unidade, turno e núcleo de origem, mesmo nível):
--   L147458  U3 A n3 → U1 A n1  desde 03/11 (Eduardo)
--   J010257  U3 C n3 → U1 C n3  desde 03/11 (Francisca)
--   I801746  U3 C n2 → U1 C n1  desde 03/11 (Gabriel)
--   S373161  U3 D n3 → U1 D n2  desde 03/11 (Francisco Joshua)
--   L101222  U3 D n1 → U2 D n1  de 03/11 a 08/11, e U1 D n1 desde 09/11 (Rachel, que ia para a U1)
--   P176228  U3 C n2 → U2 C n3  desde 09/11 (Jean)
-- Owners e backups da Savannah e da Ivy Vale desde 03/11 (as vagas de hoje fecham em 02/11):
--   A: dono Eduardo; backup Guilherme.
--   C: donos Gabriel e Jean (Jean desde 09/11); backup Francisca.
--   D: donos Francisco Joshua e Rachel; backups Melody e Yasmim.
-- A escala publicada da semana de 02/11 não muda sozinha: vale no próximo Gerar.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- Cadastros de hoje: fecham em 02/11 (os que mudam a partir de 09/11 fecham em 08/11).
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-02'
 WHERE id IN (350, 353, 366, 363, 507, 445, 396, 593, 513, 439) AND (vigente_ate IS NULL OR vigente_ate > '2026-11-02');
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-08' WHERE id = 600 AND vigente_ate IS NULL;

-- Rachel: o cadastro de 09/11 do ES_48 (U1 D) vira U3 D desde 03/11. Jean: o de 09/11 vira U3 C.
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 7, vigente_desde = '2026-11-03',
       observacao = 'Equipe da Fanvue (ES_80): U2 D → U3 D núcleo 1 a partir de 03/11/2026 (antes ES_48: U1 D em 09/11)'
 WHERE id = 579 AND unidade = 1;
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 5,
       observacao = 'Equipe da Fanvue (ES_80): U2 A → U3 C núcleo 2 a partir de 09/11/2026 (antes ES_48: U2 C)'
 WHERE id = 570 AND unidade = 2;

-- Cadastros novos (cópia do de hoje, com a unidade, o núcleo e a vigência novos).
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, x.desde, x.ate,
       LEFT(CONCAT('Equipe da Fanvue (ES_80): U', c.unidade, ' → U', n.unidade, ' núcleo ', n.numero, ' a partir de ', DATE_FORMAT(x.desde, '%d/%m/%Y'), '; ', x.motivo), 255), 'CARGA_ESTRUTURA'
  FROM (SELECT 350 AS id, 2 AS nuc, '2026-11-03' AS desde, NULL AS ate, 'equipe fixa da Fanvue' AS motivo  -- Eduardo → U3 A n2
        UNION ALL SELECT 353, 6, '2026-11-03', NULL, 'equipe fixa da Fanvue'                               -- Francisca → U3 C n3
        UNION ALL SELECT 366, 5, '2026-11-03', NULL, 'equipe fixa da Fanvue'                               -- Gabriel → U3 C n2
        UNION ALL SELECT 363, 9, '2026-11-03', NULL, 'equipe fixa da Fanvue'                               -- Francisco Joshua → U3 D n3
        UNION ALL SELECT 445, 19, '2026-11-03', NULL, 'compensação da equipe da Fanvue'                    -- L147458 → U1 A n1
        UNION ALL SELECT 396, 24, '2026-11-03', NULL, 'compensação da equipe da Fanvue'                    -- J010257 → U1 C n3
        UNION ALL SELECT 593, 22, '2026-11-03', NULL, 'compensação da equipe da Fanvue'                    -- I801746 → U1 C n1
        UNION ALL SELECT 513, 26, '2026-11-03', NULL, 'compensação da equipe da Fanvue'                    -- S373161 → U1 D n2
        UNION ALL SELECT 439, 16, '2026-11-03', '2026-11-08', 'compensação da equipe da Fanvue'            -- L101222 → U2 D n1
        UNION ALL SELECT 439, 25, '2026-11-09', NULL, 'compensação da equipe da Fanvue'                    -- L101222 → U1 D n1
        UNION ALL SELECT 600, 15, '2026-11-09', NULL, 'compensação da equipe da Fanvue') x                 -- P176228 → U2 C n3
  JOIN esc_colaborador_config c ON c.id = x.id
  JOIN esc_nucleo n ON n.id = x.nuc
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = x.desde AND f.observacao LIKE 'Equipe da Fanvue (ES_80)%');

-- Vagas fixas: quem chega à U3 perde as de fora da U3; quem sai da U3 perde as da U3.
UPDATE esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
   SET v.vigente_ate = '2026-11-02', v.motivo = LEFT(CONCAT('Equipe da Fanvue (ES_80); ', COALESCE(v.motivo, '')), 255)
 WHERE ((UPPER(v.rep_code) IN ('E510906', 'F081583', 'G031149', 'F472566', 'R152507') AND p.unidade <> 3)
     OR (UPPER(v.rep_code) IN ('L147458', 'J010257', 'I801746', 'S373161', 'L101222') AND p.unidade = 3))
   AND v.vigente_desde < '2026-11-03' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-03');
UPDATE esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
   SET v.vigente_ate = '2026-11-08', v.motivo = LEFT(CONCAT('Equipe da Fanvue (ES_80); ', COALESCE(v.motivo, '')), 255)
 WHERE ((UPPER(v.rep_code) = 'J572595' AND p.unidade <> 3) OR (UPPER(v.rep_code) = 'P176228' AND p.unidade = 3))
   AND v.vigente_desde < '2026-11-09' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09');

-- Owners e backups da Savannah e da Ivy Vale: as vagas de hoje fecham em 02/11.
UPDATE esc_vaga_equipe v
   SET v.vigente_ate = '2026-11-02', v.motivo = LEFT(CONCAT('Equipe fixa da Fanvue (ES_80); ', COALESCE(v.motivo, '')), 255)
 WHERE v.posto_id IN (931, 942, 953) AND v.vigente_desde < '2026-11-03' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-03');

INSERT INTO esc_vaga_equipe (posto_id, vaga, rep_code, fatia, ordem, status, motivo, vigente_desde, vigente_ate, origem)
SELECT x.posto, x.vaga, x.rep, x.fatia, x.ordem, 'CONFIRMADA', 'Equipe fixa da Fanvue (ES_80)', x.desde, NULL, 'TELA'
  FROM (SELECT 931 AS posto, 'DONO' AS vaga, 'E510906' AS rep, 1.000 AS fatia, 1 AS ordem, '2026-11-03' AS desde
        UNION ALL SELECT 931, 'COBERTURA', 'G126656', 1.000, 1, '2026-11-03'
        UNION ALL SELECT 942, 'DONO', 'G031149', 0.500, 1, '2026-11-03'
        UNION ALL SELECT 942, 'DONO', 'J572595', 0.500, 2, '2026-11-09'
        UNION ALL SELECT 942, 'COBERTURA', 'F081583', 1.000, 1, '2026-11-03'
        UNION ALL SELECT 953, 'DONO', 'F472566', 0.500, 1, '2026-11-03'
        UNION ALL SELECT 953, 'DONO', 'R152507', 0.500, 2, '2026-11-03'
        UNION ALL SELECT 953, 'COBERTURA', 'M627182', 0.500, 1, '2026-11-03'
        UNION ALL SELECT 953, 'COBERTURA', 'Y099118', 0.500, 2, '2026-11-03') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_vaga_equipe e WHERE e.posto_id = x.posto AND e.rep_code = x.rep AND e.vaga = x.vaga AND e.vigente_desde = x.desde);

-- Equipe fixa: o motor dá a página a eles antes de qualquer outro SR da unidade e do turno.
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT g.pagina, 'E510906,G126656,G031149,J572595,F081583,F472566,R152507,M627182,Y099118',
       'Manager: equipe fixa da Fanvue (SRs com experiência na Fanvue); outras pessoas só se ninguém dela estiver disponível', '2026-11-03', NULL
  FROM (SELECT 'Savannah' AS pagina UNION ALL SELECT 'Ivy Vale') g
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade e WHERE e.pagina = g.pagina AND e.vigente_desde = '2026-11-03');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'equipe.fixa', 'esc_pagina_prioridade', 'Savannah/Ivy Vale', NULL,
       '{"script":"ES_80","desde":"2026-11-03","equipe":"E510906,G126656,G031149,J572595,F081583,F472566,R152507,M627182,Y099118"}', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_log WHERE acao = 'equipe.fixa' AND entidade_id = 'Savannah/Ivy Vale');

COMMIT;

-- Conferência: onde cada um fica em 03/11 e em 09/11
SELECT x.rep, d.dia, c.unidade, n.numero AS nucleo, c.turno, c.nivel
  FROM (SELECT 'E510906' AS rep UNION ALL SELECT 'F081583' UNION ALL SELECT 'G031149' UNION ALL SELECT 'F472566' UNION ALL SELECT 'R152507'
        UNION ALL SELECT 'J572595' UNION ALL SELECT 'L147458' UNION ALL SELECT 'J010257' UNION ALL SELECT 'I801746' UNION ALL SELECT 'S373161'
        UNION ALL SELECT 'L101222' UNION ALL SELECT 'P176228') x
 CROSS JOIN (SELECT '2026-11-03' AS dia UNION ALL SELECT '2026-11-09') d
  LEFT JOIN esc_colaborador_config c ON UPPER(c.rep_code) = x.rep AND c.funcao = 'EQUITE' AND c.vigente_desde <= d.dia AND (c.vigente_ate IS NULL OR c.vigente_ate >= d.dia)
  LEFT JOIN esc_nucleo n ON n.id = c.nucleo_id
 ORDER BY x.rep, d.dia;
