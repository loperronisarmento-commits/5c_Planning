SET NAMES utf8mb4;
-- =====================================================================
-- ES_76_desligamentos_e_aline_novak.sql  (02/11, aplicado pelo Claude via MCP)
--
-- 1. Desligamentos informados em 02/11; ficam fora a partir de 03/11:
--      Luís Fernando Moreira Leitão  L084884  (U3, turno D, SR 1; 4 vagas de dono)
--      Lilyo de Andrade Lira Gomes   L617722  (U1, turno D, SR 1; sem vagas)
--    O cadastro e as vagas fecham em 02/11. Nenhum dos dois estava em equipe fixa de página
--    (esc_pagina_prioridade). O plano 93 (semana de 02/11, já postado) não muda: tirar à mão no site
--    (Leitão: 13 células de 03 a 09/11; Lilyo: 8 células de 04 a 06/11).
-- 2. Aline Novak (U2C2) com equipe fixa a partir de 03/11: Maiara Machado (M078957), Karolayne
--    Brito (K169904), Loik Silva (L052002), Giovanna Fumagalli (G045315), Jonhnata Souza (J857800) e
--    Marcos Cid (M137535). A equipe de antes (Privacy, com M203717) fecha em 02/11. A da Andressa
--    Urach não muda.
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip) VALUES
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '434', '{"rep":"L084884","nome":"Luís Fernando Moreira Leitão","vagas":[2806,2891,3074,3112]}', '{"vigente_ate":"2026-11-02"}', NULL),
  (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'SAIDA_EMPRESA', 'esc_colaborador_config', '596', '{"rep":"L617722","nome":"Lilyo de Andrade Lira Gomes","vagas":[]}', '{"vigente_ate":"2026-11-02"}', NULL);

UPDATE esc_colaborador_config SET vigente_ate = '2026-11-02', observacao = LEFT(CONCAT('Desligamento informado em 02/11 (ES_76); ', IFNULL(observacao, '')), 255)
 WHERE id IN (434, 596) AND rep_code IN ('L084884', 'L617722');
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-02', motivo = LEFT(CONCAT('Desligamento (ES_76); ', IFNULL(motivo, '')), 255)
 WHERE id IN (2806, 2891, 3074, 3112) AND rep_code = 'L084884';

UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-02' WHERE id = 2 AND pagina = 'Aline Novak' AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Aline Novak', 'M078957,K169904,L052002,G045315,J857800,M137535',
       'Manager: equipe fixa da Aline Novak (Maiara Machado, Karolayne Brito, Loik Silva, Giovanna Fumagalli, Jonhnata Souza e Marcos Cid)', '2026-11-03', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Aline Novak' AND vigente_desde = '2026-11-03');

-- Giovanna (ok do usuário em 02/11): ia para a U1 A em 09/11 pela preferência de turno (ES_48) e
-- sairia da unidade da Aline Novak. O destino passa a ser a U2 A núcleo 1 (o dono da Aline no A):
-- mantém o turno que ela pediu e a equipe.
UPDATE esc_colaborador_config SET unidade = 2, nucleo_id = 10,
       observacao = 'Remanejamento a partir de 09/11/2026 (ES_48): marcou A, estava no C; destino U2 A núcleo 1 (era U1) para seguir na equipe fixa da Aline Novak (ES_76)'
 WHERE id = 573 AND rep_code = 'G045315' AND vigente_desde = '2026-11-09';
