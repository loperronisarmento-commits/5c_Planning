-- =====================================================================
-- ES_65_ajustes_managers.sql  (01/11)
-- Ajustes pedidos pelos managers depois da simulação da semana de 02/11:
--   - Midwest Emma Free 221s nunca precisou de assistente: o posto fixo (U2C1) passa de 2 para
--     1 pessoa. U2 fica com 11 postos no C1 e 11 no C2. Depois, recalcular os postos do Capacity
--     (CALL abaixo, ou o "Gerar" do site, que recalcula sozinho).
--   - Nova tabela esc_pagina_assistente: SR que entra numa página só como assistente (nunca no
--     posto titular dela nem como regular). Primeira linha: Iasmim (I433559) na Mikayla Free.
-- Já aplicado em 01/11 pelo MCP (menos o CALL).
-- =====================================================================

SET NAMES utf8mb4;

UPDATE `5c_planning`.`fc_posto_fixo` SET pessoas = 1, obs = LEFT(CONCAT('ES_65: sem assistente; ', IFNULL(obs, '')), 255)
 WHERE pagina = 'Midwest Emma Free 221s' AND carteira = 'U2C1' AND vigente_ate IS NULL;

CREATE TABLE IF NOT EXISTS `5c_escalas`.`esc_pagina_assistente` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pagina VARCHAR(120) NOT NULL,
  rep_code VARCHAR(20) NOT NULL,
  motivo VARCHAR(255) NULL,
  vigente_desde DATE NOT NULL,
  vigente_ate DATE NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pagina (pagina),
  KEY ix_rep (rep_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `5c_escalas`.`esc_pagina_assistente` (pagina, rep_code, motivo, vigente_desde)
SELECT 'Mikayla Free', 'I433559', 'Manager: Iasmim é assistente na Mikayla, não main', '2026-11-01'
 WHERE NOT EXISTS (SELECT 1 FROM `5c_escalas`.`esc_pagina_assistente` WHERE pagina = 'Mikayla Free' AND rep_code = 'I433559' AND vigente_ate IS NULL);

-- Rodar no Navicat (o MCP não faz CALL):
-- CALL `5c_planning`.`sp_fc_recalcular_postos`(CURDATE());
