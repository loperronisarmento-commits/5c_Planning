SET NAMES utf8mb4;
-- =====================================================================
-- ES_73_haven_e_conhecedores_0911.sql  (02/11, aplicado pelo Claude via mysql.exe)
--
-- 1. Haven Free duplicada. O Capacity tem a página como "Haven  Free" (dois espaços; é o nome
--    de fc_posto_fixo, fc_postos_pagina e do histórico). Na geração de 01/11, a entrada automática
--    de página (lib/estrutura.php, escEstruturaPaginasFora) comparou o nome do site "Haven Free"
--    sem tirar os espaços e incluiu a mesma página outra vez: 2 postos por turno em vez de 1.
--    A linha automática fecha em 02/11 (o código foi corrigido na 1.18.9).
--
-- 2. Páginas sem SR que as conheça na própria unidade (pedido de 02/11). Conhece = 3 dias ou
--    mais na página nas últimas 8 semanas (5C Schedule). A partir de 09/11, mesmo turno e nível:
--      P628774  U3 → U2  D  núcleo 2  Draya 52 dias, Ellie D 42
--      J064868  U3 → U2  A  núcleo 2  Ariana Marie 23, Draya 23, Ellie D 19
--      F130590  U1 → U2  C  núcleo 2  Draya 32, Ellie D 31
--      C444210  U1 → U3  A  núcleo 3  Lily Lou OFTV 14
--    Compensação (a cobertura das unidades não muda), quem conhece mais as páginas do destino:
--      J145594  U2 → U3  D  núcleo 2  78 dias em páginas da U3, 5 em páginas da U2
--      V115422  U2 → U3  A  núcleo 2  42 × 5
--      M175553  U2 → U1  C  núcleo 2  71 × 7
--      J177988  U3 → U1  A  núcleo 2  53 × 27
--    Ficaram sem troca:
--      - Keera: ninguém tem mais de 6 dias nela;
--      - Aria: já tem 4 SRs que a conhecem na U3, contando 8 semanas.
--    Quem foi escolhido não estava em equipe fixa de página (esc_pagina_prioridade) nem tinha
--    cadastro com data futura. As vagas fixas na unidade de origem fecham em 08/11; o Gerar
--    completa as vagas no destino, primeiro com quem conhece as páginas.
-- Pode rodar de novo (não duplica). Depois: recalcular o Capacity e gerar a semana de 09/11.
-- =====================================================================

-- 1. Haven Free
UPDATE `5c_planning`.`fc_pagina_carteira`
   SET vigente_ate = '2026-11-02', obs = LEFT(CONCAT('Duplicata de "Haven  Free" (ES_73) · ', COALESCE(obs, '')), 255)
 WHERE BINARY pagina = 'Haven Free' AND vigente_ate IS NULL AND obs LIKE 'Entrou antes de gerar%';

-- 2. Trocas de unidade das pessoas
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es73;
CREATE TEMPORARY TABLE tmp_es73 (
  rep_code VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  de TINYINT UNSIGNED NOT NULL,
  unidade TINYINT UNSIGNED NOT NULL,
  turno CHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
  numero TINYINT UNSIGNED NOT NULL,
  motivo VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL
) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO tmp_es73 (rep_code, de, unidade, turno, numero, motivo) VALUES
  ('P628774', 3, 2, 'D', 2, 'conhece Draya e Ellie D'),
  ('J064868', 3, 2, 'A', 2, 'conhece Ariana Marie, Draya e Ellie D'),
  ('F130590', 1, 2, 'C', 2, 'conhece Draya e Ellie D'),
  ('C444210', 1, 3, 'A', 3, 'conhece Lily Lou OFTV'),
  ('J145594', 2, 3, 'D', 2, 'compensação; conhece páginas da U3'),
  ('V115422', 2, 3, 'A', 2, 'compensação; conhece páginas da U3'),
  ('M175553', 2, 1, 'C', 2, 'compensação; conhece páginas da U1'),
  ('J177988', 3, 1, 'A', 2, 'compensação; conhece páginas da U1');

