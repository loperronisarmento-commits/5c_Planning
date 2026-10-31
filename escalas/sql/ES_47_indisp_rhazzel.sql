-- =====================================================================
-- ES_47_indisp_rhazzel.sql  (31/10)
-- Indisponibilidade recorrente, já aprovada, de Rhazzel John Manabo Marfa
-- (R025361, SR U1 D): todos os dias de 01/11/2026 a 01/02/2027, o turno inteiro
-- (para só alguns blocos, troque @blocos, ex.: 'T6' ou 'T6,T1').
-- Fica igual a um pedido lançado pelo WFM e aprovado na tela Indisponibilidade:
-- o motor tira o Rhazzel da escala nesses dias (folga INDISPONIBILIDADE) e o
-- buffer cobre. Não roda duas vezes (confere se o pedido já existe).
-- Depois: recalcular as semanas a partir de 02/11 (e a de 26/10, se quiser
-- valer já em 01/11).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- Colação das colunas (a da conexão do Navicat é outra e dá erro 1267 ao comparar).
SET @rep := 'R025361' COLLATE utf8mb4_unicode_ci;
SET @de := '2026-11-01';
SET @ate := '2027-02-01' COLLATE utf8mb4_unicode_ci;
SET @blocos := NULL; -- NULL = turno inteiro
SET @txt := 'Indisponível todos os dias até 01/02/2027 (lançado e aprovado pelo planejamento, ES_47)';

SET @ja := (SELECT COUNT(*) FROM esc_indisponibilidade
             WHERE UPPER(rep_code) = @rep AND tipo = 'RECORRENTE' AND status = 'APROVADO'
               AND CONVERT(JSON_UNQUOTE(JSON_EXTRACT(recorrencia, '$.ate')) USING utf8mb4) COLLATE utf8mb4_unicode_ci = @ate);
SET @turno := (SELECT turno FROM esc_colaborador_config
                WHERE UPPER(rep_code) = @rep AND funcao = 'EQUITE' AND vigente_desde <= @de AND (vigente_ate IS NULL OR vigente_ate >= @de)
                ORDER BY vigente_desde DESC LIMIT 1);

START TRANSACTION;

INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status, solicitado_em, solicitado_por, decidido_em, comentario_decisao)
SELECT @rep, 'RECORRENTE', 'WFM', 'OUTRO', @txt, JSON_OBJECT('dias_semana', JSON_ARRAY(1, 2, 3, 4, 5, 6, 7), 'ate', @ate), 1, 'APROVADO', NOW(), 'ES_47', NOW(), 'Aprovado no lançamento (ES_47)'
  FROM DUAL WHERE @ja = 0 AND @turno IS NOT NULL;
SET @id := IF(@ja = 0 AND @turno IS NOT NULL, LAST_INSERT_ID(), NULL);

INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em, comentario)
SELECT @id, DATE_ADD(@de, INTERVAL n.n DAY), @turno, @blocos, 'APROVADO', NOW(), 'Aprovado no lançamento (ES_47)'
  FROM (SELECT a.d + 10 * b.d + 100 * c.d AS n
          FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
          CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
          CROSS JOIN (SELECT 0 AS d UNION ALL SELECT 1) c) n
 WHERE @id IS NOT NULL AND DATE_ADD(@de, INTERVAL n.n DAY) <= @ate;

INSERT INTO esc_log (acao, entidade, entidade_id, depois, canal)
SELECT 'indisp.lancar', 'esc_indisponibilidade', @id, JSON_OBJECT('rep', @rep, 'de', @de, 'ate', @ate, 'turno', @turno, 'status', 'APROVADO', 'script', 'ES_47'), 'SISTEMA'
  FROM DUAL WHERE @id IS NOT NULL;

COMMIT;

-- Conferência: 93 dias aprovados (01/11/2026 a 01/02/2027) no turno D
SELECT @ja AS ja_existia, @turno AS turno, @id AS pedido;
SELECT i.id, i.rep_code, i.tipo, i.status, COUNT(d.id) AS dias, MIN(d.dia) AS de, MAX(d.dia) AS ate, GROUP_CONCAT(DISTINCT d.turno) AS turno, GROUP_CONCAT(DISTINCT d.status) AS status_dias
  FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id
 WHERE UPPER(i.rep_code) = @rep GROUP BY i.id ORDER BY i.id DESC;
