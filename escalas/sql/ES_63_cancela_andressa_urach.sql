-- =====================================================================
-- ES_63_cancela_andressa_urach.sql  (01/11)
-- A entrada da Andressa Urach (Privacy, ES_32) foi cancelada pelo usuário em 01/11.
-- Já aplicado em 01/11 pelo MCP. Desfaz o ES_32:
--   - Capacity: sai a linha da carteira U2C2 que começaria em 02/11 (nunca valeu) e a
--     previsão fica CANCELADA;
--   - Escala: os 3 postos dela (434 A, 435 C, 436 D, versão ES32_ANDRESSA) ficam com vigência
--     de 29/10 a 29/10 (como os postos desfeitos no ES_39) e as vagas fixas que começariam em
--     02/11 ou depois saem (30 linhas).
-- A página continua ativa no site (5c_pages.pages): o Comercial precisa desativá-la, senão o
-- "Gerar" a coloca de volta sozinho (página nova no site há até 7 dias, lib/estrutura.php).
-- =====================================================================

SET NAMES utf8mb4;

DELETE FROM `5c_escalas`.`esc_vaga_equipe` WHERE posto_id IN (434, 435, 436) AND vigente_desde >= '2026-11-02';
UPDATE `5c_escalas`.`esc_posto` SET vigente_desde = '2026-10-29', vigente_ate = '2026-10-29'
 WHERE id IN (434, 435, 436) AND versao = 'ES32_ANDRESSA' AND vigente_desde = '2026-11-02';
UPDATE `5c_planning`.`fc_paginas_previstas` SET status = 'CANCELADA', obs = LEFT(CONCAT('Cancelada em 01/11 (ES_63); ', IFNULL(obs, '')), 255)
 WHERE pagina = 'Andressa Urach' AND status = 'CONFIRMADA';
DELETE FROM `5c_planning`.`fc_pagina_carteira`
 WHERE pagina = 'Andressa Urach' AND carteira = 'U2C2' AND vigente_desde = '2026-11-02' AND vigente_ate IS NULL;
