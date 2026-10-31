-- =====================================================================
-- ES_24_fecha_cadastro_3x1.sql  (29/10)
-- Cadastro de colaboradores na virada: a configuração antiga (3x1 e
-- "Primaris 3x1 + admin") já fecha em 31/10 para os 265 SRs e OS, que têm
-- a configuração nova (5x2 rotativo 21 dias, por núcleo) a partir de 01/11.
-- Ficaram 15 configurações antigas abertas, sem configuração nova:
--   - 12 da liderança, que seguem a Escala da liderança (esc_lideranca):
--       OM: G105318, J070909, V034940
--       OC: R107827, E009869, H093754, R077585, T051480, D154823, R096272, P094137, H122585
--   - 3 SRs fora do quadro de 01/11 e sem escala desde o começo de outubro:
--       G475628 Gustavo Pereira Costa (último bloco 10/10), L062246 Leticia Amalia
--       Guaragni (04/10), L037393 Luís Felipe Costa Batica Ferreira (sem escala em outubro).
-- Este script fecha essas 15 em 31/10. Se algum dos 3 SRs ainda estiver na
-- operação, coloque num núcleo pela tela Colaboradores (data 01/11).
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_colaborador_config
   SET vigente_ate = '2026-10-31',
       observacao = LEFT(CONCAT(COALESCE(CONCAT(observacao, '; '), ''), 'fechada na virada (ES_24): ',
                    IF(rep_code IN ('G475628', 'L062246', 'L037393'), 'fora do quadro de 01/11', 'liderança segue esc_lideranca')), 255)
 WHERE vigente_ate IS NULL AND vigente_desde < '2026-11-01'
   AND rep_code IN ('G105318', 'J070909', 'V034940',
                    'R107827', 'E009869', 'H093754', 'R077585', 'T051480', 'D154823', 'R096272', 'P094137', 'H122585',
                    'G475628', 'L062246', 'L037393');

-- Conferência
SELECT 'configurações antigas ainda abertas' AS item, COUNT(*) AS tem, '0' AS esperado
  FROM esc_colaborador_config WHERE vigente_ate IS NULL AND vigente_desde < '2026-11-01'
UNION ALL SELECT 'configurações vigentes em 01/11', COUNT(*), '265 (238 SR + 27 OS)'
  FROM esc_colaborador_config WHERE vigente_desde <= '2026-11-01' AND (vigente_ate IS NULL OR vigente_ate >= '2026-11-01')
UNION ALL SELECT 'vigentes em 01/11 fora do 5x2', COUNT(*), '0'
  FROM esc_colaborador_config c JOIN esc_modelo_escala m ON m.id = c.modelo_id
 WHERE c.vigente_desde <= '2026-11-01' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-01') AND m.nome <> '5x2 rotativo 21 dias';
