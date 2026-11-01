-- =====================================================================
-- ES_66_equipe_molly.sql  (01/11)
-- Pedido dos managers: a Molly Stewart rodava com equipe fixa batendo meta e tem muitas regras
-- próprias, então fica a galera que já conhece a página. Na estrutura de 01/11 a Molly foi para a
-- U2C1, mas o time dela ficou espalhado (só a Marina na U2). A partir de 02/11, 2 por turno:
--   A: Marina M497745 (já na U2 A, núcleo 11) + Társila T102316 (U1 A -> U2 A, núcleo 10)
--   C: Letícia L434531 (U3 C -> U2 C, núcleo 15) + Esley E123445 (U1 C -> U2 C, núcleo 13)
--      (a Carolina C099427 é OS da U1 C e fica onde está)
--   D: Leonardo L173493 (U3 D -> U2 D, núcleo 17) + Bruno B163774 (U1 D -> U2 D, núcleo 16)
--      e o Danilo D422040, que já está na U2 D e conhece a página.
-- Núcleo dono e de cobertura de cada turno = os dos postos da Molly na U2C1, para um cobrir a
-- folga do outro. A equipe vai para a esc_pagina_prioridade: o motor (1.18.1) põe quem é da
-- equipe e está no turno na página antes do "melhor SR".
-- Vagas fixas antigas dos 5 que mudam: fecham em 01/11; as que começariam depois saem.
-- Já aplicado em 01/11 pelo MCP.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

DROP TEMPORARY TABLE IF EXISTS tmp_es66;
CREATE TEMPORARY TABLE tmp_es66 (rep_code VARCHAR(20) PRIMARY KEY, nucleo_id INT NOT NULL)
  DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
INSERT INTO tmp_es66 VALUES ('T102316', 10), ('L434531', 15), ('E123445', 13), ('L173493', 17), ('B163774', 16);

INSERT INTO esc_colaborador_config (rep_code, modelo_id, tipo, turno, unidade, nucleo_id, nivel, funcao, fatia_regular, trilha,
       ancora, folgas_fixas, pode_assistente, vigente_desde, vigente_ate, observacao, origem)
SELECT c.rep_code, c.modelo_id, c.tipo, n.turno, n.unidade, n.id, c.nivel, c.funcao, c.fatia_regular, c.trilha,
       c.ancora, c.folgas_fixas, c.pode_assistente, '2026-11-02', NULL, 'ES_66: equipe fixa da Molly Stewart', 'TELA'
  FROM esc_colaborador_config c
  JOIN tmp_es66 t ON t.rep_code = c.rep_code
  JOIN esc_nucleo n ON n.id = t.nucleo_id
 WHERE c.vigente_ate IS NULL AND c.vigente_desde <= '2026-11-01' AND c.funcao = 'EQUITE';
UPDATE esc_colaborador_config c JOIN tmp_es66 t ON t.rep_code = c.rep_code
   SET c.vigente_ate = '2026-11-01'
 WHERE c.vigente_ate IS NULL AND c.vigente_desde <= '2026-11-01';

DELETE FROM esc_vaga_equipe WHERE rep_code IN (SELECT rep_code FROM tmp_es66) AND vigente_desde >= '2026-11-02';
UPDATE esc_vaga_equipe SET vigente_ate = '2026-11-01'
 WHERE rep_code IN (SELECT rep_code FROM tmp_es66) AND vigente_ate IS NULL;

INSERT INTO esc_pagina_prioridade (pagina, rep_codes, motivo, vigente_desde)
SELECT 'Molly Stewart VIP', 'M497745,T102316,L434531,E123445,L173493,B163774,D422040',
       'Manager: equipe fixa da Molly (página com muitas regras, batendo meta)', '2026-11-02'
 WHERE NOT EXISTS (SELECT 1 FROM esc_pagina_prioridade WHERE pagina = 'Molly Stewart VIP' AND vigente_ate IS NULL);

DROP TEMPORARY TABLE tmp_es66;
