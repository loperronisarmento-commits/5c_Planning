SET NAMES utf8mb4;
-- =====================================================================
-- ES_112_restricoes_om_u2_maximus.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 (noite), "obrigatório, com prioridade sobre qualquer outro critério", a partir de 05/11:
--   1. Maximus Mota Diniz da Silva (M704415, U3 D n1) pediu conta: cadastro (config 481) e vagas fecham em 04/11.
--   2. Giovanna Fumagalli (G045315): já está no turno A (T2/T3) desde 04/11 (config 681), nada a mudar.
--   3. Só estas páginas (v4_sr_so_paginas):
--        Vinicius Tadewald (V848567, U2 C): Brandi Love e Brandi Love FREE (já é da equipe das duas);
--        Henrique Pasini (H106569, U2 C): as páginas masculinas do Caial (Mo Saffari, Sean Adams, Jay, Sebi Barraza,
--          Matty Caron); entra na equipe da Sean Adams e da Jay (já era das outras três);
--        "Chris Santana" = C144167 (Diego Dominy Zeferino de Santana, U2 A; único Santana do cadastro, a confirmar):
--          Nicole Doshi, Nicole OFTV, CJ Miles e CJ VIP; entra na equipe das Nicole (já era das CJ).
--   4. Divisão 50/50 (esc_sr_divisao):
--        Loik Silva (L052002, U2 C): 50% Aline Novak + 50% Molly Stewart VIP;
--        Marcos Cid (M137535, U2 D): 50% Aline Novak + 50% escala normal, só em páginas de tier médio (v4_sr_tier_medio).
--        Os dois saem da linha da equipe fixa da Aline (a divisão é que reparte os turnos).
--   5. Tamanho da página:
--        José Feitoza (J477490, U2 C): só Brandi Love, Brandi Love FREE, Nicole Doshi, Nicole OFTV, Marie Dee VIP, Midwest Emma;
--        André Ventura (A178283, U2 A): só tier médio;
--        Carlos Vicente (C021331, U2 C): só assistente ou página de 40 a 60 mil em 30 dias (v4_sr_faixa_receita);
--        Thiago Larsen (T082480, U2 C): só assistente ou micro página (v4_sr_baixo_fluxo, até 12 fãs/h);
--        Larissa Soares (L049922, U2 C): só assistente (v4_sr_so_assistente).
--   6. Amanda Lopes (A099370): FORA da Nicole Doshi e da Nicole OFTV.
--   7. João Pedro Caetano da Silva Santos (J102994, U1 C n1): inegociável na U1 (não sai da unidade).
-- =====================================================================

USE `5c_escalas`;

-- A lista de v4_sr_so_paginas passa de 255 caracteres: o valor dos parâmetros vai a 2000.
ALTER TABLE esc_parametro MODIFY valor VARCHAR(2000) NOT NULL;

START TRANSACTION;

-- 1. Maximus
UPDATE esc_colaborador_config SET vigente_ate = '2026-11-04', observacao = LEFT(CONCAT('Pediu conta em 04/11 (ES_112); ', IFNULL(observacao, '')), 255)
 WHERE id = 481 AND UPPER(rep_code) = 'M704415' AND vigente_ate IS NULL;
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-04', motivo = LEFT(CONCAT('Desligamento (ES_112); ', IFNULL(motivo, '')), 255)
 WHERE UPPER(rep_code) = 'M704415' AND vigente_desde <= '2026-11-04' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30'
 WHERE UPPER(rep_code) = 'M704415' AND vigente_desde >= '2026-11-05';

-- 3 e 5. Parâmetros
UPDATE esc_parametro SET valor = CONCAT(valor, ';V848567:Brandi Love|Brandi Love FREE;H106569:Mo Saffari|Sean Adams|Jay|Sebi Barraza|Matty Caron;C144167:Nicole Doshi|Nicole OFTV|CJ Miles|CJ VIP;J477490:Brandi Love|Brandi Love FREE|Nicole Doshi|Nicole OFTV|Marie Dee VIP|Midwest Emma Free 221s'),
       descricao = 'Só estas páginas, nenhuma outra ("CÓDIGO:página|página;..."): Mikayla (U1), Vinicius Tadewald, Henrique Pasini, Chris Santana, José Feitoza'
 WHERE chave = 'v4_sr_so_paginas' AND valor NOT LIKE '%V848567%';
UPDATE esc_parametro SET valor = 'A063277,A178283,M137535', descricao = 'Só páginas de tier médio (v4_tier_medio): André Mendes, André Ventura, Marcos Cid (fora a Aline da divisão)'
 WHERE chave = 'v4_sr_tier_medio';
UPDATE esc_parametro SET valor = 'M175553,V088215,T082480', descricao = 'SRs só em posto de até v4_baixo_fluxo_max fãs/h ou de assistente: Matheus Chagas, Victor Stangl, Thiago Larsen'
 WHERE chave = 'v4_sr_baixo_fluxo';
