SET NAMES utf8mb4;
-- =====================================================================
-- ES_236 · Parâmetros da 1.41.0 (26/11/2026, pedido do CEO repassado pelo usuário: "Faça acontecer"; via MCP)
--   1. Folga de OC e OM: ciclo de 3 semanas (seg a dom), desde 07/12 (a semana de 30/11 já estava publicada):
--      semana 1 = fim de semana inteiro de folga (1111100), semana 2 = sexta + sábado de folga, trabalha domingo
--      (1111001), semana 3 = quinta + domingo de folga, trabalha sábado (1110110). Os 3 OC de cada turno (U1, U2,
--      U3) e os 3 OM ficam em semanas diferentes (deslocamento 0, 7, 14 por unidade): todo dia de fim de semana tem
--      exatamente 1, e nos dias úteis 2 ou 3. Decisão do usuário: fim de semana inteiro a cada 3 semanas (com 3 por
--      grupo, a cada 2 semanas um dia de fim de semana ficaria sem ninguém).
--   2. OS com grupo fixo de SRs: sem divisão dos OS por faturamento (v4_os_equilibrio = 0; antes 1).
--   3. Página segue o SR: ao publicar, o posto vai para o núcleo do SR que mais faz a página como titular
--      (v4_pagina_segue_sr = 1; escPostoNucleoPeloPlano em lib/estrutura.php).
--   O passo do v4_os_equilibrio roda na publicação da 1.41.0 (o código no ar ainda lê); os outros já valem (só o código novo lê).
-- =====================================================================
USE `5c_escalas`;
SELECT 'antes' AS momento, chave, valor FROM esc_parametro
 WHERE chave IN ('v4_lideranca_oc_ciclo', 'v4_lideranca_oc_desde', 'v4_lideranca_oc_desloc', 'v4_pagina_segue_sr', 'v4_os_equilibrio');

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT x.chave, x.valor, x.tipo, 'v4', x.descricao
  FROM (SELECT 'v4_lideranca_oc_ciclo' AS chave, '111110011110011110110' AS valor, 'TEXTO' AS tipo,
               'OC e OM: ciclo de 3 semanas (seg a dom; 1 = trabalha): FDS inteiro de folga, sex+sáb, qui+dom (ES_236)' AS descricao
        UNION ALL SELECT 'v4_lideranca_oc_desde', '2026-12-07', 'TEXTO', 'OC e OM seguem v4_lideranca_oc_ciclo a partir deste dia (ES_236)'
        UNION ALL SELECT 'v4_lideranca_oc_desloc', '0,7,14', 'TEXTO', 'Deslocamento no ciclo de OC e OM por unidade (U1, U2, U3): semanas diferentes (ES_236)'
        UNION ALL SELECT 'v4_pagina_segue_sr', '1', 'BOOL', '1 = ao publicar, o posto vai para o núcleo do SR que mais faz a página como titular (ES_236)'
        UNION ALL SELECT 'v4_pagina_segue_sr_min', '0.4', 'DECIMAL', 'Página segue o SR: parte mínima das células do posto que o núcleo novo precisa fazer como titular para virar dono (ES_236)') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = x.chave);

-- Na publicação da 1.41.0:
UPDATE esc_parametro SET valor = '0' WHERE chave = 'v4_os_equilibrio';

SELECT 'depois' AS momento, chave, valor FROM esc_parametro
 WHERE chave IN ('v4_lideranca_oc_ciclo', 'v4_lideranca_oc_desde', 'v4_lideranca_oc_desloc', 'v4_pagina_segue_sr', 'v4_os_equilibrio');
