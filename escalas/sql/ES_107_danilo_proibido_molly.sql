SET NAMES utf8mb4;
-- =====================================================================
-- ES_107_danilo_proibido_molly.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11: Danilo Oliveira (D422040, U2 D n2, SR 1) estava na equipe fixa da Molly
-- Stewart VIP, mas "na realidade é PROIBIDO na Molly Stewart VIP". Sai da equipe (VIP e zOFTV Molly
-- Stewart) e fica proibido (FORA) nas duas a partir de 04/11.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_pagina_prioridade SET rep_codes = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', rep_codes, ','), ',D422040,', ',')),
       motivo = LEFT(CONCAT(motivo, ' · sem o Danilo Oliveira, proibido na página (ES_107)'), 255)
 WHERE pagina IN ('Molly Stewart VIP', 'zOFTV Molly Stewart') AND vigente_ate IS NULL AND FIND_IN_SET('D422040', rep_codes);

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, vigente_desde, vigente_ate, motivo)
SELECT x.pagina, 'D422040', 'FORA', '2026-11-04', NULL, 'Manager: Danilo Oliveira proibido na Molly Stewart (04/11, ES_107)'
  FROM (SELECT 'Molly Stewart VIP' AS pagina UNION ALL SELECT 'zOFTV Molly Stewart') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND a.rep_code = 'D422040' AND a.papel = 'FORA');

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_pagina_assistente', 'ES_107',
        '{"D422040":"equipe fixa da Molly Stewart VIP"}', '{"D422040":"FORA na Molly Stewart VIP e zOFTV"}', NULL);

COMMIT;

SELECT pagina, rep_codes FROM esc_pagina_prioridade WHERE pagina IN ('Molly Stewart VIP', 'zOFTV Molly Stewart') AND vigente_ate IS NULL;
SELECT id, pagina, papel, DATE(vigente_desde) AS de FROM esc_pagina_assistente WHERE UPPER(rep_code) = 'D422040';
