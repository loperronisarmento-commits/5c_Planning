SET NAMES utf8mb4;
-- =====================================================================
-- ES_120_coerencia_restricoes.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Revisão cruzada das restrições (resultados/restricoes_0411/auditar_restricoes.py), pedida em 04/11.
-- Nenhum caso de equipe fixa e proibido ao mesmo tempo. Sobrou o que as trocas antigas de 09/11
-- desmontavam na planilha do OM da U3 (ES_114). Decisão do usuário: vale o mais recente (a planilha):
--   - Krizel Dejaro (K290130): ia da U3 D n1 para a U1 D em 09/11 (ES_75, equipe da Holly Jane, da qual já
--     não faz parte). Fica na U3 D n1: main da Lexis Star D, cover da Madison D.
--   - João Vitor Sousa (J064868): ia da U3 A n1 para a U2 A em 09/11 (ES_73, conhecimento de página). Fica
--     na U3 A n1: main da Lexis Star A.
--   - Aline Sodré (A016423): ia do A para o C da U3 em 09/11 (ES_48). Fica na U3 A n2: main da Jessie A
--     (no C o main é o John Balasta).
-- As linhas de cadastro de 09/11 passam a repetir a unidade/turno de hoje; as vagas de hoje seguem abertas
-- e as de destino vão para a data neutra.
-- Limpeza: a Liv saiu em 04/11; fecham a linha de equipe dela (Matteo Guedes) e o "proibido" do Jose
-- Feliciano nela. O Lucas Moraes (L389704) sai de v4_todos_os_dias, que era por causa da Liv, e volta ao 5x2.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- Linhas de 09/11 iguais às de hoje
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 7, turno = 'D',
       observacao = 'Fica na U3 D núcleo 1 (ES_120): desfeita a ida para a U1 em 09/11 (ES_75); main da Lexis Star D'
 WHERE id = 648 AND UPPER(rep_code) = 'K290130' AND vigente_desde = '2026-11-09';
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 1, turno = 'A',
       observacao = 'Fica na U3 A núcleo 1 (ES_120): desfeita a ida para a U2 em 09/11 (ES_73); main da Lexis Star A'
 WHERE id = 634 AND UPPER(rep_code) = 'J064868' AND vigente_desde = '2026-11-09';
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 2, turno = 'A',
       observacao = 'Fica na U3 A núcleo 2 (ES_120): desfeita a ida para o C em 09/11 (ES_48); main da Jessie A'
 WHERE id = 582 AND UPPER(rep_code) = 'A016423' AND vigente_desde = '2026-11-09';

-- Vagas: as de hoje seguem; as de destino vão para a data neutra
UPDATE esc_vaga_equipe SET vigente_ate = NULL, motivo = LEFT(CONCAT('Fica na U3 (ES_120); ', IFNULL(motivo, '')), 255)
 WHERE id IN (2813, 3147, 3030) AND UPPER(rep_code) IN ('K290130', 'J064868') AND vigente_ate = '2026-11-08';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30', motivo = LEFT(CONCAT('Não muda em 09/11 (ES_120); ', IFNULL(motivo, '')), 255)
 WHERE id IN (3371, 3508, 3521) AND UPPER(rep_code) IN ('K290130', 'J064868') AND vigente_desde = '2026-11-09';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30', motivo = LEFT(CONCAT('Não muda em 09/11 (ES_120); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'A016423' AND vigente_desde = '2026-11-09';

-- Limpeza da Liv e do "todos os dias"
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('A página saiu em 04/11 (ES_120); ', IFNULL(motivo, '')), 255)
 WHERE id = 51 AND pagina = 'Liv' AND vigente_ate IS NULL;
UPDATE esc_pagina_assistente SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('A página saiu em 04/11 (ES_120); ', IFNULL(motivo, '')), 255)
 WHERE id = 8 AND pagina = 'Liv' AND vigente_ate IS NULL;
UPDATE esc_parametro SET valor = '', descricao = 'SRs que trabalham todos os dias do turno, fora da rotação (temporário). Vazio desde 04/11 (ES_120): o Lucas Moraes voltou ao 5x2'
 WHERE chave = 'v4_todos_os_dias' AND valor = 'L389704';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_colaborador_config', 'ES_120',
        '{"K290130":"U1 D n2 desde 09/11","J064868":"U2 A n2 desde 09/11","A016423":"U3 C n2 desde 09/11","v4_todos_os_dias":"L389704"}',
        '{"K290130":"U3 D n1","J064868":"U3 A n1","A016423":"U3 A n2","Liv":"equipe e proibido fechados","v4_todos_os_dias":""}', NULL);

COMMIT;

SELECT c.id, UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('K290130', 'J064868', 'A016423') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-05') ORDER BY rep, c.vigente_desde;
SELECT UPPER(v.rep_code) AS rep, p.unidade AS u, p.turno AS t, v.vaga, DATE(v.vigente_desde) AS de, DATE(v.vigente_ate) AS ate
  FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id
 WHERE UPPER(v.rep_code) IN ('K290130', 'J064868', 'A016423') AND (v.vigente_ate IS NULL OR v.vigente_ate >= '2026-11-05') ORDER BY rep;
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_todos_os_dias';
