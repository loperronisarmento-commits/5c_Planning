-- =====================================================================
-- ES_72_mikayla_free_v30_1.sql  (01/11)
-- Pedido da líder para a Mikayla Free, T4/T5, na semana de 02/11 (já postada no site):
--   - segunda, quarta, quinta e sexta com Jonas Santos (J432005), José Melo (J104720) ou
--     Mariana Vilela (M443425). Segunda e sexta já estão com o Jonas na v30. Quarta e quinta
--     estavam com o Paulo Gersino (P703279):
--       quarta (04/11): o Jonas vem da Melody e da Haven Free, e o Paulo vai para as duas no lugar dele;
--       quinta (05/11): o José vem da Mikayla Paid, e o Paulo vai para a Paid no lugar dele.
--   - o Javans (J368655) não fica de assistente na Mikayla Free: sai do domingo (08/11) e fica
--     FORA da página daqui em diante.
-- Como a semana já está postada, nada é gerado de novo: a v30.1 é a cópia da v30 (plano 92) com
-- só essas células trocadas, travadas como ajuste manual. Ela fica em RASCUNHO para conferir e
-- publicar na Grade. Equipe fixa com data na Mikayla Free para um "Gerar" desta semana manter o pedido.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde) VALUES
  ('Mikayla Free', 'J368655', 'FORA', 'Líder: o Javans não fica de assistente na Mikayla Free', '2026-11-02');
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate) VALUES
  ('Mikayla Free', 'J432005', 'Líder: Jonas, José ou Mariana na Mikayla Free T4/T5 (qua 04/11: Jonas)', '2026-11-04', '2026-11-04'),
  ('Mikayla Free', 'J104720', 'Líder: Jonas, José ou Mariana na Mikayla Free T4/T5 (qui 05/11: José)', '2026-11-05', '2026-11-05');

-- v30.1: cópia da v30
INSERT INTO esc_plano (semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, subversao, status, plano_anterior_id,
       recalculo_id, capacity_calculo, entradas_hash, parametros, resumo, gerado_por)
SELECT semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, 1, 'RASCUNHO', id,
       NULL, capacity_calculo, entradas_hash, JSON_SET(parametros, '$.ajuste', 'ES_72'), resumo, NULL
  FROM esc_plano WHERE id = 92 AND versao = 30 AND subversao = 0;
SET @p = LAST_INSERT_ID();

INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @p, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por
  FROM esc_plano_alocacao WHERE plano_id = 92 ORDER BY id;
INSERT INTO esc_plano_folga (plano_id, rep_code, dia, tipo, ref_id)
SELECT @p, rep_code, dia, tipo, ref_id FROM esc_plano_folga WHERE plano_id = 92 ORDER BY id;

SET @mot = 'ajuste manual (ES_72, pedido da líder da Mikayla Free)';
-- Quarta 04/11
UPDATE esc_plano_alocacao SET rep_code = 'J432005', origem = 'MANUAL', alterado_em = NOW()
 WHERE plano_id = @p AND page_id = 389 AND dia = '2026-11-04' AND bloco IN ('T4', 'T5') AND rep_code = 'P703279';
UPDATE esc_plano_alocacao SET rep_code = 'P703279', origem = 'MANUAL', alterado_em = NOW()
 WHERE plano_id = @p AND page_id IN (468, 486) AND dia = '2026-11-04' AND bloco IN ('T4', 'T5') AND rep_code = 'J432005';
-- Quinta 05/11
UPDATE esc_plano_alocacao SET rep_code = 'J104720', origem = 'MANUAL', alterado_em = NOW()
 WHERE plano_id = @p AND page_id = 389 AND dia = '2026-11-05' AND bloco IN ('T4', 'T5') AND rep_code = 'P703279';
UPDATE esc_plano_alocacao SET rep_code = 'P703279', origem = 'MANUAL', alterado_em = NOW()
 WHERE plano_id = @p AND page_id = 328 AND dia = '2026-11-05' AND bloco IN ('T4', 'T5') AND rep_code = 'J104720';
-- Domingo 08/11: o Javans sai da Mikayla Free
DELETE FROM esc_plano_alocacao WHERE plano_id = @p AND page_id = 389 AND dia = '2026-11-08' AND bloco IN ('T4', 'T5') AND rep_code = 'J368655';
-- Trava as células mexidas (um "Gerar" desta semana não as desfaz)
UPDATE esc_plano_alocacao SET travada = 1, motivo = @mot
 WHERE plano_id = @p AND bloco IN ('T4', 'T5') AND (
       (page_id IN (389, 468, 486) AND dia = '2026-11-04')
    OR (page_id IN (389, 328) AND dia = '2026-11-05')
    OR (page_id = 389 AND dia = '2026-11-08'));

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido da líder)', 'SISTEMA', 'plano.ajuste', 'esc_plano', @p, '{"plano":92,"versao":"v30"}',
        '{"versao":"v30.1","mikayla_free":{"04/11":"J432005 no lugar de P703279","05/11":"J104720 no lugar de P703279","08/11":"sai J368655"},"melody_haven_free_04/11":"P703279","mikayla_paid_05/11":"P703279"}', NULL);

SELECT @p AS plano_v30_1;