UPDATE esc_parametro SET valor = 'L118244,L049922', descricao = 'Só entram como assistente, em qualquer página: Letícia Zaleski, Larissa Soares'
 WHERE chave = 'v4_sr_so_assistente';
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
 ('v4_sr_faixa_receita', 'C021331:40000-60000', 'TEXTO', 'v4', 'Só assistente ou página principal com faturamento de 30 dias na faixa ("CÓDIGO:mín-máx;..."): Carlos Vicente 40 a 60 mil')
AS n ON DUPLICATE KEY UPDATE valor = n.valor, descricao = n.descricao;

-- 3. Equipes
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, x.rep, 'Restrição do OM U2 (ES_112)', '2026-11-05', NULL
  FROM (SELECT 'Sean Adams' AS pagina, 'H106569' AS rep UNION ALL SELECT 'Jay', 'H106569'
        UNION ALL SELECT 'Nicole Doshi', 'C144167' UNION ALL SELECT 'Nicole OFTV', 'C144167') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade p WHERE p.pagina = x.pagina AND FIND_IN_SET(x.rep, p.rep_codes) AND (p.vigente_ate IS NULL OR p.vigente_ate >= '2026-11-05'));

-- 4. Divisão; Loik e Marcos saem da linha da equipe da Aline (id 26)
UPDATE esc_pagina_prioridade SET vigente_ate = '2026-11-04' WHERE id = 26 AND pagina = 'Aline Novak' AND vigente_ate IS NULL;
INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde, vigente_ate)
SELECT 'Aline Novak', 'M078957,K169904,G045315,J857800', 'Equipe da Aline sem Loik e Marcos Cid, que dividem 50/50 (ES_112)', '2026-11-05', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Aline Novak' AND vigente_desde = '2026-11-05' AND rep_codes = 'M078957,K169904,G045315,J857800');
INSERT INTO esc_sr_divisao (rep_code, pagina, parte, vigente_desde, motivo)
SELECT x.rep, x.pagina, x.parte, '2026-11-05', x.motivo FROM (
  SELECT 'L052002' AS rep, 'Aline Novak' AS pagina, 0.500 AS parte, 'OM U2: Loik 50% Aline Novak e 50% Molly Stewart (ES_112)' AS motivo
  UNION ALL SELECT 'L052002', 'Molly Stewart VIP', 0.500, 'OM U2: Loik 50% Aline Novak e 50% Molly Stewart (ES_112)'
  UNION ALL SELECT 'M137535', 'Aline Novak', 0.500, 'OM U2: Marcos Cid 50% Aline Novak e 50% páginas médias (ES_112)') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_sr_divisao d WHERE UPPER(d.rep_code) = x.rep AND d.pagina = x.pagina AND d.vigente_ate IS NULL);

-- 6. Amanda fora das Nicole
INSERT INTO esc_pagina_assistente (pagina, rep_code, papel, motivo, vigente_desde, vigente_ate)
SELECT x.pagina, 'A099370', 'FORA', 'Restrição do OM U2 (ES_112)', '2026-11-05', NULL
  FROM (SELECT 'Nicole Doshi' AS pagina UNION ALL SELECT 'Nicole OFTV') x
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_assistente a WHERE a.pagina = x.pagina AND UPPER(a.rep_code) = 'A099370' AND a.papel = 'FORA' AND a.vigente_ate IS NULL);

-- 7. João Pedro Caetano inegociável na U1
UPDATE esc_colaborador_config SET observacao = LEFT(CONCAT('Inegociável na U1 (ES_112); ', IFNULL(observacao, '')), 255)
 WHERE id = 405 AND UPPER(rep_code) = 'J102994' AND vigente_ate IS NULL AND IFNULL(observacao, '') NOT LIKE '%Inegociável na U1%';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'restricao.ajuste', 'esc_parametro', 'ES_112', NULL,
        '{"M704415":"saiu em 04/11","om_u2":"restrições de 04/11","J102994":"inegociável na U1"}', NULL);

COMMIT;

SELECT chave, valor FROM esc_parametro WHERE chave IN ('v4_sr_so_paginas', 'v4_sr_tier_medio', 'v4_sr_baixo_fluxo', 'v4_sr_so_assistente', 'v4_sr_faixa_receita');
SELECT UPPER(rep_code) AS rep, pagina, parte, DATE(vigente_desde) AS de FROM esc_sr_divisao WHERE vigente_ate IS NULL ORDER BY rep, pagina;
SELECT pagina, rep_codes, DATE(vigente_desde) AS de, DATE(vigente_ate) AS ate FROM esc_pagina_prioridade WHERE pagina = 'Aline Novak' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-04');
SELECT id, UPPER(rep_code) AS rep, DATE(vigente_ate) AS ate, LEFT(observacao, 60) AS obs FROM esc_colaborador_config WHERE id IN (481, 405, 681);
SELECT COUNT(*) AS vagas_abertas_maximus FROM esc_vaga_equipe WHERE UPPER(rep_code) = 'M704415' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-05');
SELECT pagina, UPPER(rep_code) AS rep, papel FROM esc_pagina_assistente WHERE UPPER(rep_code) = 'A099370' AND vigente_ate IS NULL;
