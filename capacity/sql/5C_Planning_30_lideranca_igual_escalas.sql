SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_30_lideranca_igual_escalas.sql  (02/11, aplicado pelo Claude via MCP)
-- A liderança do Capacity (fc_estrutura_lider, que a Nora usa) parou na carga de 01/11 (script 12) e
-- não acompanhou os ajustes de liderança do 5C Escalas.
-- Antes desta correção:
--   - só 9 dos 27 OS estavam na mesma unidade e turno que nas escalas (ex.: a Isa Albuquerque, OS1 da
--     U3 C abaixo do Eduardo, não estava; a Isa Marinho aparecia na U2 A em vez da U2 C);
--   - Henrique Dubles e Pedro Ribeiro estavam com os turnos C e D trocados;
--   - o Raphael Estevam (OC da U3 A) não estava.
-- Fonte da verdade: 5c_escalas (esc_lideranca para OM e OC, esc_nucleo para os OS). As linhas de antes
-- fecham em 02/11 e as novas valem a partir de 03/11 (o histórico fica). Resultado: 3 OM, 9 OC e
-- 27 OS, iguais às escalas.
-- Pode rodar de novo só se não houver linhas abertas de 03/11 (senão duplica).
-- =====================================================================

UPDATE `5c_planning`.`fc_estrutura_lider` SET vigente_ate = '2026-11-02' WHERE vigente_ate IS NULL AND vigente_desde <= '2026-11-02';

INSERT INTO `5c_planning`.`fc_estrutura_lider` (nome, vigente_desde, vigente_ate, nivel, unidade, turno, oc, code)
SELECT l.nome, '2026-11-03', NULL, l.nivel, l.unidade, IF(l.nivel = 'OM', NULL, l.turno), NULL, UPPER(l.rep_code)
  FROM `5c_escalas`.`esc_lideranca` l
 WHERE l.nivel IN ('OM', 'OC') AND l.vigente_desde <= '2026-11-03' AND (l.vigente_ate IS NULL OR l.vigente_ate >= '2026-11-03')
 ORDER BY l.nivel DESC, l.unidade, l.turno;

INSERT INTO `5c_planning`.`fc_estrutura_lider` (nome, vigente_desde, vigente_ate, nivel, unidade, turno, oc, code)
SELECT n.decurio_nome, '2026-11-03', NULL, 'OS', n.unidade, n.turno,
       (SELECT MAX(l.nome) FROM `5c_escalas`.`esc_lideranca` l WHERE l.nivel = 'OC' AND l.unidade = n.unidade AND l.turno = n.turno
           AND l.vigente_desde <= '2026-11-03' AND (l.vigente_ate IS NULL OR l.vigente_ate >= '2026-11-03')),
       UPPER(n.decurio_code)
  FROM `5c_escalas`.`esc_nucleo` n
 WHERE n.vigente_ate IS NULL AND n.decurio_code IS NOT NULL
 ORDER BY n.unidade, n.turno, n.numero;

-- Conferência: OS por unidade e turno nas duas tabelas (todas as linhas com "ok")
SELECT x.unidade, x.turno, IF(x.escalas = y.capacity, 'ok', 'DIFERE') AS confere
  FROM (SELECT unidade, turno, GROUP_CONCAT(decurio_code ORDER BY decurio_code) AS escalas FROM `5c_escalas`.`esc_nucleo` WHERE vigente_ate IS NULL GROUP BY unidade, turno) x
  LEFT JOIN (SELECT unidade, turno, GROUP_CONCAT(code ORDER BY code) AS capacity FROM `5c_planning`.`fc_estrutura_lider` WHERE nivel = 'OS' AND vigente_ate IS NULL GROUP BY unidade, turno) y
    ON y.unidade = x.unidade AND y.turno = x.turno COLLATE utf8mb4_unicode_ci
 ORDER BY x.unidade, x.turno;
