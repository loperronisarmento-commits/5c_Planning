SET NAMES utf8mb4;
-- =====================================================================
-- ES_111_restricoes_om_u1_plataforma.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- 1. Uma plataforma por turno (v4_uma_plataforma_turno = 1): quem faz Privacy (Aline Novak), Fanvue (Ivy Vale,
--    Savannah) ou PlayBoy (Silvia Saige) não acumula nem cobre página de outra plataforma no mesmo turno.
--    O motor (passos 6 e 7) e o "mínimo de mudanças" passam a respeitar (1.19.15).
-- 2. Restrições dos OM, U1 Victoria (pedido do usuário em 04/11), a partir de 05/11:
--    - Victor Stangl Von Czekus (V088215, U1 C): assistente na Mikayla Free ou páginas pequenas, uma página só
--      → equipe e marcação de assistente na Mikayla Free, v4_sr_baixo_fluxo, v4_sr_uma_pagina, serve como assistente.
--    - Erika Souza (E031058) e Paulo Santos (P703279): FORA da Mikayla Free e da Mikayla Paid.
--    - Nicolas Kulik (N087021, U1 C): equipe da Rocket Reyna (sem data de fim) e da Diana Rider.
--    - Eduardo Gois (E510906, U1 A): equipe de Rocket Reyna, Rebecca, Natasha Nice, Sisi Rose, Diana Rider,
--      Courtney e Silvia Saige.
--    - Não fazem assistente (pode_assistente = 0; "fazem solo"): Susan Kamau (S373161), Othavio Oliveira (O161512),
--      Luiz Souza (L031271), Ingrid Bessa (I131318), Iasmim Feijo (I433559; sai a marcação de assistente da
--      Mikayla Free) e Pamela Santos (P155379).
--    - Servem como assistente (v4_sr_pref_assistente): Thailane Nascimento (T066210), Samuel Carvalho (S474484),
--      Matheus Boaventura (M116898), Victor Stangl (V088215) e Dennyson (D386725).
--    - John Balasta (J010257): U1 C n3 → U3 C n3 a partir de 05/11.
--    - Rayssa Mulari (R105360, U1 A): equipe de Tiff, Silvia Saige e Natasha Nice.
--    - Rodrigo Menezes (R045082, U1 D): equipe de Natasha Nice, Diana Rider, Rebecca e Sisi Rose.
--    - Skye (D008138, U1 D): equipe de Mikayla Paid, Rocket Reyna VIP e OFTV (mesmo posto, double), Sisi Rose,
--      Natasha Nice e Silvia Saige (segue assistente fixa da Mikayla Free).
--    - Só Mikayla Paid ou Free, nenhuma outra (v4_sr_so_paginas): José Otávio de Melo (J104720), Jonas Santos
--      (J432005), Koi Adonis (K616148) e Brian Kombe (B387230). Os quatro já são da equipe das duas.
--    - André Mendes (A063277): só páginas de tier médio (v4_sr_tier_medio; tier C ou D do cadastro, v4_tier_medio).
--    - João Kienen (J161527) e Thallita Freitas (T112874): preferência em páginas masculinas
--      (v4_sr_pref_masculinas; lista em v4_paginas_masculinas, a confirmar com o usuário).
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

