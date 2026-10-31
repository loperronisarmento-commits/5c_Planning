-- =====================================================================
-- ES_20_lideranca_no_plano.sql  (29/10)
-- A escala da liderança entra no mesmo plano dos SRs: quem trabalha no turno vai
-- para a página 5C Schedule, nos blocos do turno, e sai no mesmo CSV com o sufixo
-- do cargo (como o -2 do Buffer e o -5 do Overlap):
--   Operational Supervisor  -8   (origem LIDER_OS)
--   Operational Coordinator -7   (origem LIDER_OC)
--   Operational Assistant   -6   (origem LIDER_OA)
-- Precisa do ES_19 e do zip do sistema de 29/10. Depois: gerar de novo as semanas
-- de 26/10 e 02/11.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

ALTER TABLE esc_plano_alocacao
  MODIFY origem ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA',
                     'VAGA_DONO','VAGA_COBERTURA','BUFFER_NUCLEO','TRANSICAO','OVERLAP','SOBRECARGA',
                     'LIDER_OS','LIDER_OC','LIDER_OA') NOT NULL;

-- Conferência
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = '5c_escalas' AND TABLE_NAME = 'esc_plano_alocacao' AND COLUMN_NAME = 'origem';
