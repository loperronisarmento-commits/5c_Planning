-- =====================================================================
-- ES_21_quadro_lideranca_29out.sql  (29/10)
-- Liderança da equipe operacional pelo quadro de 29/10 (vigência 01/11):
--   - os 27 OS nos núcleos do quadro (OS 1, 2 e 3 = núcleo 1, 2 e 3 da unidade e turno);
--   - 4 SRs viram OS: Isabela de Albuquerque (U1 C 1), Julia Xavier Broggio (U2 D 2),
--     Matheus Trevisan (U2 C 2) e Isabel da Silva Marinho (A177909, nome social; U2 C 3).
--     Saem das vagas fixas de SR (fecham em 31/10); o motor cobre essas vagas com
--     outras pessoas do núcleo;
--   - 3 OS de hoje não estão no quadro e passam a SR nível 5 no mesmo núcleo:
--     Thiago de Meneses Arantes, José Otávio de Melo e Carolina Xavier Broggio
--     (confirmado em 29/10);
--   - OC: Henrique Dubles vai para U3 D e Pedro Ribeiro para U3 C.
-- Rode depois do ES_19 (ou antes; a parte do OC vale nos dois casos).
-- Depois: gerar de novo as semanas de 26/10 e 02/11.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es21_os;
CREATE TEMPORARY TABLE tmp_es21_os (
  nucleo_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  unidade   TINYINT UNSIGNED NOT NULL,
  turno     CHAR(1) NOT NULL,
  numero    TINYINT UNSIGNED NOT NULL,
  rep_code  VARCHAR(30) NOT NULL,
  nome      VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tmp_es21_os (nucleo_id, unidade, turno, numero, rep_code, nome) VALUES
  ( 1, 1, 'A', 1, 'C019777', 'Caio Afonso da Silva Limongi'),
  ( 2, 1, 'A', 2, 'Y135044', 'Yuri de Souza Oliveira Bonfim'),
  ( 3, 1, 'A', 3, 'N092070', 'Nicoli Peretti Klein'),
  ( 4, 1, 'C', 1, 'I351554', 'Isabela de Albuquerque Costa Barros'),
  ( 5, 1, 'C', 2, 'L098436', 'Lucas Fernandes Silva'),
  ( 6, 1, 'C', 3, 'B114969', 'Betania Caroline Francisco Ferreira'),
  ( 7, 1, 'D', 1, 'A142232', 'Alex Emanuel Machado de Souza'),
  ( 8, 1, 'D', 2, 'N163267', 'Nathan da Silva Peres Santos'),
  ( 9, 1, 'D', 3, 'N069703', 'Natasha Tem Tem'),
  (10, 2, 'A', 1, 'J110041', 'Joao Paulo Caial Pires'),
  (11, 2, 'A', 2, 'V098223', 'Vinicius Kodama Yoshinaga'),
  (12, 2, 'A', 3, 'L124965', 'Lucas Dos Santos Caetano'),
  (13, 2, 'C', 1, 'J418568', 'Julia Andrade Vieira'),
  (14, 2, 'C', 2, 'M408844', 'Matheus Trevisan Simões'),
  (15, 2, 'C', 3, 'A177909', 'Isabel da Silva Marinho'),
  (16, 2, 'D', 1, 'L092466', 'Leiziane Lopes Ruiz'),
  (17, 2, 'D', 2, 'J108431', 'Julia Xavier Broggio'),
  (18, 2, 'D', 3, 'N108054', 'Nátali Bianchini Vieira'),
  (19, 3, 'A', 1, 'J085570', 'João Lucas dos Santos Mayresse'),
  (20, 3, 'A', 2, 'M112585', 'Matheus Felipe Seluzniak'),
  (21, 3, 'A', 3, 'J019571', 'Jan Roger Maia da Silva'),
  (22, 3, 'C', 1, 'F009869', 'Fernando Ehlke'),
  (23, 3, 'C', 2, 'G115750', 'Guilherme de Lucca Vicente'),
  (24, 3, 'C', 3, 'R063529', 'Rafael Zaganini Paschoal'),
  (25, 3, 'D', 1, 'N401103', 'Nathan Vinícius Sahdo'),
  (26, 3, 'D', 2, 'V056646', 'Victor Renner Magno de Aguiar'),
  (27, 3, 'D', 3, 'G463421', 'Gabriel da Silva');

-- 1. OS de cada núcleo.
UPDATE esc_nucleo n
  JOIN tmp_es21_os t ON t.nucleo_id = n.id
   SET n.decurio_code = t.rep_code, n.decurio_nome = t.nome
 WHERE n.unidade = t.unidade AND n.turno = t.turno COLLATE utf8mb4_unicode_ci AND n.numero = t.numero
   AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-01');

-- 2. Configuração de quem é OS (inclui os 4 que eram SR): função OS, unidade, turno e núcleo do quadro.
UPDATE esc_colaborador_config c
  JOIN tmp_es21_os t ON t.rep_code = c.rep_code COLLATE utf8mb4_unicode_ci
   SET c.funcao = 'DECURIO', c.tipo = 'NUCLEO', c.unidade = t.unidade, c.turno = t.turno, c.nucleo_id = t.nucleo_id,
       c.nivel = NULL, c.fatia_regular = NULL,
       c.observacao = 'OS pelo quadro de 29/10 (ES_21)'
 WHERE c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL;

-- 3. Os 4 novos OS saem das vagas fixas de SR (fecham em 31/10; o histórico fica na tabela).
UPDATE esc_vaga_equipe
   SET vigente_desde = LEAST(vigente_desde, '2026-10-31'), vigente_ate = '2026-10-31',
       motivo = LEFT(CONCAT('virou OS (quadro de 29/10); ', COALESCE(motivo, '')), 255)
 WHERE rep_code IN ('I351554', 'J108431', 'M408844', 'A177909')
   AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');

-- 4. OS de hoje fora do quadro: SR nível 5 no mesmo núcleo.
UPDATE esc_colaborador_config
   SET funcao = 'EQUITE', nivel = 5, observacao = 'saiu de OS no quadro de 29/10: SR nível 5 no mesmo núcleo (ES_21)'
 WHERE rep_code IN ('T143032', 'J104720', 'C099427')
   AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL AND funcao = 'DECURIO';

-- 5. OC da unidade 3: Henrique Dubles no turno D e Pedro Ribeiro no C
--    (turno preenchido = OC; vale com o nível 'OM' de antes do ES_19 ou 'OC' de depois).
UPDATE esc_lideranca SET rep_code = 'H122585', nome = 'Henrique Dubles Bisneto'
 WHERE unidade = 3 AND turno = 'D' AND vigente_ate IS NULL;
UPDATE esc_lideranca SET rep_code = 'P094137', nome = 'Pedro Ribeiro da Silva Neto'
 WHERE unidade = 3 AND turno = 'C' AND vigente_ate IS NULL;

-- Conferência
SELECT n.unidade, n.turno, n.numero, n.decurio_code, n.decurio_nome,
       IF(n.decurio_code = t.rep_code COLLATE utf8mb4_unicode_ci, 'ok', 'DIFERENTE') AS quadro
  FROM esc_nucleo n JOIN tmp_es21_os t ON t.nucleo_id = n.id
 WHERE n.vigente_ate IS NULL ORDER BY n.unidade, FIELD(n.turno, 'D', 'C', 'A'), n.numero;
SELECT 'OS com código nos núcleos' AS item, COUNT(*) AS tem, '27' AS esperado FROM esc_nucleo WHERE vigente_ate IS NULL AND decurio_code IS NOT NULL
UNION ALL SELECT 'configurações de OS vigentes em 01/11', COUNT(*), '27' FROM esc_colaborador_config WHERE funcao = 'DECURIO' AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL
UNION ALL SELECT 'OS com núcleo da configuração igual ao do quadro', COUNT(*), '27'
  FROM esc_colaborador_config c JOIN tmp_es21_os t ON t.rep_code = c.rep_code COLLATE utf8mb4_unicode_ci
 WHERE c.funcao = 'DECURIO' AND c.nucleo_id = t.nucleo_id AND c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL
UNION ALL SELECT 'vagas fixas abertas dos 4 novos OS', COUNT(*), '0' FROM esc_vaga_equipe
 WHERE rep_code IN ('I351554', 'J108431', 'M408844', 'A177909') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01')
UNION ALL SELECT 'ex-OS como SR nível 5', COUNT(*), '3' FROM esc_colaborador_config
 WHERE rep_code IN ('T143032', 'J104720', 'C099427') AND funcao = 'EQUITE' AND nivel = 5 AND vigente_ate IS NULL
UNION ALL SELECT 'SRs vigentes em 01/11', COUNT(*), '238 (239 − 4 novos OS + 3 ex-OS)' FROM esc_colaborador_config
 WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= '2026-11-01' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
SELECT unidade, turno, rep_code, nome FROM esc_lideranca WHERE unidade = 3 AND turno IS NOT NULL AND vigente_ate IS NULL ORDER BY turno;
DROP TEMPORARY TABLE IF EXISTS tmp_es21_os;
