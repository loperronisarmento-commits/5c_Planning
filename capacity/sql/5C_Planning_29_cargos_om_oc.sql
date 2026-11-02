SET NAMES utf8mb4;
-- =====================================================================
-- 5C_Planning_29_cargos_om_oc.sql  (02/11, já aplicado pelo MCP)
-- Nomes novos dos cargos na liderança do Capacity (fc_estrutura_lider), iguais aos do 5C Escalas:
--   OUM → OM  (Operational Unit Manager, por unidade, sem turno)
--   OM  → OC  (Operational Coordinator, por unidade e turno)
--   OS  continua (Operational Supervisor)
-- A coluna "om" (o coordenador de cada OS) passa a se chamar "oc".
-- A troca passa por um enum com os 4 valores, para não perder nenhuma linha. A única visão que lê a
-- tabela (vw_fc_11_quadro_vagas) só filtra OS e não muda.
-- Resultado em 02/11: 3 OM, 9 OC e 27 OS.
-- =====================================================================

USE `5c_planning`;

ALTER TABLE fc_estrutura_lider MODIFY nivel ENUM('OUM','OM','OS','OC') NOT NULL;
UPDATE fc_estrutura_lider SET nivel = 'OC' WHERE nivel = 'OM';
UPDATE fc_estrutura_lider SET nivel = 'OM' WHERE nivel = 'OUM';
ALTER TABLE fc_estrutura_lider
  MODIFY nivel ENUM('OM','OC','OS') NOT NULL COMMENT 'OM (Operational Unit Manager, por unidade), OC (Operational Coordinator, por unidade e turno), OS (Operational Supervisor)',
  MODIFY turno CHAR(1) NULL COMMENT 'A, C ou D (OM sem turno)',
  CHANGE om oc VARCHAR(120) NULL COMMENT 'OC do OS';

SELECT nivel, COUNT(*) AS n FROM fc_estrutura_lider GROUP BY nivel;
