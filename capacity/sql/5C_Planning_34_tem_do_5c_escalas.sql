SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_34_tem_do_5c_escalas.sql  (09/11/2026)
--
-- O "tem" do Capacity passa a vir do cadastro do 5C Escalas.
--
-- Antes: sp_fc_postos conta os reps de fc_estrutura_rep, que só tinha a foto provisória de 27/10
-- (script 12, 220 reps, vigente desde 01/11). Ninguém atualizava a tabela, e o "tem" ficou em 220
-- em todas as versões. Em 09/11 o 5C Escalas tinha 221 SRs (funcao = 'EQUITE') e outra divisão:
-- 130 iguais, 75 mudaram de unidade e/ou turno, 16 entraram e 15 saíram (11 desligados, 4 viraram OS).
-- Lista por SR: resultados/capacity_tem_0911/diferencas_por_sr.csv (fora do git).
--
-- Como fica:
--   1. fc_estrutura_rep ganha a coluna origem (FOTO_2710 | ESCALAS); carteira passa a aceitar NULL.
--   2. A foto de 27/10 vale até 08/11 (vigente_ate = 2026-11-09, que é exclusivo nesta tabela).
--      As versões já gravadas não mudam, e um recálculo de data anterior a 09/11 usa a foto.
--   3. sp_fc_estrutura_rep_escalas() espelha esc_colaborador_config (EQUITE) em fc_estrutura_rep a
--      partir de 09/11, com o histórico e as mudanças já agendadas. Apaga e regrava só as linhas
--      ESCALAS, então pode rodar quantas vezes for preciso.
--        - vigente_ate do Escalas é inclusivo; aqui é exclusivo (+1 dia), como o sp_fc_postos lê.
--        - os = nome do OS do núcleo (esc_nucleo.decurio_nome).
--        - carteira: a da foto, se o SR continua na mesma unidade; senão, a carteira das páginas em que
--          ele é fixo (esc_pagina_prioridade) na unidade dele; senão NULL. O SR sem carteira conta no
--          "tem" da unidade e turno, mas não no "tem" da carteira (vw_fc_9_carteiras).
--   4. sp_fc_recalcular_postos (o Gerar e as Saídas do 5C Escalas usam) e sp_fc_rodar_dia (diário)
--      chamam a sincronização antes do sp_fc_postos. O sp_fc_postos não muda.
--   5. Recalcula 09/11 e 10/11 para gravar as versões novas com o "tem" do Escalas.
--
-- Rodar pelo mysql.exe com o 5c.cnf (tem CALL, SET e DELIMITER; o MCP desfaz CALL com rollback).
-- Pode rodar de novo: o passo 1 confere se a coluna já existe e o 2 só fecha linhas abertas.
-- =====================================================================

USE `5c_planning`;

-- ---------------------------------------------------------------------
-- 0. Antes: a última versão e o quadro de hoje no Escalas
-- ---------------------------------------------------------------------
SELECT 'antes' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.origem, v.precisa, v.tem, v.saldo
  FROM fc_capacity_versao v WHERE v.versao = (SELECT MAX(versao) FROM fc_capacity_versao);

SELECT 'antes' AS momento, origem_dado, unidade, turno, COUNT(*) AS reps FROM (
  SELECT 'fc_estrutura_rep' AS origem_dado, unidade, turno FROM fc_estrutura_rep
   WHERE ficticio = 0 AND vigente_desde <= CURDATE() AND (vigente_ate IS NULL OR vigente_ate > CURDATE())
  UNION ALL
  SELECT 'esc_colaborador_config', unidade, turno FROM `5c_escalas`.esc_colaborador_config
   WHERE funcao = 'EQUITE' AND vigente_desde <= CURDATE() AND (vigente_ate IS NULL OR vigente_ate >= CURDATE())
) x GROUP BY origem_dado, unidade, turno WITH ROLLUP;

-- ---------------------------------------------------------------------
-- 1. Estrutura da tabela
-- ---------------------------------------------------------------------
SET @tem_origem = (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = '5c_planning' AND TABLE_NAME = 'fc_estrutura_rep' AND COLUMN_NAME = 'origem');
SET @sql = IF(@tem_origem = 0,
  'ALTER TABLE fc_estrutura_rep
     MODIFY carteira VARCHAR(8) NULL COMMENT ''NULL = SR do 5C Escalas sem carteira definida (conta só na unidade e turno)'',
     ADD COLUMN origem ENUM(''FOTO_2710'',''ESCALAS'') NOT NULL DEFAULT ''FOTO_2710''
       COMMENT ''FOTO_2710 = carga do script 12; ESCALAS = espelho de 5c_escalas.esc_colaborador_config (script 34)'' AFTER ficticio',
  'SELECT ''coluna origem já existe'' AS aviso');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 2. A foto de 27/10 vale até 08/11
