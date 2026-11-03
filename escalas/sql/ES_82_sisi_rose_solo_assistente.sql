SET NAMES utf8mb4;
-- =====================================================================
-- ES_82_sisi_rose_solo_assistente.sql  (02/11, aplicado pelo Claude via mysql.exe)
-- Pedido do usuário ("Sisi June" = Sisi Rose, U1C2; a Sienna June está inativa no site): pela
-- importância da página, ela roda exclusivamente solo e com mais um assistente.
--   - Alocação nova EXCLUSIVA (solo): o SR do posto da página não acumula outro posto nem cobre
--     página na sobrecarga, e o posto dela não vai para o SR de outro posto (1.18.14).
--   - Assistente de volta: o posto fixo do Capacity passa de 1 para 2 pessoas (o ES_67 tinha tirado).
--     O recálculo dos postos (CALL abaixo) cria o posto de assistente; o 5C Escalas copia no próximo
--     "Gerar" (atualização automática da estrutura).
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_pagina_config MODIFY COLUMN alocacao ENUM('EQUIPE','SOB_DEMANDA','EXCLUSIVA') NOT NULL DEFAULT 'EQUIPE';

INSERT INTO esc_pagina_config (pagina, sla_modo, alocacao, atualizado_por)
VALUES ('Sisi Rose', 'AUTO', 'EXCLUSIVA', NULL)
ON DUPLICATE KEY UPDATE alocacao = VALUES(alocacao);

UPDATE `5c_planning`.`fc_posto_fixo` SET pessoas = 2, obs = LEFT(CONCAT('ES_82: solo com assistente; ', REPLACE(IFNULL(obs, ''), 'ES_67: sem assistente; ', '')), 255)
 WHERE id = 226 AND pagina = 'Sisi Rose' AND carteira = 'U1C2' AND vigente_ate IS NULL;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'pagina.config', 'esc_pagina_config', 'Sisi Rose',
        '{"alocacao":"EQUIPE","posto_fixo_pessoas":1}', '{"alocacao":"EXCLUSIVA","posto_fixo_pessoas":2,"script":"ES_82"}', NULL);

CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE(), 'ES_82 Sisi Rose solo com assistente');

-- Conferência
SELECT pagina, sla_modo, alocacao FROM esc_pagina_config WHERE pagina = 'Sisi Rose';
SELECT id, carteira, grupo, pagina, pessoas, obs FROM `5c_planning`.`fc_posto_fixo` WHERE id = 226;
