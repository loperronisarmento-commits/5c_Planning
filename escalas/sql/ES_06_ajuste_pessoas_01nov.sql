-- =====================================================================
-- ES_06_ajuste_pessoas_01nov.sql
-- Ajuste da carga do ES_05 (revisão de 28/10):
--   1. Fecha em 31/10 a configuração da v3 de quem não está mais ativo no
--      headcount (12 desligados em 28/10).
--   2. Coloca nos núcleos os 20 reps ativos que trabalharam nos últimos
--      14 dias e não estavam no modelo de 27/10 (a maioria buffers da v3).
--      Turno = o turno em que mais trabalharam de 14/10 a 27/10.
--      Nível pelo cargo de hoje: Primaris → 5, Secundus → 3, Tertius e
--      sem cargo → 1. Núcleo: o mais curto do turno (postos próprios +
--      metade da cobertura − pessoas).
--   Os 20 entram sem equipe fixa: o motor v4 usa cada um onde faltar gente
--   no núcleo, nos dias de trabalho dele.
-- Rodar depois do ES_05. Pode rodar de novo (não duplica).
-- Ficam de fora (sem escala em 14 dias): L037393 Luís Felipe Costa Batica
-- Ferreira e L062246 Leticia Amalia Guaragni.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

START TRANSACTION;

-- 1. Desligados
UPDATE esc_colaborador_config c
   SET c.vigente_ate = '2026-10-31'
 WHERE c.vigente_ate IS NULL
   AND c.nucleo_id IS NULL
   AND c.vigente_desde < '2026-11-01'
   AND NOT EXISTS (SELECT 1 FROM `5c_headcount`.headcount h
                    WHERE h.code = c.rep_code COLLATE utf8mb4_0900_ai_ci AND h.active = 1);

-- 2. Os 20 reps nos núcleos
DROP TEMPORARY TABLE IF EXISTS tmp_es06;
CREATE TEMPORARY TABLE tmp_es06 (rep_code VARCHAR(30) NOT NULL PRIMARY KEY, nome VARCHAR(150), unidade TINYINT UNSIGNED,
  turno CHAR(1), nucleo TINYINT UNSIGNED, nivel TINYINT UNSIGNED) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tmp_es06 (rep_code, nome, unidade, turno, nucleo, nivel) VALUES
  ('E407324', 'Esther Wanjiru Njoroge', 1, 'C', 1, 3),
  ('C473710', 'Caetano Aparecido Pedroso Junior', 1, 'C', 3, 3),
  ('M335264', 'Mirriam Wamwari Kariuki', 1, 'D', 1, 1),
  ('D861706', 'Demétrius Freitas Rodrigues de Carvalho', 1, 'D', 2, 1),
  ('I084013', 'Izabelly Kawanny de Souza Lima', 1, 'D', 3, 1),
  ('A098851', 'Arthur Novais Moraes', 3, 'A', 1, 3),
  ('I036726', 'Isabelly Araujo Rodrigues', 3, 'A', 1, 3),
  ('R009812', 'Rodjah Christie Maddy', 3, 'A', 2, 1),
  ('A177909', 'Arthur da Silva Marinho', 3, 'C', 1, 5),
  ('M106697', 'Matheus de Castro Ramos', 3, 'C', 1, 3),
  ('M443425', 'Mariana Ravasio Vilela', 3, 'C', 2, 3),
  ('R169247', 'Rafael Garcia da Costa', 3, 'C', 2, 5),
  ('H185970', 'Hélio Guerra Souza Dos Santos', 3, 'C', 3, 1),
  ('R095006', 'Raphael Estevam', 3, 'C', 3, 5),
  ('L102778', 'Larissa Ceola Guchert', 3, 'D', 1, 3),
  ('R025361', 'Rhazzel John Manabo Marfa', 3, 'D', 1, 1),
  ('C101323', 'Carolina Silva Sousa', 3, 'D', 2, 1),
  ('L704704', 'Lucas Wallace Silva Ribeiro', 3, 'D', 2, 3),
  ('R119391', 'Rodrigo Marquez Felice', 3, 'D', 2, 3),
  ('P291469', 'Peter Omagwa Nyangau', 3, 'D', 3, 1);

UPDATE esc_colaborador_config c JOIN tmp_es06 t ON t.rep_code = c.rep_code
   SET c.vigente_ate = '2026-10-31'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde < '2026-11-01';

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, vigente_desde, origem, observacao)
SELECT t.rep_code, m.id, 'NUCLEO', t.turno, t.unidade, n.id, t.nivel, 'EQUITE', '2026-11-01', 'CARGA_ESTRUTURA',
       'ES_06: ativo fora do modelo de 27/10, sem equipe fixa'
  FROM tmp_es06 t
  JOIN esc_modelo_escala m ON m.nome = '5x2 rotativo 21 dias'
  JOIN esc_nucleo n ON n.unidade = t.unidade AND n.turno = t.turno AND n.numero = t.nucleo AND n.vigente_desde = '2026-11-01'
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config x WHERE x.rep_code = t.rep_code AND x.vigente_desde = '2026-11-01');

COMMIT;

-- Conferência
SELECT 'pessoas na estrutura nova' AS item, COUNT(*) AS n, '265' AS esperado FROM esc_colaborador_config WHERE vigente_desde = '2026-11-01'
UNION ALL SELECT 'Equites', COUNT(*), '239' FROM esc_colaborador_config WHERE vigente_desde = '2026-11-01' AND funcao = 'EQUITE'
UNION ALL SELECT 'configurações v3 ainda abertas fora da estrutura', COUNT(*), '15 (12 líderes OUM/OM, 2 sem escala, 1 RTA)'
  FROM esc_colaborador_config WHERE vigente_ate IS NULL AND nucleo_id IS NULL;
