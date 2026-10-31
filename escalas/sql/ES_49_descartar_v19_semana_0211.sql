-- =====================================================================
-- ES_49_descartar_v19_semana_0211.sql  (31/10)
-- A escala da semana de 02/11 foi apagada do site para ser gerada do zero.
-- A v19.1 (plano 63) deixa de ser a publicada: com ela publicada, o "Gerar"
-- mantém quem já estava nela. Fica como DESCARTADO (só consulta).
-- A semana de 26/10 (v23.1, plano 62) não muda.
-- Depois: Gerar escala › semana de 02/11 › gerar, conferir, publicar e postar
-- todas as páginas.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_plano SET status = 'DESCARTADO'
 WHERE id = 63 AND semana_inicio = '2026-11-02' AND status = 'PUBLICADO';

-- Conferência: a semana de 02/11 não deve ter nenhuma versão PUBLICADO
SELECT id, semana_inicio, versao, subversao, status FROM esc_plano WHERE semana_inicio IN ('2026-10-26', '2026-11-02') ORDER BY semana_inicio, versao, subversao;
