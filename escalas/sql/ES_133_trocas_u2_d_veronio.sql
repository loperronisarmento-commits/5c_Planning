SET NAMES utf8mb4;
-- =====================================================================
-- ES_133_trocas_u2_d_veronio.sql  (08/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário (08/11), no rascunho v3.6 da semana de 09/11. Turno D = T6 do dia + T1 do dia seguinte.
-- Células trocadas inteiras e travadas (MANUAL); buffer na 5C Schedule (-2), travado.
--   Alix Lynx 09/11 D: Veronio Junior (V412402) regular; Marina Silva (M029962) vai para o buffer.
--   Nicole Doshi 10/11 D: Veronio regular; Marina Silva assistente.
--   Midwest Emma Free 221s 11/11 D: Veronio regular; Pamela Santos (P155379) assistente (a Victorya 188m segue com ela).
--   Marie Dee VIP 12/11 D: Veronio regular; Gabrielly Barreiros (G029092) vai para o buffer.
--   CJ VIP e CJ Miles 15/11 D: Veronio regular. A Pamela, que era a regular da CJ VIP, fica de assistente nela
--     (confirmado pelo usuário em 08/11).
--   Anna Lícia Goslar (A012621, U3 C núcleo 3), que estava sem nada na semana: buffer no T4/T5 dos dias de trabalho
--     do núcleo (09, 10, 13, 14 e 15/11).
-- Depois de um recálculo, reaplicar na ordem: ES_128, ES_130, ES_132 e este.
-- =====================================================================

USE `5c_escalas`;
SET @plano := 132;

DROP TEMPORARY TABLE IF EXISTS tmp_cel;
CREATE TEMPORARY TABLE tmp_cel (dia DATE NOT NULL, bloco CHAR(2) NOT NULL, turno CHAR(1) NOT NULL, page_id INT NOT NULL, pagina VARCHAR(150) NOT NULL,
  rep VARCHAR(30) NULL, papel VARCHAR(12) NOT NULL, origem VARCHAR(20) NOT NULL) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_cel VALUES
 ('2026-11-09', 'T6', 'D', 86, 'Alix Lynx', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-10', 'T1', 'D', 86, 'Alix Lynx', 'V412402', 'REGULAR', 'MANUAL'),
 ('2026-11-10', 'T6', 'D', 64, 'Nicole Doshi', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-11', 'T1', 'D', 64, 'Nicole Doshi', 'V412402', 'REGULAR', 'MANUAL'),
 ('2026-11-10', 'T6', 'D', 64, 'Nicole Doshi', 'M029962', 'ASSISTENTE', 'MANUAL'), ('2026-11-11', 'T1', 'D', 64, 'Nicole Doshi', 'M029962', 'ASSISTENTE', 'MANUAL'),
 ('2026-11-11', 'T6', 'D', 229, 'Midwest Emma Free 221s', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-12', 'T1', 'D', 229, 'Midwest Emma Free 221s', 'V412402', 'REGULAR', 'MANUAL'),
 ('2026-11-11', 'T6', 'D', 229, 'Midwest Emma Free 221s', 'P155379', 'ASSISTENTE', 'MANUAL'), ('2026-11-12', 'T1', 'D', 229, 'Midwest Emma Free 221s', 'P155379', 'ASSISTENTE', 'MANUAL'),
 ('2026-11-12', 'T6', 'D', 482, 'Marie Dee VIP', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-13', 'T1', 'D', 482, 'Marie Dee VIP', 'V412402', 'REGULAR', 'MANUAL'),
 ('2026-11-15', 'T6', 'D', 29, 'CJ VIP', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-16', 'T1', 'D', 29, 'CJ VIP', 'V412402', 'REGULAR', 'MANUAL'),
 ('2026-11-15', 'T6', 'D', 29, 'CJ VIP', 'P155379', 'ASSISTENTE', 'MANUAL'), ('2026-11-16', 'T1', 'D', 29, 'CJ VIP', 'P155379', 'ASSISTENTE', 'MANUAL'),
 ('2026-11-15', 'T6', 'D', 15, 'CJ Miles', 'V412402', 'REGULAR', 'MANUAL'), ('2026-11-16', 'T1', 'D', 15, 'CJ Miles', 'V412402', 'REGULAR', 'MANUAL');
DROP TEMPORARY TABLE IF EXISTS tmp_buf;
CREATE TEMPORARY TABLE tmp_buf (dia DATE NOT NULL, bloco CHAR(2) NOT NULL, turno CHAR(1) NOT NULL, rep VARCHAR(30) NOT NULL, motivo VARCHAR(255) NOT NULL)
  DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_buf VALUES
 ('2026-11-09', 'T6', 'D', 'M029962', 'buffer: saiu da Alix Lynx para o Veronio (ES_133)'), ('2026-11-10', 'T1', 'D', 'M029962', 'buffer: saiu da Alix Lynx para o Veronio (ES_133)'),
 ('2026-11-12', 'T6', 'D', 'G029092', 'buffer: saiu da Marie Dee VIP para o Veronio (ES_133)'), ('2026-11-13', 'T1', 'D', 'G029092', 'buffer: saiu da Marie Dee VIP para o Veronio (ES_133)'),
 ('2026-11-09', 'T4', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'), ('2026-11-09', 'T5', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'),
 ('2026-11-10', 'T4', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'), ('2026-11-10', 'T5', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'),
 ('2026-11-13', 'T4', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'), ('2026-11-13', 'T5', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'),
 ('2026-11-14', 'T4', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'), ('2026-11-14', 'T5', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'),
 ('2026-11-15', 'T4', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)'), ('2026-11-15', 'T5', 'C', 'A012621', 'buffer: estava sem escala na semana (ES_133)');

START TRANSACTION;
-- 1. Células citadas: inteiras como pedido, travadas
DELETE a FROM esc_plano_alocacao a JOIN (SELECT DISTINCT dia, bloco, page_id FROM tmp_cel) c ON c.dia = a.dia AND c.bloco = a.bloco AND c.page_id = a.page_id
 WHERE a.plano_id = @plano;
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @plano, dia, bloco, turno, page_id, pagina, NULL, NULL, rep, papel, origem, 1, 'troca pedida em 08/11 (ES_133)', NOW(), NULL FROM tmp_cel;
-- 2. Buffer na 5C Schedule (sem repetir quem já está lá no bloco)
INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @plano, b.dia, b.bloco, b.turno, 3, '5C Schedule', NULL, NULL, b.rep, 'REGULAR', 'BUFFER', 1, b.motivo, NOW(), NULL
  FROM tmp_buf b
 WHERE NOT EXISTS (SELECT 1 FROM esc_plano_alocacao a WHERE a.plano_id = @plano AND a.dia = b.dia AND a.bloco = b.bloco AND a.page_id = 3 AND UPPER(a.rep_code) = b.rep);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'grade.ajustar', 'esc_plano_alocacao', CONCAT(@plano, ':2026-11-09:ES_133'), NULL,
       JSON_OBJECT('script', 'ES_133', 'celulas', (SELECT COUNT(*) FROM tmp_cel), 'buffer', (SELECT COUNT(*) FROM tmp_buf)), NULL;
COMMIT;

-- Conferência
SELECT DATE_FORMAT(a.dia, '%d/%m') dia, a.bloco, a.pagina, GROUP_CONCAT(CONCAT(a.rep_code, IF(a.papel = 'ASSISTENTE', '#', '')) ORDER BY a.papel DESC SEPARATOR ', ') AS quem
  FROM esc_plano_alocacao a JOIN (SELECT DISTINCT dia, bloco, page_id FROM tmp_cel) c ON c.dia = a.dia AND c.bloco = a.bloco AND c.page_id = a.page_id
 WHERE a.plano_id = @plano GROUP BY a.dia, a.bloco, a.pagina ORDER BY a.dia, a.bloco, a.pagina;
SELECT UPPER(rep_code) rep, DATE_FORMAT(dia, '%d/%m') dia, bloco, origem FROM esc_plano_alocacao WHERE plano_id = @plano AND page_id = 3 AND UPPER(rep_code) IN ('M029962', 'G029092', 'A012621') ORDER BY rep, dia, bloco;
