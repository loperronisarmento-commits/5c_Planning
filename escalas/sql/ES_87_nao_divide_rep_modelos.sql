SET NAMES utf8mb4;
-- =====================================================================
-- ES_87_nao_divide_rep_modelos.sql  (03/11, aplicado pelo Claude via mysql.exe a pedido do Luis Otávio)
-- Regra P-03 (não divide representante com outra modelo), em contexto/regras_prioridade_paginas.md.
-- 1) Alocação EXCLUSIVA nas páginas destas modelos (a Sisi Rose já é desde o ES_82):
--    Mikayla (Free e Paid), Cynthia, Elly Clutch, Nicole Doshi (e Nicole OFTV, apoio no mesmo posto),
--    Liv, Midwest Emma, Molly Stewart, Holly Jane, Britt, Ariana Marie e Madison Moore (VIP e Free).
--    As On demand dessas modelos (zOFTV Molly Stewart, Britt OFTV, zOFTV Diana Rider) continuam On demand.
--    Vale também para o 2º SR dos postos de 2 pessoas (Cynthia, Nicole Doshi, Mikayla Free).
--    Na 1.18.14 a EXCLUSIVA trava por posto: o SR não acumula outro posto (passo 6) nem cobre página
--    (passo 7). Até o motor passar a travar por modelo, o SR da Mikayla Free também não acumula a
--    Mikayla Paid (que tem posto próprio).
-- 2) Capacity: a Ariana Marie dividia o posto com a Yuni Yung (U2C1 grupo 5). A Yuni Yung vai para um
--    posto próprio (grupo 10): +1 posto por turno na U2C1. O recálculo abaixo grava a versão nova;
--    o 5C Escalas copia no próximo "Gerar".
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_pagina_config (pagina, alocacao)
VALUES ('Mikayla Free', 'EXCLUSIVA'), ('Mikayla Paid', 'EXCLUSIVA'), ('Cynthia', 'EXCLUSIVA'),
       ('Elly Clutch 100m', 'EXCLUSIVA'), ('Nicole Doshi', 'EXCLUSIVA'), ('Nicole OFTV', 'EXCLUSIVA'),
       ('Liv', 'EXCLUSIVA'), ('Midwest Emma Free 221s', 'EXCLUSIVA'), ('Molly Stewart VIP', 'EXCLUSIVA'),
       ('Holly Jane', 'EXCLUSIVA'), ('Britt VIP', 'EXCLUSIVA'), ('Ariana Marie', 'EXCLUSIVA'),
       ('Madison Moore VIP 115m', 'EXCLUSIVA'), ('Madison Moore Free 115s', 'EXCLUSIVA')
ON DUPLICATE KEY UPDATE alocacao = VALUES(alocacao);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', pagina,
       '{"alocacao":"EQUIPE"}', '{"alocacao":"EXCLUSIVA","regra":"P-03 não divide rep","script":"ES_87"}', NULL
  FROM esc_pagina_config
 WHERE pagina IN ('Mikayla Free', 'Mikayla Paid', 'Cynthia', 'Elly Clutch 100m', 'Nicole Doshi', 'Nicole OFTV', 'Liv',
                  'Midwest Emma Free 221s', 'Molly Stewart VIP', 'Holly Jane', 'Britt VIP', 'Ariana Marie',
                  'Madison Moore VIP 115m', 'Madison Moore Free 115s');

UPDATE `5c_planning`.`fc_posto_fixo`
   SET grupo = 10, seq = 1, obs = LEFT(CONCAT('ES_87: posto próprio (Ariana Marie não divide rep); ', IFNULL(obs, '')), 255)
 WHERE id = 253 AND pagina = 'Yuni Yung' AND carteira = 'U2C1' AND grupo = 5 AND vigente_ate IS NULL;

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'ES_87 Ariana Marie não divide rep: Yuni Yung em posto próprio');

-- Conferência
SELECT alocacao, COUNT(*) AS paginas, GROUP_CONCAT(pagina ORDER BY pagina SEPARATOR ', ') AS lista
  FROM esc_pagina_config WHERE alocacao <> 'EQUIPE' GROUP BY alocacao;
SELECT id, carteira, grupo, seq, pagina, pessoas, obs FROM `5c_planning`.`fc_posto_fixo` WHERE id IN (252, 253);
SELECT carteira, turno, COUNT(*) AS postos FROM `5c_planning`.`fc_postos`
 WHERE data_calculo = CURDATE() AND carteira LIKE 'U2%' GROUP BY carteira, turno ORDER BY carteira, turno;
