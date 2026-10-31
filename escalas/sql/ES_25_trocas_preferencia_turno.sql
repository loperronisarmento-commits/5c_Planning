-- =====================================================================
-- ES_25_trocas_preferencia_turno.sql  (29/10)
-- Trocas de turno pelo formulário de preferência (28–29/10). Cada troca é 1 por 1
-- entre SRs: a pessoa vai para o núcleo da outra e assume as vagas fixas dela
-- (nenhum posto fica sem dono). Vale da estrutura nova em diante (01/11).
-- Detalhe de cada troca e o motivo: Proposta_turnos_formulario.xlsx, aba Trocas.
-- Depois: ES_26 e gerar de novo as semanas de 26/10 e 02/11.
-- Pode rodar de novo (só troca quem ainda está no núcleo de origem).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es25;
CREATE TEMPORARY TABLE tmp_es25 (
  rep_code   VARCHAR(30) NOT NULL PRIMARY KEY,
  par_code   VARCHAR(30) NOT NULL,
  u_de TINYINT UNSIGNED NOT NULL, t_de CHAR(1) NOT NULL, k_de TINYINT UNSIGNED NOT NULL,
  u_para TINYINT UNSIGNED NOT NULL, t_para CHAR(1) NOT NULL, k_para TINYINT UNSIGNED NOT NULL,
  nome VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tmp_es25 (rep_code, par_code, u_de, t_de, k_de, u_para, t_para, k_para, nome) VALUES
  ('A007546', 'E407324', 1, 'A', 3, 1, 'C', 1, 'Alexandre Magno Barroso Ferreira'),
  ('E407324', 'A007546', 1, 'C', 1, 1, 'A', 3, 'Esther Wanjiru Njoroge'),
  ('M137535', 'C099427', 2, 'C', 2, 2, 'D', 3, 'Marcos Vinicius Cid Mélo'),
  ('C099427', 'M137535', 2, 'D', 3, 2, 'C', 2, 'Carolina Xavier Broggio'),
  ('R169247', 'I131318', 3, 'C', 2, 3, 'D', 3, 'Rafael Garcia da Costa'),
  ('I131318', 'R169247', 3, 'D', 3, 3, 'C', 2, 'Ingrid Marcia Bessa'),
  ('C051206', 'M632209', 3, 'D', 1, 3, 'A', 3, 'Caroline Gonçalves Souza'),
  ('M632209', 'C051206', 3, 'A', 3, 3, 'D', 1, 'Márcio Herbert Lima Pontes'),
  ('L704704', 'E031058', 3, 'D', 2, 3, 'C', 1, 'Lucas Wallace Silva Ribeiro'),
  ('E031058', 'L704704', 3, 'C', 1, 3, 'D', 2, 'Erika Martins Ev de Souza'),
  ('J132213', 'M029962', 2, 'D', 1, 2, 'A', 2, 'José Diogo Moss Domingues Cascon Martins'),
  ('M029962', 'J132213', 2, 'A', 2, 2, 'D', 1, 'Marina de Souza Silva'),
  ('J621606', 'A099370', 2, 'C', 3, 2, 'D', 3, 'Julio Eduardo Candia Antezana'),
  ('A099370', 'J621606', 2, 'D', 3, 2, 'C', 3, 'Amanda Gonçales Rodrigues Lopes'),
  ('D014864', 'L118244', 2, 'C', 2, 2, 'A', 3, 'Diego Gosteinski'),
  ('L118244', 'D014864', 2, 'A', 3, 2, 'C', 2, 'Letícia Carolina Zaleski'),
  ('I061456', 'L031271', 3, 'C', 3, 3, 'A', 1, 'Ian Lucas Ferreira Almeida (Miranda Kyle)'),
  ('L031271', 'I061456', 3, 'A', 1, 3, 'C', 3, 'Luiz Alfredo de Queiroz Souza'),
  ('A044968', 'M011546', 3, 'D', 2, 3, 'A', 2, 'Alexsander Branco Barbosa'),
  ('M011546', 'A044968', 3, 'A', 2, 3, 'D', 2, 'Matheus Vinicius Iglessias da Silva'),
  ('R095006', 'M060354', 3, 'C', 3, 3, 'A', 1, 'Raphael Estevam'),
  ('M060354', 'R095006', 3, 'A', 1, 3, 'C', 3, 'Matheus Silva Oliveira');

-- Só entra quem ainda está no núcleo de origem (se rodar de novo, não destroca).
DELETE t FROM tmp_es25 t
 WHERE NOT EXISTS (SELECT 1 FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
                    WHERE c.rep_code = t.rep_code COLLATE utf8mb4_unicode_ci AND c.vigente_desde <= '2026-11-01' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-01')
                      AND n.unidade = t.u_de AND n.turno = t.t_de COLLATE utf8mb4_unicode_ci AND n.numero = t.k_de);

-- 1. Núcleo, unidade e turno novos na configuração de 01/11.
UPDATE esc_colaborador_config c
  JOIN tmp_es25 t ON t.rep_code = c.rep_code COLLATE utf8mb4_unicode_ci
  JOIN esc_nucleo n ON n.unidade = t.u_para AND n.turno = t.t_para COLLATE utf8mb4_unicode_ci AND n.numero = t.k_para AND n.vigente_ate IS NULL
   SET c.nucleo_id = n.id, c.unidade = n.unidade, c.turno = n.turno,
       c.observacao = LEFT(CONCAT('troca de turno pelo formulário (ES_25) com ', t.par_code, '; ', COALESCE(c.observacao, '')), 255)
 WHERE c.vigente_desde <= '2026-11-01' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-01');

-- 2. Vagas fixas: cada um assume as do par (marca com # para não misturar e depois tira).
UPDATE esc_vaga_equipe e JOIN tmp_es25 t ON t.rep_code = e.rep_code COLLATE utf8mb4_unicode_ci
   SET e.rep_code = CONCAT('#', t.par_code), e.motivo = LEFT(CONCAT('troca de turno pelo formulário (ES_25); ', COALESCE(e.motivo, '')), 255)
 WHERE e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-01';
UPDATE esc_vaga_equipe SET rep_code = SUBSTRING(rep_code, 2) WHERE rep_code LIKE '#%';

-- Conferência
SELECT t.rep_code, t.nome, CONCAT('U', t.u_de, ' ', t.t_de, t.k_de) AS de, CONCAT('U', n.unidade, ' ', n.turno, n.numero) AS agora,
       CONCAT('U', t.u_para, ' ', t.t_para, t.k_para) AS esperado,
       (SELECT COUNT(*) FROM esc_vaga_equipe e WHERE e.rep_code = t.rep_code COLLATE utf8mb4_unicode_ci AND (e.vigente_ate IS NULL OR e.vigente_ate >= '2026-11-01')) AS vagas
  FROM tmp_es25 t
  JOIN esc_colaborador_config c ON c.rep_code = t.rep_code COLLATE utf8mb4_unicode_ci AND c.vigente_desde <= '2026-11-01' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-01')
  JOIN esc_nucleo n ON n.id = c.nucleo_id ORDER BY t.u_de, t.t_de, t.k_de;
SELECT 'pessoas trocadas' AS item, COUNT(*) AS tem, '22' AS esperado FROM tmp_es25
UNION ALL SELECT 'vagas com # sobrando', COUNT(*), '0' FROM esc_vaga_equipe WHERE rep_code LIKE '#%';
DROP TEMPORARY TABLE IF EXISTS tmp_es25;
