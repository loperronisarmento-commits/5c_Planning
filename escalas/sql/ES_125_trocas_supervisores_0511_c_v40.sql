SET NAMES utf8mb4;
-- =====================================================================
-- ES_125_trocas_supervisores_0511_c_v40.sql  (05/11, aplicado pelo Claude via mysql.exe)
--
-- Reaplica o ES_124: a v40 foi gerada do zero e saiu sem as trocas. Trocas pedidas pelos supervisores para 05/11, turno C (T4/T5), no rascunho criado a partir da v40 publicada (plano 116), semana de 02/11,
-- que vai ser postado a partir de 05/11 C. Fica como a edição manual da Grade: cada célula (página × bloco)
-- citada é trocada inteira e travada (origem MANUAL); recálculos mantêm.
--   - Lily Lou OFTV (U3): sai Victor Stangl (V088215, já não estava no v39), entra John Balasta (J010257);
--     J337675 e R471964 seguem. O John continua também na Jessie e na Aja (como no v39): a confirmar.
--   - U2 C: a lista do supervisor (quem faz cada página). Quem está na lista e estava em outra página
--     no mesmo bloco sai dela; a célula que ficar vazia vira lacuna travada.
-- =====================================================================

USE `5c_escalas`;

-- Rascunho da semana de 02/11 criado depois da v40 (Grade › "Criar rascunho a partir desta"). Sem ele, nada muda.
SET @p := (SELECT MAX(id) FROM esc_plano WHERE semana_inicio = '2026-11-02' AND time_nome = '' AND status = 'RASCUNHO' AND id > 116);
SELECT @p AS plano_alvo, (SELECT CONCAT('v', versao, '.', subversao) FROM esc_plano WHERE id = @p) AS versao;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_sup;
CREATE TEMPORARY TABLE tmp_sup (pagina VARCHAR(150) NOT NULL, page_id INT NOT NULL, rep CHAR(7) NOT NULL, papel VARCHAR(12) NOT NULL, tira TINYINT NOT NULL);
INSERT INTO tmp_sup VALUES
 ('Lily Lou OFTV', 479, 'J337675', 'REGULAR', 0), ('Lily Lou OFTV', 479, 'R471964', 'REGULAR', 0), ('Lily Lou OFTV', 479, 'J010257', 'REGULAR', 0),
 ('Brandi Love', 373, 'V848567', 'REGULAR', 1), ('Brandi Love', 373, 'M175553', 'ASSISTENTE', 1),
 ('Brandi Love FREE', 375, 'V848567', 'REGULAR', 1), ('Brandi Love FREE', 375, 'M175553', 'ASSISTENTE', 1),
 ('CJ VIP', 29, 'F092951', 'REGULAR', 1), ('Yuni Yung', 456, 'F092951', 'REGULAR', 1), ('Apex VIP', 462, 'F092951', 'REGULAR', 1), ('Apex Free', 463, 'F092951', 'REGULAR', 1),
 ('CJ Miles', 15, 'N027675', 'REGULAR', 1), ('Keera', 483, 'N027675', 'REGULAR', 1),
 ('Draya', 53, 'A099370', 'REGULAR', 1), ('Sophie Sierra', 487, 'A099370', 'REGULAR', 1), ('Sarah H 329m', 478, 'A099370', 'REGULAR', 1),
 ('Nicole Doshi', 64, 'F156592', 'REGULAR', 1), ('Nicole OFTV', 103, 'F156592', 'REGULAR', 1),
 ('Midwest Emma Free 221s', 229, 'L434531', 'REGULAR', 1), ('Victorya 188m', 272, 'L434531', 'REGULAR', 1),
 ('Marie Dee VIP', 482, 'T143032', 'REGULAR', 1),
 ('Alix Lynx', 86, 'J058769', 'REGULAR', 1),
 ('Molly Stewart VIP', 121, 'L052002', 'REGULAR', 1),
 ('Ariana Marie', 125, 'D129107', 'REGULAR', 1),
 ('Mo Saffari', 333, 'H106569', 'REGULAR', 1), ('Matty Caron', 418, 'H106569', 'REGULAR', 1), ('Sebi Barraza', 416, 'H106569', 'REGULAR', 1),
 ('Ellie D', 430, 'J477490', 'REGULAR', 1), ('Blair VIP 155m', 220, 'J477490', 'REGULAR', 1), ('Blair OFTV 155t', 281, 'J477490', 'REGULAR', 1),
 ('Sean Adams', 447, 'J703805', 'REGULAR', 1), ('Jay', 448, 'J703805', 'REGULAR', 1),
 ('Aline Novak', 480, 'C021331', 'REGULAR', 1);
