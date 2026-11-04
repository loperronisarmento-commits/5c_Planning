SET NAMES utf8mb4;
-- =====================================================================
-- ES_104_skye_angeline_oftv.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedidos do usuário em 04/11:
--   1. Skye Zyon (D008138) deixa de ser "só assistente" na Mikayla Free (a marcação id 17, do ES_100, é
--      apagada). Ela continua na equipe fixa da Mikayla Free.
--   2. Angeline Butal (A283671) não é proibida na Rocket Reyna: é da equipe fixa. As proibições (ids 2 e 3,
--      de 02/11, na VIP e na OFTV) fecham em 03/11 e ela entra na equipe da Rocket Reyna VIP a partir de 04/11.
--   3. As equipes fixas valem também para a OFTV da mesma modelo: cada linha vigente da página principal é
--      repetida na OFTV (Rocket Reyna VIP → Rocket Reyna OFTV, Nicole Doshi → Nicole OFTV,
--      Britt VIP → Britt OFTV, Molly Stewart VIP → zOFTV Molly Stewart).
--      A Britt OFTV e a zOFTV Molly Stewart são On demand: o Gerar não põe ninguém nelas, e a equipe fica
--      como referência para a alocação manual.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

DELETE FROM esc_pagina_assistente WHERE id = 17 AND pagina = 'Mikayla Free' AND UPPER(rep_code) = 'D008138' AND papel = 'ASSISTENTE';

UPDATE esc_pagina_assistente SET vigente_ate = '2026-11-03', motivo = LEFT(CONCAT('ES_104: é da equipe fixa, não proibida; ', IFNULL(motivo, '')), 255)
 WHERE id IN (2, 3) AND UPPER(rep_code) = 'A283671' AND papel = 'FORA' AND vigente_ate IS NULL;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Rocket Reyna VIP', 'A283671', 'Manager: Angeline Butal na equipe fixa da Rocket Reyna (ES_104)', '2026-11-04', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Rocket Reyna VIP' AND rep_codes = 'A283671' AND vigente_ate IS NULL);

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT x.oftv, p.rep_codes, LEFT(CONCAT('Repetida da ', p.pagina, ' (ES_104): ', IFNULL(p.motivo, '')), 255), p.vigente_desde, p.vigente_ate
  FROM esc_pagina_prioridade p
  JOIN (SELECT 'Rocket Reyna VIP' AS pagina, 'Rocket Reyna OFTV' AS oftv UNION ALL SELECT 'Nicole Doshi', 'Nicole OFTV'
        UNION ALL SELECT 'Britt VIP', 'Britt OFTV' UNION ALL SELECT 'Molly Stewart VIP', 'zOFTV Molly Stewart') x ON x.pagina = p.pagina
 WHERE (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-04')
   AND NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade q WHERE q.pagina = x.oftv AND q.rep_codes = p.rep_codes AND q.vigente_desde = p.vigente_desde);

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'equipe_fixa.ajuste', 'esc_pagina_prioridade', 'ES_104', NULL,
        '{"skye":"sem só assistente","angeline":"equipe da Rocket Reyna, sem proibição","oftv":["Rocket Reyna OFTV","Nicole OFTV","Britt OFTV","zOFTV Molly Stewart"]}', NULL);

COMMIT;

SELECT pagina, rep_codes, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_prioridade
 WHERE pagina IN ('Rocket Reyna VIP', 'Rocket Reyna OFTV', 'Nicole Doshi', 'Nicole OFTV', 'Britt VIP', 'Britt OFTV', 'Molly Stewart VIP', 'zOFTV Molly Stewart')
   AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04') ORDER BY pagina, vigente_desde;
SELECT id, pagina, UPPER(rep_code) AS rep, papel, DATE(vigente_ate) AS ate FROM esc_pagina_assistente WHERE UPPER(rep_code) IN ('D008138', 'A283671');
