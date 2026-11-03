SET NAMES utf8mb4;
-- =====================================================================
-- ES_92_mikayla_free_jose_turno_a.sql  (03/11, aplicado pelo Claude via MCP)
--
-- Com o ES_83, o José Otávio (J104720) foi para o turno A. O pedido da líder de 01/11 (ES_72)
-- ainda o punha na Mikayla Free no T4/T5 de 05/11, e a equipe fixa daquele dia (id 24) também
-- o punha no T2/T3: no plano 96 (v30.4) ele ficou em T2, T3, T4 e T5 em 05/11.
--   - Sai a equipe fixa de um dia (id 24, Mikayla Free, J104720, 05/11).
--   - Plano 96: destrava as células da Mikayla Free do T4/T5 de 04 e 05/11 que o ES_72 travou,
--     menos a do Jonas em 04/11 (pedido da líder). Assim o próximo "Recalcular tudo" a partir de
--     um turno antes delas refaz essas células (o Luiz Souza, que nunca fez a página, também estava
--     travado ali como assistente).
-- =====================================================================

USE `5c_escalas`;

DELETE FROM esc_pagina_prioridade WHERE id = 24 AND pagina = 'Mikayla Free' AND rep_codes = 'J104720';

UPDATE esc_plano_alocacao
   SET travada = 0, motivo = LEFT(CONCAT(motivo, ' · destravada (ES_92: José no turno A)'), 255)
 WHERE plano_id = 96 AND pagina = 'Mikayla Free' AND bloco IN ('T4', 'T5') AND dia IN ('2026-11-04', '2026-11-05')
   AND travada = 1 AND NOT (dia = '2026-11-04' AND UPPER(rep_code) = 'J432005');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'plano.ajuste', 'esc_plano', '96',
        '{"prioridade_24":"Mikayla Free J104720 05/11","travadas":"Mikayla Free T4/T5 04 e 05/11"}',
        '{"script":"ES_92","prioridade_24":"apagada","destravadas":"menos Jonas 04/11"}', NULL);

-- Conferência
SELECT DATE_FORMAT(dia, '%d/%m') AS dia, bloco, UPPER(rep_code) AS rep, papel, travada
  FROM esc_plano_alocacao WHERE plano_id = 96 AND pagina = 'Mikayla Free' AND bloco IN ('T4', 'T5') AND dia IN ('2026-11-04', '2026-11-05')
 ORDER BY dia, bloco, papel DESC;
