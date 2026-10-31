-- =====================================================================
-- ES_50_marcos_ciclo_rafael.sql  (31/10)
-- Folga juntos: o Rafael Garcia da Costa (R169247) foi para a U1 D núcleo 2
-- (ciclo 14) pela tela, a partir de 02/11; o ES_46 tinha posto o Marcos
-- Vinicius Cid Mélo (M137535) na U2 D núcleo 3 (ciclo 7) a partir de 09/11.
-- Para os dois folgarem juntos, o Marcos vai para a U2 D núcleo 2 (ciclo 14),
-- o mesmo ciclo que ele já tem hoje no C: só muda o turno, sem emenda.
-- Só roda se o cadastro estiver como o ES_46 deixou. Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

SET @ok := (SELECT COUNT(*) FROM esc_colaborador_config
             WHERE id = 567 AND UPPER(rep_code) = 'M137535' AND nucleo_id = 18 AND vigente_desde = '2026-11-09' AND vigente_ate IS NULL);
UPDATE esc_colaborador_config
   SET nucleo_id = 17,
       observacao = 'Folga juntos a partir de 09/11/2026 (ES_46, ES_50): marcou A/D, estava no C; casal com Rafael Garcia da Costa (U1 D núcleo 2, ciclo 14)'
 WHERE id = 567 AND @ok = 1;
INSERT INTO esc_log (acao, entidade, entidade_id, antes, depois, canal)
SELECT 'casal.ajuste', 'esc_colaborador_config', '567', JSON_OBJECT('nucleo_id', 18), JSON_OBJECT('rep', 'M137535', 'nucleo_id', 17, 'desde', '2026-11-09', 'script', 'ES_50'), 'SISTEMA'
  FROM DUAL WHERE @ok = 1;

-- Conferência: os 2 do par 2 com o mesmo ciclo em 09/11 (14)
SELECT @ok AS aplicado;
SELECT UPPER(c.rep_code) AS rep, c.unidade, c.turno, n.numero AS nucleo, n.deslocamento AS ciclo, c.vigente_desde
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) IN ('M137535', 'R169247') AND c.vigente_desde <= '2026-11-09' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-09');