DROP TEMPORARY TABLE IF EXISTS tmp_sup2;
CREATE TEMPORARY TABLE tmp_sup2 AS SELECT * FROM tmp_sup;
DROP TEMPORARY TABLE IF EXISTS tmp_pags;
CREATE TEMPORARY TABLE tmp_pags AS SELECT DISTINCT page_id, pagina FROM tmp_sup;

-- 1. Quem está na lista sai das outras páginas do bloco (não citadas para ele)
DROP TEMPORARY TABLE IF EXISTS tmp_sai;
CREATE TEMPORARY TABLE tmp_sai AS
SELECT a.id, a.page_id, a.pagina, a.bloco, UPPER(a.rep_code) AS rep
  FROM esc_plano_alocacao a
 WHERE a.plano_id = @p AND a.dia = '2026-11-05' AND a.bloco IN ('T4', 'T5')
   AND UPPER(a.rep_code) IN (SELECT rep FROM tmp_sup WHERE tira = 1)
   AND a.page_id NOT IN (SELECT page_id FROM tmp_pags)
   AND NOT EXISTS (SELECT 1 FROM tmp_sup2 s WHERE s.rep = UPPER(a.rep_code) AND s.page_id = a.page_id);
DELETE a FROM esc_plano_alocacao a JOIN tmp_sai s ON s.id = a.id;
-- célula que esvaziou vira lacuna travada; a que ficou com gente fica travada como está
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT DISTINCT @p, '2026-11-05', s.bloco, 'C', s.page_id, s.pagina, NULL, 'REGULAR', 'LACUNA', 1, 'troca dos supervisores 05/11 C: a pessoa foi para outra página (ES_125)', NOW(), NULL
  FROM tmp_sai s
 WHERE NOT EXISTS (SELECT 1 FROM esc_plano_alocacao a WHERE a.plano_id = @p AND a.dia = '2026-11-05' AND a.bloco = s.bloco AND a.page_id = s.page_id);
UPDATE esc_plano_alocacao a JOIN (SELECT DISTINCT page_id, bloco FROM tmp_sai) s ON s.page_id = a.page_id AND s.bloco = a.bloco
   SET a.travada = 1, a.alterado_em = NOW()
 WHERE a.plano_id = @p AND a.dia = '2026-11-05';

-- 2. Páginas citadas: a célula inteira como o supervisor mandou, travada
DELETE a FROM esc_plano_alocacao a JOIN tmp_pags p ON p.page_id = a.page_id
 WHERE a.plano_id = @p AND a.dia = '2026-11-05' AND a.bloco IN ('T4', 'T5');
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @p, '2026-11-05', b.bloco, 'C', s.page_id, s.pagina, s.rep, s.papel, 'MANUAL', 1, 'troca dos supervisores 05/11 C (ES_125)', NOW(), NULL
  FROM tmp_sup s JOIN (SELECT 'T4' AS bloco UNION ALL SELECT 'T5') b;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', CONCAT(@p, ':2026-11-05:C:ES_125'),
       JSON_OBJECT('saiu_de', (SELECT JSON_ARRAYAGG(CONCAT(rep, ' ', pagina, ' ', bloco)) FROM tmp_sai)),
       JSON_OBJECT('script', 'ES_125', 'celulas', (SELECT COUNT(*) FROM tmp_pags) * 2), NULL;

COMMIT;

-- Conferência
SELECT s.rep, s.pagina, s.bloco FROM tmp_sai s ORDER BY s.rep, s.bloco, s.pagina;
SELECT a.bloco, a.pagina, GROUP_CONCAT(CONCAT(IFNULL(a.rep_code, '-'), IF(a.papel = 'ASSISTENTE', '#', '')) ORDER BY a.papel DESC SEPARATOR ',') AS reps
  FROM esc_plano_alocacao a
 WHERE a.plano_id = @p AND a.dia = '2026-11-05' AND a.bloco = 'T4' AND (a.page_id IN (SELECT page_id FROM tmp_pags) OR a.page_id IN (SELECT page_id FROM tmp_sai))
 GROUP BY a.bloco, a.pagina ORDER BY a.pagina;