-- ---------------------------------------------------------------------
UPDATE fc_estrutura_rep SET vigente_ate = '2026-11-09'
 WHERE origem = 'FOTO_2710' AND vigente_ate IS NULL;

DELIMITER $$

-- ---------------------------------------------------------------------
-- 3. Espelho do cadastro do 5C Escalas
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_estrutura_rep_escalas $$
CREATE PROCEDURE sp_fc_estrutura_rep_escalas()
BEGIN
  -- Dia em que o "tem" passou a vir do Escalas (antes dele vale a foto de 27/10).
  DECLARE v_corte DATE DEFAULT '2026-11-09';
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  DELETE FROM fc_estrutura_rep WHERE origem = 'ESCALAS';

  INSERT INTO fc_estrutura_rep (code, vigente_desde, vigente_ate, unidade, carteira, turno, nivel, os, ficticio, origem)
  SELECT c.rep_code,
         GREATEST(c.vigente_desde, v_corte),
         c.vigente_ate + INTERVAL 1 DAY,
         c.unidade,
         COALESCE(f.carteira, pg.carteira),
         c.turno,
         c.nivel,
         n.decurio_nome,
         0,
         'ESCALAS'
    FROM `5c_escalas`.esc_colaborador_config c
    LEFT JOIN `5c_escalas`.esc_nucleo n ON n.id = c.nucleo_id
    -- carteira da foto, se o SR continua na mesma unidade
    LEFT JOIN (SELECT code, unidade, MAX(carteira) AS carteira
                 FROM fc_estrutura_rep
                WHERE origem = 'FOTO_2710' AND ficticio = 0
                GROUP BY code, unidade) f
           ON f.code COLLATE utf8mb4_unicode_ci = c.rep_code COLLATE utf8mb4_unicode_ci
          AND f.unidade = c.unidade
    -- senão, a carteira em que ele é fixo em mais páginas, na unidade dele
    LEFT JOIN (SELECT x.rep_code, x.unidade,
                      SUBSTRING_INDEX(GROUP_CONCAT(x.carteira ORDER BY x.paginas DESC, x.carteira), ',', 1) AS carteira
                 FROM (SELECT s.rep_code, k.unidade, pc.carteira, COUNT(*) AS paginas
                         FROM `5c_escalas`.esc_pagina_prioridade p
                         JOIN fc_pagina_carteira pc
                           ON pc.pagina COLLATE utf8mb4_unicode_ci = p.pagina COLLATE utf8mb4_unicode_ci
                          AND pc.vigente_desde <= CURDATE() AND (pc.vigente_ate IS NULL OR pc.vigente_ate > CURDATE())
                         JOIN fc_carteira k ON k.carteira = pc.carteira
                         JOIN (SELECT DISTINCT rep_code, unidade FROM `5c_escalas`.esc_colaborador_config
                                WHERE funcao = 'EQUITE' AND unidade IS NOT NULL) s
                           ON s.unidade = k.unidade
                          AND FIND_IN_SET(s.rep_code COLLATE utf8mb4_unicode_ci,
                                          REPLACE(p.rep_codes, ' ', '') COLLATE utf8mb4_unicode_ci) > 0
                        WHERE p.vigente_desde <= CURDATE() AND (p.vigente_ate IS NULL OR p.vigente_ate >= CURDATE())
                        GROUP BY s.rep_code, k.unidade, pc.carteira) x
                GROUP BY x.rep_code, x.unidade) pg
           ON pg.rep_code = c.rep_code AND pg.unidade = c.unidade
   WHERE c.funcao = 'EQUITE'
     AND c.unidade IS NOT NULL AND c.turno IS NOT NULL AND c.nivel IS NOT NULL
     AND COALESCE(c.vigente_ate, '9999-12-31') >= v_corte;

  COMMIT;
END $$

-- ---------------------------------------------------------------------
-- 4. Recálculo à mão e orquestração diária sincronizam antes do sp_fc_postos
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_fc_recalcular_postos $$
CREATE PROCEDURE sp_fc_recalcular_postos(IN p_data DATE, IN p_obs VARCHAR(255))
BEGIN
  -- script 34: o "tem" vem do cadastro do 5C Escalas
  CALL sp_fc_estrutura_rep_escalas();
  CALL sp_fc_postos(p_data);
  CALL sp_fc_capacity_versao(p_data, 'MANUAL', p_obs);
END $$

