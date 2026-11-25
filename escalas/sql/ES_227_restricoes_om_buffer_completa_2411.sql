SET NAMES utf8mb4;
-- =====================================================================
-- ES_227 (24/11/2026): restrições por pessoa pedidas pelos OM, por tempo indeterminado (via mysql.exe).
--   v4_sr_so_buffer (só buffer de plantão, nenhuma página): L704704, C015038.
--   v4_sr_completa (completa a escala: por último, fora da base, do melhor SR do turno e das equipes; página até o
--     Tier E, v4_sr_completa_teto = 32000): T066210, R009812, V053618, N176385, J064868, J337675.
--   v4_sr_sem_masculinas (não faz página masculina, v4_paginas_masculinas): C091389.
-- Equipe fixa, desde 30/11: C091389 sai do COVER da Blake, Mario Adrion e Liam (páginas masculinas);
--   N176385 sai da EQUIPE da Savannah.
-- Texto neutro em tudo o que aparece nas telas (pedido do usuário).
-- =====================================================================
USE `5c_escalas`;

SELECT 'antes' AS momento, chave, valor FROM esc_parametro
 WHERE chave IN ('v4_sr_so_buffer', 'v4_sr_completa', 'v4_sr_completa_teto', 'v4_sr_sem_masculinas');
SELECT 'antes' AS momento, id, pagina, rep_codes, papel, vigente_desde, vigente_ate FROM esc_pagina_prioridade
 WHERE vigente_ate IS NULL AND ((pagina IN ('Blake', 'Mario Adrion', 'Liam') AND rep_codes LIKE '%C091389%') OR (pagina = 'Savannah' AND rep_codes LIKE '%N176385%'));

START TRANSACTION;
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT x.chave, x.valor, x.tipo, t.grupo, x.descricao
  FROM (SELECT 'v4_sr_so_buffer' AS chave, 'L704704,C015038' AS valor, 'TEXTO' AS tipo,
               'Restrição dos OM: códigos que ficam só de buffer de plantão, sem página (24/11, ES_227)' AS descricao
        UNION ALL SELECT 'v4_sr_completa', 'T066210,R009812,V053618,N176385,J064868,J337675', 'TEXTO',
               'Restrição dos OM: códigos que completam a escala (entram depois dos outros e só em página até v4_sr_completa_teto) (24/11, ES_227)'
        UNION ALL SELECT 'v4_sr_completa_teto', '32000', 'DECIMAL',
               'Teto de faturamento de 30 dias (US$) da página para quem completa a escala; 32 mil = Tier E. 0 = sem teto (24/11, ES_227)'
        UNION ALL SELECT 'v4_sr_sem_masculinas', 'C091389', 'TEXTO',
               'Restrição dos OM: códigos que não fazem página masculina (v4_paginas_masculinas), de assistente também (24/11, ES_227)') x
  JOIN esc_parametro t ON t.chave = 'v4_sr_solo'
 WHERE NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = x.chave);

-- Equipe fixa: fecha em 29/11 e reabre em 30/11 sem a pessoa.
DROP TEMPORARY TABLE IF EXISTS tmp_es227;
CREATE TEMPORARY TABLE tmp_es227 AS
SELECT id, pagina, rep_codes, papel, motivo, vigente_desde,
       IF(pagina = 'Savannah', 'N176385', 'C091389') AS sai
  FROM esc_pagina_prioridade
 WHERE vigente_ate IS NULL AND vigente_desde < '2026-11-30'
   AND ((pagina IN ('Blake', 'Mario Adrion', 'Liam') AND FIND_IN_SET('C091389', REPLACE(rep_codes, ' ', '')))
     OR (pagina = 'Savannah' AND FIND_IN_SET('N176385', REPLACE(rep_codes, ' ', ''))));
UPDATE esc_pagina_prioridade p JOIN tmp_es227 t ON t.id = p.id
   SET p.vigente_ate = '2026-11-29', p.motivo = LEFT(CONCAT('Ajuste dos OM de 24/11 (ES_227); ', IFNULL(p.motivo, '')), 255);
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, papel, motivo, vigente_desde, vigente_ate)
SELECT pagina, TRIM(BOTH ',' FROM REPLACE(CONCAT(',', REPLACE(rep_codes, ' ', ''), ','), CONCAT(',', sai, ','), ',')), papel,
       LEFT(IFNULL(motivo, ''), 255), '2026-11-30', NULL
  FROM tmp_es227
 WHERE TRIM(BOTH ',' FROM REPLACE(CONCAT(',', REPLACE(rep_codes, ' ', ''), ','), CONCAT(',', sai, ','), ',')) <> '';
COMMIT;

SELECT 'depois' AS momento, chave, valor, tipo, grupo FROM esc_parametro
 WHERE chave IN ('v4_sr_so_buffer', 'v4_sr_completa', 'v4_sr_completa_teto', 'v4_sr_sem_masculinas');
SELECT 'depois' AS momento, id, pagina, rep_codes, papel, vigente_desde, vigente_ate FROM esc_pagina_prioridade
 WHERE pagina IN ('Blake', 'Mario Adrion', 'Liam', 'Savannah') AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-29') ORDER BY pagina, vigente_desde;
