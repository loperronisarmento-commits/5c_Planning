SET NAMES utf8mb4;
-- =====================================================================
-- ES_116_u3_liam_fixos_luan.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Respostas do usuário em 04/11 sobre o ES_114 e pedidos novos, a partir de 05/11:
--   - "Chris Santana" = C144167 e "Mar Benkendorf" = Thayla Benkendorf (T099707): confirmados (nada a mudar).
--   - Liam junto de Mario Adrion e Blake (Capacity, script 32): a Liam ganha a mesma equipe main/cover deles.
--   - Blake também é página masculina (v4_paginas_masculinas).
--   - Membros fixos da U3 (inegociáveis): Luan de Souza Oliveira (L101222), Donerio Costa Vila Real Neto
--     (D103456) e Guilherme Ribeiro da Silva (G457983).
--     O Luan saiu da U3 pelo ES_80 (compensação da Fanvue: U2 D até 08/11, U1 D desde 09/11). Volta para a
--     U3 D núcleo 1 a partir de 05/11, como main da Elly Clutch no D; a divisão 50% Elly (ES_98), feita
--     quando ele estava fora da unidade, fecha em 04/11.
--   - Juliana "Simões" (main da Goddess D, cover da Ivy D): ainda sem cadastro, fica de fora.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- Liam com a equipe de Mario Adrion e Blake
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.reps, x.papel, 'Planilha do OM da U3: male models juntos (ES_116)', '2026-11-05', NULL
  FROM (SELECT 'Liam' AS pagina, 'J172862,B065640,P098140' AS reps, 'MAIN' AS papel
        UNION ALL SELECT 'Liam', 'M051596,C091389,A012515', 'COVER') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND p.papel = x.papel AND p.vigente_desde = '2026-11-05');

UPDATE esc_parametro SET valor = 'Mo Saffari|Sean Adams|Jay|Sebi Barraza|Matty Caron|Mario Adrion|Blake|Levi Free|Levi Paid|Liam'
 WHERE chave = 'v4_paginas_masculinas';

-- Luan de volta à U3 D n1 (a linha 664, que o levava à U1 em 09/11, passa a ser a da U3 desde 05/11)
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-04' WHERE id = 663 AND UPPER(rep_code) = 'L101222';
UPDATE esc_colaborador_config SET unidade = 3, nucleo_id = 7, turno = 'D', vigente_desde = '2026-11-05', vigente_ate = NULL,
       observacao = 'Inegociável na U3 (ES_116): volta para a U3 D núcleo 1 a partir de 05/11/2026; main da Elly Clutch no D'
 WHERE id = 664 AND UPPER(rep_code) = 'L101222';
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Volta para a U3 (ES_116); ', IFNULL(motivo, '')), 255)
 WHERE id IN (3399, 3402) AND UPPER(rep_code) = 'L101222' AND vigente_desde <= '2026-11-04';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'L101222' AND vigente_desde >= '2026-11-05';
UPDATE esc_sr_divisao SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Fecha: volta para a U3 como main da Elly (ES_116); ', IFNULL(motivo, '')), 255)
 WHERE id = 3 AND UPPER(rep_code) = 'L101222' AND vigente_ate IS NULL;

-- Donerio e Guilherme: inegociáveis na U3
UPDATE esc_colaborador_config SET observacao = LEFT(CONCAT('Inegociável na U3 (ES_116); ', IFNULL(observacao, '')), 255)
 WHERE id IN (343, 375) AND UPPER(rep_code) IN ('D103456', 'G457983') AND vigente_ate IS NULL AND IFNULL(observacao, '') NOT LIKE '%Inegociável na U3%';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_colaborador_config', 'ES_116',
        '{"L101222":"U2 D n1 até 08/11, U1 D n1 desde 09/11; 50% Elly"}', '{"L101222":"U3 D n1 desde 05/11","inegociaveis_u3":["L101222","D103456","G457983"],"Liam":"equipe de Mario e Blake"}', NULL);

COMMIT;

SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate, LEFT(c.observacao, 50) AS obs
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('L101222', 'D103456', 'G457983') AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-03') ORDER BY rep, c.vigente_desde;
SELECT COUNT(*) AS vagas_abertas_luan FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'L101222' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
SELECT pagina, papel, rep_codes FROM esc_pagina_prioridade WHERE pagina = 'Liam' AND vigente_ate IS NULL ORDER BY papel;
SELECT chave, valor FROM esc_parametro WHERE chave = 'v4_paginas_masculinas';
SELECT UPPER(rep_code) AS rep, pagina, DATE(vigente_ate) AS ate FROM esc_sr_divisao WHERE UPPER(rep_code) = 'L101222';