-- Parâmetros novos e ajustados
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
 ('v4_uma_plataforma_turno', '1', 'BOOL', 'v4', 'O SR não faz páginas de plataformas diferentes no mesmo turno (Privacy, Fanvue, PlayBoy, OnlyFans): não acumula nem cobre'),
 ('v4_sr_pref_assistente', 'T066210,S474484,M116898,V088215,D386725', 'TEXTO', 'v4', 'Servem como assistente: vão antes no posto de assistente, em qualquer nível (Thailane, Samuel Carvalho, Matheus Boaventura, Victor Stangl, Dennyson)'),
 ('v4_sr_so_paginas', 'J104720:Mikayla Free|Mikayla Paid;J432005:Mikayla Free|Mikayla Paid;K616148:Mikayla Free|Mikayla Paid;B387230:Mikayla Free|Mikayla Paid', 'TEXTO', 'v4', 'Só estas páginas, nenhuma outra ("CÓDIGO:página|página;..."): José Otávio, Jonas, Koi e Brian só na Mikayla'),
 ('v4_sr_tier_medio', 'A063277', 'TEXTO', 'v4', 'Só páginas de tier médio (v4_tier_medio): André Mendes'),
 ('v4_tier_medio', 'C,D', 'TEXTO', 'v4', 'Tiers do cadastro de páginas (5c_pages) que contam como médio'),
 ('v4_sr_pref_masculinas', 'J161527,T112874', 'TEXTO', 'v4', 'Preferência nos postos com página masculina (v4_paginas_masculinas): João Kienen, Thallita Freitas'),
 ('v4_paginas_masculinas', 'Mo Saffari|Sean Adams|Jay|Sebi Barraza|Matty Caron|Mario Adrion|Levi Free|Levi Paid|Liam', 'TEXTO', 'v4', 'Páginas masculinas, separadas por |')
AS n ON DUPLICATE KEY UPDATE valor = n.valor, tipo = n.tipo, descricao = n.descricao;

UPDATE esc_parametro SET valor = 'A311550,R009812,A101727,O030503,V088215',
       descricao = 'SRs que só trabalham com uma página (não pegam posto de várias páginas, não acumulam nem cobrem): Omari Mambo, Rodjah Maddy, Alexandre Mortagua, Oliver Melo, Victor Stangl'
 WHERE chave = 'v4_sr_uma_pagina';
UPDATE esc_parametro SET valor = 'M175553,V088215',
       descricao = 'SRs só em posto de até v4_baixo_fluxo_max fãs/h ou de assistente: Matheus Chagas, Victor Stangl'
 WHERE chave = 'v4_sr_baixo_fluxo';

-- Não fazem assistente
UPDATE esc_colaborador_config SET pode_assistente = 0,
       observacao = LEFT(CONCAT('Não faz assistente, faz solo (OM U1, ES_111); ', IFNULL(observacao, '')), 255)
 WHERE id IN (662, 494, 428, 388, 390, 498, 651)
   AND UPPER(rep_code) IN ('S373161', 'O161512', 'L031271', 'I131318', 'I433559', 'P155379');
UPDATE esc_pagina_assistente SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Não faz assistente (ES_111); ', IFNULL(motivo, '')), 255)
 WHERE id = 1 AND UPPER(rep_code) = 'I433559' AND vigente_ate IS NULL;

-- Victor Stangl de assistente na Mikayla Free; Erika e Paulo fora das Mikayla
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.rep, x.papel, 'Restrição do OM U1 (ES_111)', '2026-11-05', NULL
  FROM (SELECT 'Mikayla Free' AS pagina, 'V088215' AS rep, 'ASSISTENTE' AS papel
        UNION ALL SELECT 'Mikayla Free', 'E031058', 'FORA' UNION ALL SELECT 'Mikayla Paid', 'E031058', 'FORA'
        UNION ALL SELECT 'Mikayla Free', 'P703279', 'FORA' UNION ALL SELECT 'Mikayla Paid', 'P703279', 'FORA') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND UPPER(a.rep_code) = x.rep AND a.papel = x.papel AND a.vigente_ate IS NULL);

