-- =====================================================================
-- ES_19_lideranca_escala.sql  (29/10)
-- Nomes novos da liderança e escala 5x2 dela, separada da dos SRs.
--   - OUM passa a ser Operational Manager (OM): 3, um por unidade, sem escala.
--   - OM passa a ser Operational Coordinator (OC): 9, um por unidade e turno
--     (3 por turno), 5x2 no ciclo de 21 dias.
--   - OS (Operational Supervisor): 27, um por núcleo, 5x2 com o núcleo.
--   - OA (Operational Assistant): cargo novo, sem unidade. 1 por turno, todo dia
--     (24/7), numa semana fixa (padrao_semana, seg a dom: A, C, D ou '.' = folga).
--     Os 4 OAs fazem 5 turnos cada (20) e o Giovanni (supervisor) cobre 1:
--       Marcelo  ..AAAAA   (só A)
--       Ian      AACCC..   (A ou C)
--       Daniel   CDD..CC   (C ou D)
--       Diego    D..DDDD   (só D)
--       Giovanni .C.....   (cobre 1 turno)
--     Quem faz dois turnos sempre muda para o mais tarde (11 h de descanso).
-- OC e OS não têm o dia de treinamento separado dos SRs: o 15º dia de trabalho
-- cai na mesma posição do ciclo para todos (v4_lideranca_overlap_posicao = 5).
-- Como cada um tem um deslocamento, nesse dia os 3 do grupo estão no turno
-- (no sábado, com a âncora de 02/11); nos outros dias, 2 de 3.
-- Os 3 OC de cada turno usam os deslocamentos dos núcleos: U1 = núcleo 1 (0),
-- U2 = núcleo 2 (14), U3 = núcleo 3 (7).
-- Precisa do zip do sistema de 29/10 (tela Escala da liderança).
-- Pode rodar de novo: a troca de nomes só acontece enquanto houver 'OUM'.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- 1. Nomes: OM → OC e OUM → OM, de uma vez (só na primeira execução).
ALTER TABLE esc_lideranca MODIFY nivel ENUM('OUM','OM','OC','OA') NOT NULL;
SET @esc_tem_oum = (SELECT COUNT(*) FROM esc_lideranca WHERE nivel = 'OUM');
UPDATE esc_lideranca
   SET nivel = CASE nivel WHEN 'OM' THEN 'OC' WHEN 'OUM' THEN 'OM' END
 WHERE nivel IN ('OM', 'OUM') AND @esc_tem_oum > 0;
ALTER TABLE esc_lideranca
  MODIFY nivel   ENUM('OM','OC','OA') NOT NULL COMMENT 'OM = Operational Manager (unidade); OC = Operational Coordinator (unidade × turno); OA = Operational Assistant (turno)',
  MODIFY unidade TINYINT UNSIGNED NULL COMMENT 'OM e OC: unidade; OA: NULL',
  MODIFY turno   ENUM('A','C','D') NULL COMMENT 'OC: turno; OA: NULL (vem da padrao_semana); OM: NULL',
  MODIFY origem  ENUM('CARGA_27OUT','CARGA_29OUT','PLANNER','TELA') NOT NULL DEFAULT 'TELA',
  MODIFY team_id INT NULL COMMENT 'teamId do historicplanner (OC)',
  COMMENT = 'Operational Manager, Operational Coordinator e Operational Assistant, com vigência';

-- 2. Deslocamento do OC no ciclo de 21 dias (NULL = o do núcleo de mesmo número da unidade).
DROP PROCEDURE IF EXISTS esc_tmp_add_coluna;
DELIMITER $$
CREATE PROCEDURE esc_tmp_add_coluna(IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna) THEN
    SET @esc_sql = CONCAT('ALTER TABLE `5c_escalas`.`', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_def);
    PREPARE s FROM @esc_sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;
CALL esc_tmp_add_coluna('esc_lideranca', 'deslocamento', "TINYINT UNSIGNED NULL COMMENT 'OC e OA: ponto de partida no ciclo de 21 dias (como o núcleo); NULL = OC: o do núcleo de mesmo número da unidade; OA: 0 e 14 pela ordem no turno' AFTER team_id");
CALL esc_tmp_add_coluna('esc_lideranca', 'padrao_semana', "CHAR(7) NULL COMMENT 'OA: turno de cada dia, seg a dom (A, C, D ou . = folga); a semana se repete' AFTER deslocamento");
DROP PROCEDURE IF EXISTS esc_tmp_add_coluna;

