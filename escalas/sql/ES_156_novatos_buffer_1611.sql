SET NAMES utf8mb4;
-- =====================================================================
-- ES_156_novatos_buffer_1611.sql  (13/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário (13/11): os novatos com destino confirmado no site (Turmas de treinamento, NH45, 12 pessoas)
-- entram todos como buffer das unidades na semana de 16/11; depois os OM decidem onde alocar.
-- Buffer na 5C Schedule (page 3), origem BUFFER, travado, nos dias de trabalho do núcleo de destino pelo ciclo 5x2 de 21 dias
-- (âncora 02/11): A = T2 e T3; C = T4 e T5; D = T6 do dia e T1 do dia seguinte. Não mexe no cadastro (esc_colaborador_config).
-- Depois de um recálculo da semana de 16/11, reaplicar depois do ES_153, do ES_154 e do ES_155.
-- =====================================================================

USE `5c_escalas`;
SET @plano := 151;

DROP TEMPORARY TABLE IF EXISTS tmp_buf;
CREATE TEMPORARY TABLE tmp_buf (dia DATE NOT NULL, bloco CHAR(2) NOT NULL, turno CHAR(1) NOT NULL, rep VARCHAR(30) NOT NULL, motivo VARCHAR(255) NOT NULL)
  DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_buf VALUES
 ('2026-11-17', 'T2', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T3', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T2', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T3', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T2', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T3', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T2', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T3', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T2', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T3', 'A', 'A017276', 'novato NH45A2026, destino U1 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T4', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T5', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T4', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T5', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'F439690', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T4', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T5', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T4', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T5', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'L077775', 'novato NH45C2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T6', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T1', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T6', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T1', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T6', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T1', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T6', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T1', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T6', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-23', 'T1', 'D', 'A501236', 'novato NH45D2026, destino U1 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T2', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T3', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T2', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T3', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T2', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T3', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T2', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T3', 'A', 'J090827', 'novato NH45A2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T4', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T5', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T4', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T5', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'L437773', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T4', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T5', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T4', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T5', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'M480846', 'novato NH45C2026, destino U2 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T4', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T5', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T4', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T5', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'R115033', 'novato NH45C2026, destino U2 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T6', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T1', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T6', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T1', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T6', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T1', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T6', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T1', 'D', 'H134329', 'novato NH45D2026, destino U2 núcleo 3: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T2', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T3', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T2', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T3', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T2', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T3', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T2', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T3', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T2', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T3', 'A', 'L704791', 'novato NH45A2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T4', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-16', 'T5', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T4', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T5', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T4', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T5', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T4', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T5', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T4', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T5', 'C', 'V123144', 'novato NH45C2026, destino U3 núcleo 1: buffer até o OM alocar (ES_156)'),
 ('2026-11-17', 'T6', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T1', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-18', 'T6', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T1', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-19', 'T6', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-20', 'T1', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-21', 'T6', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T1', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-22', 'T6', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)'),
 ('2026-11-23', 'T1', 'D', 'V422869', 'novato NH45D2026, destino U3 núcleo 2: buffer até o OM alocar (ES_156)');

START TRANSACTION;
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @plano, b.dia, b.bloco, b.turno, 3, '5C Schedule', NULL, NULL, b.rep, 'REGULAR', 'BUFFER', 1, b.motivo, NOW(), NULL
  FROM tmp_buf b
 WHERE NOT EXISTS (SELECT 1 FROM esc_plano_alocacao a WHERE a.plano_id = @plano AND a.dia = b.dia AND a.bloco = b.bloco AND UPPER(a.rep_code) = b.rep COLLATE utf8mb4_unicode_ci);
INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', CONCAT(@plano, ':2026-11-16:ES_156'), NULL,
       JSON_OBJECT('script', 'ES_156', 'novatos', 12, 'linhas', (SELECT COUNT(*) FROM tmp_buf)), NULL;
COMMIT;

SELECT COUNT(*) AS buffer_novatos, COUNT(DISTINCT rep_code) AS pessoas FROM esc_plano_alocacao WHERE plano_id = @plano AND motivo LIKE '%(ES_156)';
