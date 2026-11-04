SET NAMES utf8mb4;
-- =====================================================================
-- ES_94_mariana_vilela_afastada.sql  (03/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário: a Mariana Vilela (M443425, U1 turno C) está afastada, por tempo indeterminado,
-- e tinha sido escalada (v31.0: Mikayla Paid em 06, 07 e 08/11). Indisponibilidade aprovada, como a
-- licença do id 1: uma linha por dia no turno dela, de 04/11 a 31/12/2026. Quando ela voltar,
-- cancelar os dias que sobrarem (status CANCELADO) na tela de pedidos ou por script.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

INSERT INTO esc_indisponibilidade (rep_code, tipo, canal, motivo_categoria, motivo_texto, recorrencia, urgente, status,
                                   solicitado_em, solicitado_por, decidido_em, decidido_por, comentario_decisao)
SELECT 'M443425', 'VARIOS_DIAS', 'WFM', 'OUTRO', 'Afastada por tempo indeterminado (pedido do Planejamento em 03/11; ES_94)', NULL, 1, 'APROVADO',
       NOW(), NULL, NOW(), NULL, 'Registrado pelo Claude a pedido do Planejamento; revisar quando ela voltar'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_indisponibilidade WHERE rep_code = 'M443425' AND motivo_texto LIKE '%ES_94%');
SET @ind = (SELECT id FROM esc_indisponibilidade WHERE rep_code = 'M443425' AND motivo_texto LIKE '%ES_94%');

INSERT INTO esc_indisponibilidade_data (indisponibilidade_id, dia, turno, blocos, status, decidido_em, decidido_por, comentario)
WITH RECURSIVE d (dia) AS (SELECT DATE('2026-11-04') UNION ALL SELECT dia + INTERVAL 1 DAY FROM d WHERE dia < '2026-12-31')
SELECT @ind, d.dia, 'C', NULL, 'APROVADO', NOW(), NULL, 'afastada (ES_94)'
  FROM d WHERE NOT EXISTS (SELECT 1 FROM esc_indisponibilidade_data x WHERE x.indisponibilidade_id = @ind AND x.dia = d.dia);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'indisponibilidade.registrar', 'esc_indisponibilidade', @ind,
        NULL, '{"script":"ES_94","rep":"M443425","de":"2026-11-04","ate":"2026-12-31","turno":"C","motivo":"afastada, indeterminado"}', NULL);

COMMIT;

-- Conferência
SELECT i.id, i.rep_code, i.status, MIN(d.dia) AS de, MAX(d.dia) AS ate, COUNT(*) AS dias
  FROM esc_indisponibilidade i JOIN esc_indisponibilidade_data d ON d.indisponibilidade_id = i.id
 WHERE i.id = @ind GROUP BY i.id;
