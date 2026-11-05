SET NAMES utf8mb4;
-- =====================================================================
-- ES_119_penellopy_fica_u3.sql  (04/11, aplicado pelo Claude via mysql.exe)
--
-- Pedido do usuário em 04/11 ("Penellopy mantém na U3"): Penellopy Luisa Sousa de Oliveira Rosário
-- (P176228, U3 C núcleo 2, SR 3, cover da Lexis Star no C pela planilha do OM da U3) iria para a U2 C
-- núcleo 3 em 09/11 pelo ES_80 (compensação do Jean Larraine na Fanvue). Fica na U3 C núcleo 2:
--   - a linha 665 (U2 desde 09/11) passa a ser U3 C núcleo 2 desde 09/11 (continua a linha 600);
--   - as vagas da U3 que fechavam em 08/11 seguem abertas; as da U2 desde 09/11 vão para a data neutra.
-- Vanderson (V115422, U2 A): mantém a regra atual (50% Brandi Love e 50% Elly Clutch, cover da Elly no A
-- como último caso). Nada a mudar.
-- =====================================================================

USE `5c_escalas`;

START TRANSACTION;

UPDATE esc_colaborador_config c JOIN esc_nucleo n ON n.unidade = 3 AND n.turno = 'C' AND n.numero = 2
   SET c.unidade = 3, c.nucleo_id = n.id,
       c.observacao = 'Fica na U3 C núcleo 2 (ES_119): desfeita a ida para a U2 em 09/11 (ES_80); cover da Lexis Star no C'
 WHERE c.id = 665 AND UPPER(c.rep_code) = 'P176228' AND c.vigente_desde = '2026-11-09';

UPDATE esc_vaga_equipe SET vigente_ate = NULL, motivo = LEFT(CONCAT('Fica na U3 (ES_119); ', IFNULL(motivo, '')), 255)
 WHERE id IN (3007, 3449) AND UPPER(rep_code) = 'P176228' AND vigente_ate = '2026-11-08';
UPDATE esc_vaga_equipe SET vigente_desde = '2026-10-30', vigente_ate = '2026-10-30', motivo = LEFT(CONCAT('Não vai para a U2 (ES_119); ', IFNULL(motivo, '')), 255)
 WHERE id IN (3515, 3523) AND UPPER(rep_code) = 'P176228' AND vigente_desde = '2026-11-09';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
VALUES (NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'estrutura.trocas', 'esc_colaborador_config', 'ES_119',
        '{"P176228":"U2 C n3 desde 09/11"}', '{"P176228":"U3 C n2 (fica)"}', NULL);

COMMIT;

SELECT c.id, c.unidade AS u, c.turno AS t, n.numero AS nuc, DATE(c.vigente_desde) AS de, DATE(c.vigente_ate) AS ate
  FROM esc_colaborador_config c JOIN esc_nucleo n ON n.id = c.nucleo_id
 WHERE UPPER(c.rep_code) = 'P176228' AND (c.vigente_ate IS NULL OR c.vigente_ate >= '2026-11-02') ORDER BY c.vigente_desde;
SELECT v.id, p.unidade AS u, v.vaga, DATE(v.vigente_desde) AS de, DATE(v.vigente_ate) AS ate
  FROM esc_vaga_equipe v JOIN esc_posto p ON p.id = v.posto_id WHERE UPPER(v.rep_code) = 'P176228' AND v.id IN (3007, 3449, 3515, 3523);
