SET NAMES utf8mb4;
-- =====================================================================
-- ES_35 · Renumerar as unidades (30/10/2026)
--
-- Pedido do Planejamento: a unidade da Victoria passa a ser a Unidade 1, a do
-- João continua 2 e a do Keller passa a ser a 3. A distribuição (núcleos, postos,
-- SRs, páginas, carteiras) fica igual; só troca o número 1 <-> 3.
--
-- Mexe no 5C Escalas (5c_escalas) e no Capacity (5c_planning):
--   - colunas de unidade (1 <-> 3) e códigos de carteira (U1Cx <-> U3Cx);
--   - os ids dos núcleos e postos não mudam, então escalas, vagas, owners e
--     histórico continuam ligados às mesmas pessoas e páginas.
-- A troca passa por um valor provisório (9 / U9) para não bater nas chaves
-- únicas. As checagens de chave estrangeira ficam LIGADAS: existe uma unidade 9
-- provisória no esc_unidade durante a troca, e as carteiras do Capacity (chave
-- referenciada por fc_pagina_carteira e fc_estrutura_rep) são copiadas, as filhas
-- apontadas para a cópia e a antiga apagada, sem mudar chave no lugar.
-- (1ª versão: o UPDATE da chave de fc_carteira com as checagens desligadas
-- derrubou a conexão — erro 1160 — e a transação voltou inteira.)
--
-- Trava contra rodar duas vezes: só troca se hoje a Unidade 1 é do Keller
-- (OM G105318). Se já rodou, não faz nada (a conferência no fim mostra).
--
-- Depois de rodar: no 5C Escalas, Recalcular a semana 02/11 (só para os rótulos
-- novos nos relatórios) e, se usa o Modo Treino, "Recomeçar o treino".
-- =====================================================================

SET @ok := (SELECT COUNT(*) FROM `5c_escalas`.`esc_lideranca`
             WHERE nivel = 'OM' AND unidade = 1 AND rep_code = 'G105318' AND vigente_ate IS NULL);

START TRANSACTION;

-- unidade 9 provisória (apagada no fim)
INSERT INTO `5c_escalas`.`esc_unidade` (id, nome, guild_id, guild_nome, ativo, atualizado_em, atualizado_por)
SELECT 9, 'ES_35 provisória', NULL, NULL, 0, NOW(), NULL FROM DUAL
 WHERE @ok = 1 AND NOT EXISTS (SELECT 1 FROM `5c_escalas`.`esc_unidade` WHERE id = 9);

-- ---------- 5C Escalas: unidade ----------
UPDATE `5c_escalas`.`esc_nucleo`             SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_escalas`.`esc_nucleo`             SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_escalas`.`esc_nucleo`             SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_escalas`.`esc_lideranca`          SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_escalas`.`esc_lideranca`          SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_escalas`.`esc_lideranca`          SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_escalas`.`esc_colaborador_config` SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_escalas`.`esc_colaborador_config` SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_escalas`.`esc_colaborador_config` SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_escalas`.`esc_capacity_versao_ut` SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_escalas`.`esc_capacity_versao_ut` SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_escalas`.`esc_capacity_versao_ut` SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_escalas`.`esc_hora_extra`         SET unidade = CASE unidade WHEN 1 THEN 3 WHEN 3 THEN 1 END WHERE unidade IN (1, 3) AND @ok = 1;
UPDATE `5c_escalas`.`esc_plano`              SET unidade = CASE unidade WHEN 1 THEN 3 WHEN 3 THEN 1 END WHERE unidade IN (1, 3) AND @ok = 1;
UPDATE `5c_escalas`.`esc_pagina_previsao`    SET destino_unidade = CASE destino_unidade WHEN 1 THEN 3 WHEN 3 THEN 1 END WHERE destino_unidade IN (1, 3) AND @ok = 1;
UPDATE `5c_escalas`.`esc_solicitacao`        SET destino_unidade = CASE destino_unidade WHEN 1 THEN 3 WHEN 3 THEN 1 END WHERE destino_unidade IN (1, 3) AND @ok = 1;

-- ---------- 5C Escalas: postos (unidade + carteira juntos) ----------
UPDATE `5c_escalas`.`esc_posto` SET unidade = 9, carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_escalas`.`esc_posto` SET unidade = 1, carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_escalas`.`esc_posto` SET unidade = 3, carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_escalas`.`esc_capacity_versao_posto` SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_escalas`.`esc_capacity_versao_posto` SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_escalas`.`esc_capacity_versao_posto` SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;

-- ---------- Capacity: carteiras (copia, aponta as filhas, apaga a antiga) ----------
-- U1 -> U9
INSERT INTO `5c_planning`.`fc_carteira` (carteira, unidade, codigo, nome, maior_pagina, ativo, atualizado_em)
SELECT CONCAT('U9', SUBSTRING(carteira, 3)), 9, codigo, nome, maior_pagina, ativo, atualizado_em FROM `5c_planning`.`fc_carteira` WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_estrutura_rep`   SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira` SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
DELETE FROM `5c_planning`.`fc_carteira` WHERE unidade = 1 AND @ok = 1;
-- U3 -> U1
INSERT INTO `5c_planning`.`fc_carteira` (carteira, unidade, codigo, nome, maior_pagina, ativo, atualizado_em)
SELECT CONCAT('U1', SUBSTRING(carteira, 3)), 1, codigo, nome, maior_pagina, ativo, atualizado_em FROM `5c_planning`.`fc_carteira` WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_estrutura_rep`   SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira` SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
DELETE FROM `5c_planning`.`fc_carteira` WHERE unidade = 3 AND @ok = 1;
-- U9 -> U3
INSERT INTO `5c_planning`.`fc_carteira` (carteira, unidade, codigo, nome, maior_pagina, ativo, atualizado_em)
SELECT CONCAT('U3', SUBSTRING(carteira, 3)), 3, codigo, nome, maior_pagina, ativo, atualizado_em FROM `5c_planning`.`fc_carteira` WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_estrutura_rep`   SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira` SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;
DELETE FROM `5c_planning`.`fc_carteira` WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_carteira` SET nome = CONCAT('U', unidade, ' · ', codigo) WHERE unidade IN (1, 3) AND @ok = 1;

