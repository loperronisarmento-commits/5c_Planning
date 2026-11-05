SET NAMES utf8mb4;
-- =====================================================================
-- ES_114_u3_main_cover.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 (planilha do OM da U3): distribuição das páginas da U3 por turno, com o
-- titular (main) e quem cobre (cover). A partir de 05/11.
--   - esc_pagina_prioridade ganha a coluna papel (EQUIPE, MAIN, COVER). O motor (1.19.15) põe primeiro os
--     MAIN de todas as páginas, depois os COVER e por último o resto da equipe.
--   - As equipes antigas de Cynthia, Elly Clutch, Madison Moore (VIP e Free), Britt (VIP e OFTV) e Ivy Vale
--     fecham em 04/11 e dão lugar às da planilha. A equipe da Fanvue segue na Savannah (mesmo posto da Ivy),
--     como EQUIPE, depois dos main e cover.
--   - Mario Adrion e Blake (mesmo posto). A Liam está em outro posto (com a Bianca): fica fora até o usuário
--     decidir se muda o posto no Capacity.
--   - Só assistente "por enquanto" (v4_sr_so_assistente): Mirriam Kariuki (M335264), "Mar" Benkendorf = Thayla
--     Benkendorf (T099707, a confirmar) e Maressa Thereza Silva (M529303).
--   - Não faz a página como main (só assistente nela, esc_pagina_assistente): Myles Merced (M051596) na Cynthia,
--     Jose Feliciano (J337675) na Elly Clutch.
--   - Fora da regra da unidade (ficam como última opção, sem mudar ninguém de unidade): Luan Oliveira (L101222,
--     U2 D; segue com a divisão 50% Elly), Vanderson (V115422, U2 A; segue com a divisão 50% Elly) e Léo Castro
--     (L147458, U1 A, cover da Ivy Vale). Juliana Santos (main da Goddess D e cover da Ivy D) não está no cadastro.
-- =====================================================================

USE `5c_escalas`;

ALTER TABLE esc_pagina_prioridade ADD COLUMN papel ENUM('EQUIPE', 'MAIN', 'COVER') NOT NULL DEFAULT 'EQUIPE' AFTER rep_codes;

START TRANSACTION;

UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Substituída pela planilha do OM da U3 (ES_114); ', IFNULL(motivo, '')), 255)
 WHERE id IN (70, 36, 34, 35, 29, 33, 32) AND vigente_ate IS NULL;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.reps, x.papel, 'Planilha do OM da U3 (ES_114)', '2026-11-05', NULL FROM (
 SELECT 'Blake' AS pagina, 'M051596,C091389,A012515' AS reps, 'COVER' AS papel
 UNION ALL SELECT 'Blake', 'J172862,B065640,P098140', 'MAIN'
 UNION ALL SELECT 'Britt OFTV', 'M054981,B165015,I131039', 'COVER'
 UNION ALL SELECT 'Britt OFTV', 'D103456,M059686,M032948', 'MAIN'
 UNION ALL SELECT 'Britt VIP', 'M054981,B165015,I131039', 'COVER'
 UNION ALL SELECT 'Britt VIP', 'D103456,M059686,M032948', 'MAIN'
 UNION ALL SELECT 'Cynthia', 'G457983,M059686,A041809', 'COVER'
 UNION ALL SELECT 'Cynthia', 'L086668,J058241,L497114', 'MAIN'
 UNION ALL SELECT 'Elly Clutch 100m', 'B032241,J172862,V115422,M036514', 'COVER'
 UNION ALL SELECT 'Elly Clutch 100m', 'L101222,G147453,A041809', 'MAIN'
 UNION ALL SELECT 'Goddess Sylvanas 150m', 'F163789,C860704', 'COVER'
 UNION ALL SELECT 'Goddess Sylvanas 150m', 'I468128,D105788', 'MAIN'
 UNION ALL SELECT 'Ivy Vale', 'L147458,S154683,L099242', 'COVER'
 UNION ALL SELECT 'Ivy Vale', 'M105176,G126656,I131039', 'MAIN'
 UNION ALL SELECT 'Janet Free', 'M105176,F373986,S154683', 'COVER'
 UNION ALL SELECT 'Janet Free', 'M203717,B165015,M036514', 'MAIN'
 UNION ALL SELECT 'Janet VIP', 'M105176,F373986,S154683', 'COVER'
 UNION ALL SELECT 'Janet VIP', 'M203717,B165015,M036514', 'MAIN'
 UNION ALL SELECT 'Jessie', 'A028910,C091389,L099242', 'COVER'
 UNION ALL SELECT 'Jessie', 'B032241,A016423,J010257', 'MAIN'
 UNION ALL SELECT 'Lexis Star', 'B456106,H083842,P176228', 'COVER'
 UNION ALL SELECT 'Lexis Star', 'K290130,J064868,A007546', 'MAIN'
 UNION ALL SELECT 'Madison Moore Free 115s', 'K290130,M203717,H109391,G092815', 'COVER'
 UNION ALL SELECT 'Madison Moore Free 115s', 'A070935,P065669,L055002', 'MAIN'
 UNION ALL SELECT 'Madison Moore VIP 115m', 'K290130,M203717,H109391,G092815', 'COVER'
 UNION ALL SELECT 'Madison Moore VIP 115m', 'A070935,P065669,L055002', 'MAIN'
 UNION ALL SELECT 'Mario Adrion', 'M051596,C091389,A012515', 'COVER'
 UNION ALL SELECT 'Mario Adrion', 'J172862,B065640,P098140', 'MAIN'
 UNION ALL SELECT 'Ms Red Free', 'J172862,I468128,W175160', 'COVER'
 UNION ALL SELECT 'Ms Red Free', 'G457983,H109391,G092815', 'MAIN'
 UNION ALL SELECT 'Ms Red VIP', 'J172862,I468128,W175160', 'COVER'
 UNION ALL SELECT 'Ms Red VIP', 'G457983,H109391,G092815', 'MAIN') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND p.papel = x.papel AND p.vigente_desde = '2026-11-05');

INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.rep, 'ASSISTENTE', 'OM U3: não faz a página como main (ES_114)', '2026-11-05', NULL
  FROM (SELECT 'Cynthia' AS pagina, 'M051596' AS rep UNION ALL SELECT 'Elly Clutch 100m', 'J337675') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND UPPER(a.rep_code) = x.rep AND a.vigente_ate IS NULL);

UPDATE esc_parametro SET valor = 'L118244,L049922,T082480,M335264,T099707,M529303',
       descricao = 'Só entram como assistente, em qualquer página: Letícia Zaleski, Larissa Soares, Thiago Larsen, Mirriam Kariuki, Thayla Benkendorf, Maressa Silva'
 WHERE chave = 'v4_sr_so_assistente';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_pagina_prioridade', 'ES_114', NULL,
        '{"u3":"main e cover por página e turno","desde":"2026-11-05"}', NULL);

COMMIT;

SELECT pagina, papel, rep_codes FROM esc_pagina_prioridade WHERE vigente_desde = '2026-11-05' AND motivo LIKE '%ES_114%' ORDER BY pagina, papel;
SELECT id, pagina, DATE(vigente_ate) AS ate FROM esc_pagina_prioridade WHERE id IN (70, 36, 34, 35, 29, 33, 32, 28);
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_sr_so_assistente';
