-- =====================================================================
-- ES_29_roster_lideranca_30out.sql  (30/10)
-- Liderança pelo roster de 30/10 (vigência 01/11; a estrutura ainda não começou,
-- então os registros de 01/11 são corrigidos no lugar):
--   - OC da U1 · T2/T3: Raphael Estevam (R095006, era SR 5 na U3 · T4/T5) no lugar
--     de Rafaela de Souza Gaspar (R107827). A Rafaela vira SR 5 na vaga dele:
--     mesmo núcleo (U3 · T4/T5, núcleo 3) e as 2 vagas fixas dele;
--   - OS da U2 · T6/T1: Guilherme de Lucca Vicente (OS 2, vinha da U3 · T4/T5) e
--     Gabriel da Silva (OS 3, vinha da U3 · T6/T1);
--   - OS da U3 · T6/T1: Nátali Bianchini Vieira (OS 3, vinha da U2 · T6/T1);
--   - OS da U3 · T4/T5: Carolina Xavier Broggio (OS 2, era SR 5 na U2 · T6/T1).
--     A Julia Xavier Broggio deixa de ser OS e vira SR 5 no lugar da Carolina
--     (U2 · T6/T1, núcleo 3). Se for o contrário, pule o bloco 4;
--   - nomes como no roster: Julia Vieira de Andrade, João Paulo Caial Pires,
--     Lucas dos Santos Caetano.
-- Os SRs continuam nos mesmos núcleos (quem muda é o OS que lidera o núcleo).
-- Depois: recalcular as semanas de 26/10 e 02/11 (Gerar escala › Recalcular) e,
-- se o Modo Treino estiver instalado, "Recomeçar o treino".
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es29_os;
CREATE TEMPORARY TABLE tmp_es29_os (
  nucleo_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  unidade   TINYINT UNSIGNED NOT NULL,
  turno     CHAR(1) NOT NULL,
  numero    TINYINT UNSIGNED NOT NULL,
  rep_code  VARCHAR(30) NOT NULL,
  nome      VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tmp_es29_os (nucleo_id, unidade, turno, numero, rep_code, nome) VALUES
  (10, 2, 'A', 1, 'J110041', 'João Paulo Caial Pires'),
  (12, 2, 'A', 3, 'L124965', 'Lucas dos Santos Caetano'),
  (13, 2, 'C', 1, 'J418568', 'Julia Vieira de Andrade'),
  (17, 2, 'D', 2, 'G115750', 'Guilherme de Lucca Vicente'),
  (18, 2, 'D', 3, 'G463421', 'Gabriel da Silva'),
  (23, 3, 'C', 2, 'C099427', 'Carolina Xavier Broggio'),
  (27, 3, 'D', 3, 'N108054', 'Nátali Bianchini Vieira');

-- 1. OS de cada núcleo (e os nomes corrigidos).
UPDATE esc_nucleo n
  JOIN tmp_es29_os t ON t.nucleo_id = n.id
   SET n.decurio_code = t.rep_code, n.decurio_nome = t.nome
 WHERE n.unidade = t.unidade AND n.turno = t.turno COLLATE utf8mb4_unicode_ci AND n.numero = t.numero
   AND (n.vigente_ate IS NULL OR n.vigente_ate >= '2026-11-01');

-- 2. Configuração de 01/11 de quem é OS: unidade, turno e núcleo do roster
--    (inclui a Carolina, que era SR 5).
UPDATE esc_colaborador_config c
  JOIN tmp_es29_os t ON t.rep_code = c.rep_code COLLATE utf8mb4_unicode_ci
   SET c.funcao = 'DECURIO', c.tipo = 'NUCLEO', c.unidade = t.unidade, c.turno = t.turno, c.nucleo_id = t.nucleo_id,
       c.nivel = NULL, c.fatia_regular = NULL,
       c.observacao = 'OS pelo roster de 30/10 (ES_29)'
 WHERE c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL
   AND t.rep_code IN ('G115750', 'G463421', 'C099427', 'N108054');

-- 3. OC da U1 · T2/T3: Raphael Estevam no lugar da Rafaela.
UPDATE esc_lideranca SET rep_code = 'R095006', nome = 'Raphael Estevam'
 WHERE nivel = 'OC' AND unidade = 1 AND turno = 'A' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');

--    A configuração de SR 5 dele (U3 · T4/T5, núcleo 3) e as 2 vagas fixas passam para a Rafaela.
--    Ele fica como os outros OC: só com a configuração de antes da virada, sem data de fim.
UPDATE esc_colaborador_config
   SET vigente_ate = '2026-10-31'
 WHERE rep_code = 'R107827' AND vigente_desde < '2026-11-01' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
SET @es29_rafaela := (SELECT COUNT(*) FROM esc_colaborador_config WHERE rep_code = 'R107827' AND vigente_desde = '2026-11-01');
UPDATE esc_colaborador_config
   SET rep_code = 'R107827', observacao = 'SR 5 na vaga do Raphael Estevam, que virou OC (ES_29)'
 WHERE rep_code = 'R095006' AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL AND @es29_rafaela = 0;
SET @es29_raphael := (SELECT COUNT(*) FROM esc_colaborador_config WHERE rep_code = 'R095006' AND vigente_desde >= '2026-11-01');
UPDATE esc_colaborador_config
   SET vigente_ate = NULL
 WHERE rep_code = 'R095006' AND vigente_desde < '2026-11-01' AND vigente_ate = '2026-10-31' AND @es29_raphael = 0;
UPDATE esc_vaga_equipe
   SET rep_code = 'R107827', motivo = LEFT(CONCAT('vaga do Raphael Estevam, que virou OC (ES_29); ', COALESCE(motivo, '')), 255)
 WHERE rep_code = 'R095006' AND vigente_desde >= '2026-11-01' AND vigente_ate IS NULL;

-- 4. Julia Xavier Broggio: deixa de ser OS e vira SR 5 no lugar da Carolina (U2 · T6/T1, núcleo 3).
UPDATE esc_colaborador_config
   SET funcao = 'EQUITE', nivel = 5, tipo = 'NUCLEO', unidade = 2, turno = 'D', nucleo_id = 18,
       observacao = 'saiu de OS no roster de 30/10: SR nível 5 no núcleo da Carolina (ES_29)'
 WHERE rep_code = 'J108431' AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL;

-- Conferência
SELECT n.unidade, n.turno, n.numero, n.decurio_code, n.decurio_nome,
       IF(n.decurio_code = t.rep_code COLLATE utf8mb4_unicode_ci, 'ok', 'DIFERENTE') AS roster
  FROM esc_nucleo n JOIN tmp_es29_os t ON t.nucleo_id = n.id
 WHERE n.vigente_ate IS NULL ORDER BY n.unidade, FIELD(n.turno, 'D', 'A', 'C'), n.numero;
SELECT 'OS com código nos núcleos' AS item, COUNT(*) AS tem, '27' AS esperado FROM esc_nucleo WHERE vigente_ate IS NULL AND decurio_code IS NOT NULL
UNION ALL SELECT 'configurações de OS vigentes em 01/11', COUNT(*), '27' FROM esc_colaborador_config WHERE funcao = 'DECURIO' AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL
UNION ALL SELECT 'OS com o núcleo da configuração igual ao do núcleo', COUNT(*), '27'
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.decurio_code = c.rep_code AND n.vigente_ate IS NULL
 WHERE c.funcao = 'DECURIO' AND c.nucleo_id = n.id AND c.vigente_desde = '2026-11-01' AND c.vigente_ate IS NULL
UNION ALL SELECT 'OC da U1 T2/T3 = Raphael Estevam', COUNT(*), '1' FROM esc_lideranca WHERE nivel = 'OC' AND unidade = 1 AND turno = 'A' AND rep_code = 'R095006' AND vigente_ate IS NULL
UNION ALL SELECT 'Rafaela SR 5 no núcleo 24 em 01/11', COUNT(*), '1' FROM esc_colaborador_config WHERE rep_code = 'R107827' AND funcao = 'EQUITE' AND nivel = 5 AND nucleo_id = 24 AND vigente_desde = '2026-11-01' AND vigente_ate IS NULL
UNION ALL SELECT 'vagas fixas abertas da Rafaela', COUNT(*), '2' FROM esc_vaga_equipe WHERE rep_code = 'R107827' AND vigente_ate IS NULL
UNION ALL SELECT 'vagas fixas abertas do Raphael', COUNT(*), '0' FROM esc_vaga_equipe WHERE rep_code = 'R095006' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01')
UNION ALL SELECT 'Julia SR 5 no núcleo 18', COUNT(*), '1' FROM esc_colaborador_config WHERE rep_code = 'J108431' AND funcao = 'EQUITE' AND nivel = 5 AND nucleo_id = 18 AND vigente_ate IS NULL
UNION ALL SELECT 'SRs vigentes em 01/11', COUNT(*), 'igual a antes (Raphael sai, Rafaela entra; Carolina sai, Julia entra)' FROM esc_colaborador_config
 WHERE funcao = 'EQUITE' AND nucleo_id IS NOT NULL AND vigente_desde <= '2026-11-01' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01');
SELECT nivel, unidade, turno, rep_code, nome FROM esc_lideranca WHERE nivel IN ('OM', 'OC') AND vigente_ate IS NULL ORDER BY unidade, FIELD(turno, 'D', 'A', 'C');
DROP TEMPORARY TABLE IF EXISTS tmp_es29_os;