START TRANSACTION;

-- Cadastro novo a partir de 09/11 no núcleo do destino (copia o que vale hoje).
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, c.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-09', NULL,
       LEFT(CONCAT('Conhecimento de página (ES_73): U', x.de, ' → U', x.unidade, ' núcleo ', x.numero, ' a partir de 09/11/2026; ', x.motivo), 255), 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c
  JOIN tmp_es73 x ON x.rep_code = UPPER(c.rep_code)
  JOIN esc_nucleo n ON n.unidade = x.unidade AND n.turno = x.turno AND n.numero = x.numero
                   AND n.vigente_desde <= '2026-11-09' AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-09')
 WHERE c.funcao = 'EQUITE' AND c.unidade = x.de AND c.turno = x.turno
   AND c.vigente_desde < '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09')
   AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE UPPER(f.rep_code) = x.rep_code AND f.vigente_desde >= '2026-11-09');

-- O cadastro de antes fecha em 08/11 (só de quem já tem o novo).
UPDATE esc_colaborador_config c
  JOIN tmp_es73 x ON x.rep_code = UPPER(c.rep_code)
   SET c.vigente_ate = '2026-11-08'
 WHERE c.vigente_desde < '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09')
   AND EXISTS (SELECT 1 FROM (SELECT DISTINCT UPPER(rep_code) AS rep FROM esc_colaborador_config WHERE vigente_desde = '2026-11-09' AND observacao LIKE 'Conhecimento de página (ES_73)%') f
                WHERE f.rep = x.rep_code);

-- Vagas fixas na unidade de origem fecham em 08/11.
UPDATE esc_vaga_equipe v
  JOIN tmp_es73 x ON x.rep_code = UPPER(v.rep_code)
  JOIN esc_posto p ON p.id = v.posto_id AND p.unidade = x.de
   SET v.vigente_ate = '2026-11-08', v.motivo = LEFT(CONCAT('Conhecimento de página (ES_73): foi para a U', x.unidade, '; ', COALESCE(v.motivo, '')), 255)
 WHERE v.vigente_desde < '2026-11-09' AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09');

COMMIT;

-- Conferência: os 8 com o cadastro de 08/11 e o de 09/11
SELECT x.rep_code, a.unidade AS de_u, b.unidade AS para_u, bn.numero AS para_nucleo, x.turno, b.nivel,
       (SELECT COUNT(*) FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
         WHERE UPPER(v.rep_code) = x.rep_code AND p.unidade = x.de AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-09')) AS vagas_origem_0911
  FROM tmp_es73 x
  LEFT JOIN esc_colaborador_config a ON UPPER(a.rep_code) = x.rep_code AND a.vigente_desde <= '2026-11-08' AND (a.vigente_ate IS NULL OR a.vigente_ate >= '2026-11-08')
  LEFT JOIN esc_colaborador_config b ON UPPER(b.rep_code) = x.rep_code AND b.vigente_desde <= '2026-11-09' AND (b.vigente_ate IS NULL OR b.vigente_ate >= '2026-11-09')
  LEFT JOIN esc_nucleo bn ON bn.id = b.nucleo_id
 ORDER BY x.turno, x.rep_code;

DROP TEMPORARY TABLE IF EXISTS tmp_es73;

-- 3. Parâmetros do conhecimento da página (Equilíbrio das unidades, 1.18.9). Aplicado pelo MCP.
INSERT INTO `5c_escalas`.`esc_parametro` (chave, valor, tipo, grupo, descricao, atualizado_em) VALUES
  ('v4_conhece_dias', '3', 'INT', 'v4', 'Dias na página nas últimas 8 semanas (5C Schedule) para o SR contar como quem conhece a página (Equilíbrio das unidades)', NOW()),
  ('v4_conhece_min', '2', 'INT', 'v4', 'Mínimo de SRs que conhecem a modelo na unidade de destino para o Equilíbrio sugerir a troca de unidade; abaixo disso na própria unidade, a página aparece em Páginas sem quem as conheça (0 desliga)', NOW())
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);
