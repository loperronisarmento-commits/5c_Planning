SET NAMES utf8mb4;
-- =====================================================================
-- ES_147 · Rhazzel John Manabo Marfa (R025361, U1 D): OFF em 18/11 para consulta médica
-- (12/11/2026, pedido do usuário; aplicado pelo Claude via mysql.exe)
-- A indisponibilidade id 29 (01/11 a 01/02) já tem os dias de 16/11 em diante cancelados: ela voltou em 16/11.
-- Registra uma indisponibilidade nova de um dia (18/11, turno D, dia inteiro), já aprovada pelo WFM.
-- O Gerar da semana de 16/11 passa a deixá-la de fora nesse dia.
-- =====================================================================

USE `5c_escalas`;

SELECT 'antes' AS momento, i.id, i.tipo, i.status, d.dia, d.turno, d.status AS status_dia
  FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id
 WHERE i.rep_code = 'R025361' AND d.dia BETWEEN '2026-11-15' AND '2026-11-19' ORDER BY d.dia;

START TRANSACTION;
INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, anexo_url, recorrencia, urgente, status, solicitado_em, solicitado_por, decidido_em, decidido_por, comentario_decisao)
SELECT 'R025361', 'UM_DIA', 'WFM', 'SAUDE', 'Consulta médica em 18/11 (lançado e aprovado pelo planejamento, ES_147)', NULL, NULL, 0, 'APROVADO', NOW(), NULL, NOW(), NULL, 'Pedido do planejamento em 12/11'
  FROM DUAL WHERE NOT EXISTS (
    SELECT 1 FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id
     WHERE i.rep_code = 'R025361' AND d.dia = '2026-11-18' AND d.status = 'APROVADO');
INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em, decidido_por, comentario)
SELECT LAST_INSERT_ID(), '2026-11-18', 'D', NULL, 'APROVADO', NOW(), NULL, 'Consulta médica (ES_147)'
  FROM DUAL WHERE ROW_COUNT() = 1;
COMMIT;

SELECT 'depois' AS momento, i.id, i.tipo, i.status, d.dia, d.turno, d.status AS status_dia
  FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id
 WHERE i.rep_code = 'R025361' AND d.dia BETWEEN '2026-11-15' AND '2026-11-19' ORDER BY d.dia;
SELECT 'escala 16/11' AS momento, a.dia, a.turno, GROUP_CONCAT(DISTINCT a.pagina) AS paginas
  FROM esc_plano_alocacao a JOIN esc_plano p ON p.id = a.plano_id
 WHERE p.semana_inicio = '2026-11-16' AND p.status IN ('RASCUNHO', 'PUBLICADO') AND a.rep_code = 'R025361' AND a.dia = '2026-11-18'
 GROUP BY a.dia, a.turno;