-- fc_estrutura_rep: a unidade (a carteira já foi acima)
UPDATE `5c_planning`.`fc_estrutura_rep` SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_estrutura_rep` SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_estrutura_rep` SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

UPDATE `5c_planning`.`fc_postos` SET unidade = 9, carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_postos` SET unidade = 1, carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_postos` SET unidade = 3, carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE unidade = 9 AND @ok = 1;

-- só carteira
UPDATE `5c_planning`.`fc_postos_pagina`        SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_planning`.`fc_postos_pagina`        SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_planning`.`fc_postos_pagina`        SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_carteira` SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_carteira` SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_carteira` SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carga`         SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carga`         SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carga`         SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;

-- só unidade
UPDATE `5c_planning`.`fc_estrutura_lider`       SET unidade = CASE unidade WHEN 1 THEN 3 WHEN 3 THEN 1 END WHERE unidade IN (1, 3) AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_estrutura` SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_estrutura` SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_necessidade_estrutura` SET unidade = 3 WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_buffer_aloc`       SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_buffer_aloc`       SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_buffer_aloc`       SET unidade = 3 WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico`              SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico`              SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico`              SET unidade = 3 WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico_dia`          SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico_dia`          SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_wrk_pico_dia`          SET unidade = 3 WHERE unidade = 9 AND @ok = 1;

-- versões do Capacity (script 19): todas as versões, para o "O que mudou" comparar a mesma unidade
UPDATE `5c_planning`.`fc_capacity_versao_unidade` SET unidade = 9 WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_capacity_versao_unidade` SET unidade = 1 WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_capacity_versao_unidade` SET unidade = 3 WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_capacity_versao_posto` SET unidade = 9, carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_capacity_versao_posto` SET unidade = 1, carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_capacity_versao_posto` SET unidade = 3, carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE unidade = 9 AND @ok = 1;

-- backups da saída da Skylar: se um dia forem restaurados, já vêm com a numeração nova
UPDATE `5c_planning`.`fc_carteira_bkp_skylar` SET unidade = 9, carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE unidade = 1 AND @ok = 1;
UPDATE `5c_planning`.`fc_carteira_bkp_skylar` SET unidade = 1, carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE unidade = 3 AND @ok = 1;
UPDATE `5c_planning`.`fc_carteira_bkp_skylar` SET unidade = 3, carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE unidade = 9 AND @ok = 1;
UPDATE `5c_planning`.`fc_carteira_bkp_skylar` SET nome = CONCAT('U', unidade, ' · ', codigo) WHERE unidade IN (1, 3) AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira_bkp_skylar` SET carteira = CONCAT('U9', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U1%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira_bkp_skylar` SET carteira = CONCAT('U1', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U3%' AND @ok = 1;
UPDATE `5c_planning`.`fc_pagina_carteira_bkp_skylar` SET carteira = CONCAT('U3', SUBSTRING(carteira, 3)) WHERE carteira LIKE 'U9%' AND @ok = 1;

DELETE FROM `5c_escalas`.`esc_unidade` WHERE id = 9 AND nome = 'ES_35 provisória';
COMMIT;

-- ---------- Conferência (esperado: U1 = Victoria, U2 = João, U3 = Keller; nada em 9/U9) ----------
SELECT CONCAT('Unidade ', unidade) AS item, nome AS valor, IF(@ok = 1, 'trocou agora', 'já estava trocado (não mexeu)') AS obs
  FROM `5c_escalas`.`esc_lideranca` WHERE nivel = 'OM' AND vigente_ate IS NULL
UNION ALL SELECT 'Sobrou valor provisório (deve ser 0)',
  (SELECT COUNT(*) FROM `5c_escalas`.`esc_nucleo` WHERE unidade = 9)
  + (SELECT COUNT(*) FROM `5c_escalas`.`esc_posto` WHERE unidade = 9 OR carteira LIKE 'U9%')
  + (SELECT COUNT(*) FROM `5c_planning`.`fc_carteira` WHERE unidade = 9)
  + (SELECT COUNT(*) FROM `5c_planning`.`fc_pagina_carteira` WHERE carteira LIKE 'U9%')
  + (SELECT COUNT(*) FROM `5c_planning`.`fc_capacity_versao_unidade` WHERE unidade = 9)
  + (SELECT COUNT(*) FROM `5c_planning`.`fc_capacity_versao_posto` WHERE unidade = 9 OR carteira LIKE 'U9%'), ''
UNION ALL SELECT 'Versões do Capacity: postos de U1 com carteira de outra unidade (deve ser 0)', COUNT(*), '' FROM `5c_planning`.`fc_capacity_versao_posto` WHERE unidade = 1 AND carteira NOT LIKE 'U1%'
UNION ALL SELECT 'Postos de U1 com carteira de outra unidade (deve ser 0)', COUNT(*), '' FROM `5c_escalas`.`esc_posto` WHERE unidade = 1 AND carteira NOT LIKE 'U1%'
UNION ALL SELECT 'Carteiras', GROUP_CONCAT(CONCAT(carteira, '=', nome) ORDER BY carteira SEPARATOR ', '), '' FROM `5c_planning`.`fc_carteira`;