UPDATE esc_lideranca l
  JOIN (SELECT numero, MIN(deslocamento) AS d FROM esc_nucleo WHERE vigente_ate IS NULL GROUP BY numero) n ON n.numero = l.unidade
   SET l.deslocamento = n.d
 WHERE l.nivel = 'OC' AND l.deslocamento IS NULL;

-- 3. Operational Assistants (headcount de 29/10: Commercial Agent e Commercial Agent Supervisor).
INSERT INTO esc_lideranca (nivel, unidade, turno, rep_code, nome, padrao_semana, vigente_desde, origem)
SELECT t.nivel, NULL, NULL, t.rep_code, t.nome, t.padrao, '2026-11-01', 'CARGA_29OUT'
  FROM (SELECT 'OA' AS nivel, 'M065522' AS rep_code, 'Marcelo Feliciano Figueiredo' AS nome, '..AAAAA' AS padrao
        UNION ALL SELECT 'OA', 'I046054', 'Ian de Castro Felix', 'AACCC..'
        UNION ALL SELECT 'OA', 'D401716', 'Daniel Ignácio Silva', 'CDD..CC'
        UNION ALL SELECT 'OA', 'P072162', 'Paulo Diego Lima Leite', 'D..DDDD'
        UNION ALL SELECT 'OA', 'G425075', 'Giovanni Fusse Neira', '.C.....') t
 WHERE NOT EXISTS (SELECT 1 FROM esc_lideranca l WHERE l.nivel = 'OA' AND l.rep_code = t.rep_code COLLATE utf8mb4_unicode_ci AND l.vigente_ate IS NULL);

-- 4. Dia de Overlap da liderança (OC e OS).
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
VALUES ('v4_lideranca_overlap_posicao', '5', 'TEXTO', 'v4',
        'OC e OS: posição no ciclo de 21 dias (0 a 20) do 15º dia de trabalho, a mesma para todos; nesse dia os 3 do grupo estão no turno')
    AS n ON DUPLICATE KEY UPDATE descricao = n.descricao;

-- Conferência
SELECT nivel, turno, unidade, rep_code, nome, deslocamento, padrao_semana,
       CASE nivel WHEN 'OM' THEN 'Operational Manager' WHEN 'OC' THEN 'Operational Coordinator' ELSE 'Operational Assistant' END AS cargo
  FROM esc_lideranca WHERE vigente_ate IS NULL ORDER BY nivel DESC, turno, unidade;
SELECT 'OM (Operational Manager)' AS item, COUNT(*) AS tem, '3' AS esperado FROM esc_lideranca WHERE nivel = 'OM' AND vigente_ate IS NULL
UNION ALL SELECT 'OC (Operational Coordinator)', COUNT(*), '9' FROM esc_lideranca WHERE nivel = 'OC' AND vigente_ate IS NULL
UNION ALL SELECT 'OC sem deslocamento', COUNT(*), '0' FROM esc_lideranca WHERE nivel = 'OC' AND vigente_ate IS NULL AND deslocamento IS NULL
UNION ALL SELECT 'OA (4 Operational Assistants + Giovanni)', COUNT(*), '5' FROM esc_lideranca WHERE nivel = 'OA' AND vigente_ate IS NULL
UNION ALL SELECT 'OA: turnos na semana (precisa 21)', SUM(7 - (LENGTH(padrao_semana) - LENGTH(REPLACE(padrao_semana, '.', '')))), '21' FROM esc_lideranca WHERE nivel = 'OA' AND vigente_ate IS NULL
UNION ALL SELECT 'OS (núcleos com OS)', COUNT(*), '27' FROM esc_nucleo WHERE vigente_ate IS NULL AND decurio_nome IS NOT NULL
UNION ALL SELECT 'OS sem código', COUNT(*), '1 (Isabel da Silva Marinho, U2 A núcleo 3)' FROM esc_nucleo WHERE vigente_ate IS NULL AND decurio_code IS NULL;
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_lideranca_overlap_posicao';