-- Equipes fixas
UPDATE esc_pagina_prioridade SET vigente_ate = NULL, motivo = LEFT(CONCAT('Manter na Rocket Reyna (OM U1, ES_111); ', IFNULL(motivo, '')), 255)
 WHERE pagina IN ('Rocket Reyna VIP', 'Rocket Reyna OFTV') AND rep_codes = 'N087021' AND vigente_ate = '2026-11-10';
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.rep, 'Restrição do OM U1 (ES_111)', '2026-11-05', NULL
  FROM (SELECT 'Diana Rider' AS pagina, 'N087021' AS rep UNION ALL SELECT 'zOFTV Diana Rider', 'N087021'
        UNION ALL SELECT 'Rocket Reyna VIP', 'E510906' UNION ALL SELECT 'Rocket Reyna OFTV', 'E510906'
        UNION ALL SELECT 'Rebecca', 'E510906' UNION ALL SELECT 'Rebecca OFTV', 'E510906'
        UNION ALL SELECT 'Natasha Nice VIP', 'E510906' UNION ALL SELECT 'Sisi Rose', 'E510906'
        UNION ALL SELECT 'Diana Rider', 'E510906' UNION ALL SELECT 'zOFTV Diana Rider', 'E510906'
        UNION ALL SELECT 'Courtney', 'E510906' UNION ALL SELECT 'Silvia Saige VIP', 'E510906' UNION ALL SELECT 'Silvia Saige Free', 'E510906'
        UNION ALL SELECT 'Tiff 176m', 'R105360' UNION ALL SELECT 'Silvia Saige VIP', 'R105360' UNION ALL SELECT 'Silvia Saige Free', 'R105360'
        UNION ALL SELECT 'Natasha Nice VIP', 'R105360'
        UNION ALL SELECT 'Natasha Nice VIP', 'R045082' UNION ALL SELECT 'Diana Rider', 'R045082' UNION ALL SELECT 'zOFTV Diana Rider', 'R045082'
        UNION ALL SELECT 'Rebecca', 'R045082' UNION ALL SELECT 'Rebecca OFTV', 'R045082' UNION ALL SELECT 'Sisi Rose', 'R045082'
        UNION ALL SELECT 'Mikayla Paid', 'D008138' UNION ALL SELECT 'Rocket Reyna VIP', 'D008138' UNION ALL SELECT 'Rocket Reyna OFTV', 'D008138'
        UNION ALL SELECT 'Sisi Rose', 'D008138' UNION ALL SELECT 'Natasha Nice VIP', 'D008138'
        UNION ALL SELECT 'Silvia Saige VIP', 'D008138' UNION ALL SELECT 'Silvia Saige Free', 'D008138'
        UNION ALL SELECT 'Mikayla Free', 'V088215') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND FIND_IN_SET(x.rep, p.rep_codes) AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-05'));

-- John Balasta para a U3 C núcleo 3 a partir de 05/11
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-04' WHERE id = 660 AND UPPER(rep_code) = 'J010257' AND vigente_ate IS NULL;
INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha, ancora,
                                    folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha, c.ancora,
       c.folgas_fixas, c.pode_assistente, '2026-11-05', NULL, 'ES_111: U1 C núcleo 3 → U3 C núcleo 3 a partir de 05/11/2026 (é do Keller)', 'CARGA_ESTRUTURA'
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = 6
 WHERE c.id = 660 AND NOT EXISTS (SELECT 1 FROM esc_colaborador_config f WHERE f.rep_code = c.rep_code AND f.vigente_desde = '2026-11-05');
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Troca de unidade (ES_111); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'J010257' AND vigente_desde <= '2026-11-04' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'J010257' AND vigente_desde >= '2026-11-05';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_parametro', 'ES_111', NULL,
        '{"plataforma":"uma por turno","om_u1":"restrições de 04/11","J010257":"U3 C n3 desde 05/11"}', NULL);

COMMIT;

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_uma_plataforma_turno', 'v4_sr_pref_assistente', 'v4_sr_so_paginas', 'v4_sr_tier_medio', 'v4_tier_medio',
       'v4_sr_pref_masculinas', 'v4_paginas_masculinas', 'v4_sr_uma_pagina', 'v4_sr_baixo_fluxo');
SELECT id, UPPER(rep_code) AS rep, pode_assistente FROM esc_colaborador_config WHERE id IN (662, 494, 428, 388, 390, 498, 651);
SELECT pagina, UPPER(rep_code) AS rep, papel, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_assistente
 WHERE UPPER(rep_code) IN ('V088215', 'E031058', 'P703279', 'I433559') ORDER BY rep, pagina;
SELECT COUNT(*) AS linhas_equipe_es111 FROM esc_pagina_prioridade WHERE motivo LIKE '%ES_111%';
SELECT UPPER(c.rep_code) AS rep, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id WHERE UPPER(c.rep_code) = 'J010257' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-04');
SELECT COUNT(*) AS vagas_abertas_balasta FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'J010257' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
