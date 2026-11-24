-- 5C_Planning_42 (24/11/2026): o diário do Capacity (sp_fc_rodar_dia) falhava na etapa "sla" desde 07/11:
--   Duplicate entry '2026-11-23-14-CJ Miles' for key 'fc_sla_hora_excluida.PRIMARY'
-- Causa: posto com 2 pessoas (fc_posto_fixo.pessoas = 2) vira dois postos na versão do Capacity com as
-- mesmas páginas (ex.: CJ Miles + CJ VIP nos postos 6 e 7 da U2C1). A mesma página saía duas vezes na
-- mesma hora. Correção: uma linha por (dia, hora, página), no menor posto. A regra não muda: a hora sai
-- do SLA quando os fãs do posto passam da capacidade.
-- Auditoria de 24/11 (A-03). Rodar pelo mysql.exe (tem DELIMITER).
SET NAMES utf8mb4;
USE `5c_planning`;

-- Antes: falhas do diário desde 07/11
SELECT DATE(inicio) AS dia, etapa, status, LEFT(mensagem, 120) AS msg
  FROM fc_log_execucao WHERE inicio >= '2026-11-07' AND status = 'ERRO' ORDER BY inicio;

DROP PROCEDURE IF EXISTS sp_fc_sla_horas;
DELIMITER $$
CREATE PROCEDURE sp_fc_sla_horas(IN p_dia DATE, IN p_versao INT)
BEGIN
  DECLARE v INT;
  SET v = p_versao;
  IF v IS NULL THEN
    SELECT MAX(versao) INTO v FROM fc_capacity_versao WHERE data_calculo <= p_dia AND origem <> 'RECONSTRUIDA';
  END IF;

  DELETE FROM fc_sla_hora_excluida WHERE dia = p_dia;
  IF v IS NOT NULL THEN
    INSERT INTO fc_sla_hora_excluida (dia, hora, pagina, carteira, turno, posto, versao, fans_posto, capacidade)
    SELECT p_dia, x.hora, vp.pagina, MIN(x.carteira), MIN(x.turno), MIN(x.posto), v, MAX(x.fans), MAX(x.cap)
    FROM (SELECT vp.carteira, vp.turno, vp.posto, h.hora, SUM(h.fans) AS fans, MAX(vp.capacidade) AS cap
          FROM fc_capacity_versao_posto vp
          JOIN fc_fans_pagina_hora h ON h.pagina = vp.pagina AND h.turno = vp.turno AND h.dia_turno = p_dia
          WHERE vp.versao = v AND vp.forcado = 1
          GROUP BY vp.carteira, vp.turno, vp.posto, h.hora
          HAVING SUM(h.fans) > MAX(vp.capacidade)) x
    JOIN fc_capacity_versao_posto vp ON vp.versao = v AND vp.carteira = x.carteira
                                    AND vp.turno = x.turno AND vp.posto = x.posto
    GROUP BY x.hora, vp.pagina;
  END IF;

  DELETE FROM fc_sla_hora_excluida WHERE dia < p_dia - INTERVAL 400 DAY;
END$$
DELIMITER ;

-- Teste no dia que falhou hoje (23/11) e refaz os dias de 07/11 em diante.
CALL sp_fc_sla_horas('2026-11-23', NULL);

-- Depois: linhas por dia (uma por hora × página)
SELECT dia, COUNT(*) AS linhas, COUNT(DISTINCT pagina) AS paginas FROM fc_sla_hora_excluida
 WHERE dia >= '2026-11-07' GROUP BY dia ORDER BY dia;

-- Refaz as horas excluídas dos dias que ficaram sem (05 a 20/11), na versão de cada dia.
CALL sp_fc_sla_horas('2026-11-05', NULL);
CALL sp_fc_sla_horas('2026-11-06', NULL);
CALL sp_fc_sla_horas('2026-11-07', NULL);
CALL sp_fc_sla_horas('2026-11-08', NULL);
CALL sp_fc_sla_horas('2026-11-09', NULL);
CALL sp_fc_sla_horas('2026-11-10', NULL);
CALL sp_fc_sla_horas('2026-11-11', NULL);
CALL sp_fc_sla_horas('2026-11-12', NULL);
CALL sp_fc_sla_horas('2026-11-13', NULL);
CALL sp_fc_sla_horas('2026-11-14', NULL);
CALL sp_fc_sla_horas('2026-11-15', NULL);
CALL sp_fc_sla_horas('2026-11-16', NULL);
CALL sp_fc_sla_horas('2026-11-17', NULL);
CALL sp_fc_sla_horas('2026-11-18', NULL);
CALL sp_fc_sla_horas('2026-11-19', NULL);
CALL sp_fc_sla_horas('2026-11-20', NULL);
SELECT dia, COUNT(*) AS linhas FROM fc_sla_hora_excluida WHERE dia >= '2026-11-05' GROUP BY dia ORDER BY dia;