DROP PROCEDURE IF EXISTS sp_fc_rodar_dia $$
CREATE PROCEDURE sp_fc_rodar_dia()
BEGIN
  DECLARE v_d DATE DEFAULT CURDATE();
  DECLARE v_log INT;
  DECLARE v_msg VARCHAR(500);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_msg = MESSAGE_TEXT;
    UPDATE fc_log_execucao SET fim = NOW(), status = 'ERRO', mensagem = LEFT(v_msg, 500) WHERE id = v_log;
  END;

  INSERT INTO fc_log_execucao (data_calculo, etapa, inicio) VALUES (v_d, 'carga_fans', NOW());
  SET v_log = LAST_INSERT_ID();

  CALL sp_fc_carga_fans(v_d - INTERVAL 2 DAY, v_d - INTERVAL 1 DAY);
  UPDATE fc_log_execucao SET etapa = 'hc_to' WHERE id = v_log;
  CALL sp_fc_hc_to();
  UPDATE fc_log_execucao SET etapa = 'paginas_eventos' WHERE id = v_log;
  CALL sp_fc_paginas_eventos(v_d);
  UPDATE fc_log_execucao SET etapa = 'prev_to' WHERE id = v_log;
  CALL sp_fc_prev_to(v_d);
  UPDATE fc_log_execucao SET etapa = 'escala' WHERE id = v_log;
  CALL sp_fc_escala(v_d);
  UPDATE fc_log_execucao SET etapa = 'buffer' WHERE id = v_log;
  CALL sp_fc_buffer(v_d);
  UPDATE fc_log_execucao SET etapa = 'impacto_paginas' WHERE id = v_log;
  CALL sp_fc_impacto_paginas(v_d);
  -- etapa do script 34: o "tem" vem do cadastro do 5C Escalas
  UPDATE fc_log_execucao SET etapa = 'estrutura_rep' WHERE id = v_log;
  CALL sp_fc_estrutura_rep_escalas();
  UPDATE fc_log_execucao SET etapa = 'postos' WHERE id = v_log;
  CALL sp_fc_postos(v_d);
  -- etapa do script 19 (versões do capacity e plano sugerido); numa instalação nova, rodar o 19 logo depois
  UPDATE fc_log_execucao SET etapa = 'versao' WHERE id = v_log;
  CALL sp_fc_capacity_versao(v_d, 'DIARIO', NULL);
  -- etapa do script 23: horas fora do SLA nos postos da regra de faturamento (fãs de ontem e anteontem)
  UPDATE fc_log_execucao SET etapa = 'sla' WHERE id = v_log;
  CALL sp_fc_sla_horas(v_d - INTERVAL 2 DAY, NULL);
  CALL sp_fc_sla_horas(v_d - INTERVAL 1 DAY, NULL);

  UPDATE fc_log_execucao SET etapa = 'fim', fim = NOW(), status = 'OK' WHERE id = v_log;
END $$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 5. Versões novas de 09/11 e 10/11 com o "tem" do Escalas (rodado em 10/11, depois do diário das 06h30)
-- ---------------------------------------------------------------------
CALL sp_fc_recalcular_postos('2026-11-09', 'Tem passa a vir do cadastro do 5C Escalas (script 34)');
CALL sp_fc_recalcular_postos('2026-11-10', 'Tem passa a vir do cadastro do 5C Escalas (script 34)');

-- ---------------------------------------------------------------------
-- 6. Depois: o espelho, a versão nova e a conferência por unidade e turno (dif tem de dar 0)
-- ---------------------------------------------------------------------
SELECT 'depois' AS momento, origem, COUNT(*) AS linhas, SUM(carteira IS NULL) AS sem_carteira,
       MIN(vigente_desde) AS desde, MAX(vigente_ate) AS ate
  FROM fc_estrutura_rep GROUP BY origem;

SELECT 'depois' AS momento, v.versao, DATE(v.data_calculo) AS data_calculo, v.origem, v.obs, v.precisa, v.tem, v.saldo
  FROM fc_capacity_versao v WHERE v.versao > 76 ORDER BY v.versao;

SELECT v.versao, DATE(v.data_calculo) AS data_calculo, u.unidade, u.turno, u.tem AS tem_versao,
       (SELECT COUNT(*) FROM `5c_escalas`.esc_colaborador_config c
         WHERE c.funcao = 'EQUITE' AND c.unidade = u.unidade
           AND c.turno COLLATE utf8mb4_unicode_ci = u.turno COLLATE utf8mb4_unicode_ci
           AND c.vigente_desde <= v.data_calculo AND (c.vigente_ate IS NULL OR c.vigente_ate >= v.data_calculo)) AS escalas,
       u.tem - (SELECT COUNT(*) FROM `5c_escalas`.esc_colaborador_config c
         WHERE c.funcao = 'EQUITE' AND c.unidade = u.unidade
           AND c.turno COLLATE utf8mb4_unicode_ci = u.turno COLLATE utf8mb4_unicode_ci
           AND c.vigente_desde <= v.data_calculo AND (c.vigente_ate IS NULL OR c.vigente_ate >= v.data_calculo)) AS dif
  FROM fc_capacity_versao v
  JOIN fc_capacity_versao_unidade u ON u.versao = v.versao
 WHERE v.versao > 76
 ORDER BY v.versao, u.unidade, u.turno;
