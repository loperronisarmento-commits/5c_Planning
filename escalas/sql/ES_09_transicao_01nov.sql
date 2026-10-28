-- =====================================================================
-- ES_09_transicao_01nov.sql
-- Volta a virada para 01/11 às 05:00 (T2 em diante) com 01/11 como dia de
-- transição (revisão de 28/10, substitui o ES_08):
--   - v4_virada        → 2026-11-01 05:00;
--   - v4_dia_transicao → 2026-11-01: as páginas de 01/11 são cobertas por
--     quem já está escalado nesse dia no scheduleday, no turno em que está.
--     Só entram SRs (Sales Representatives) da estrutura nova: quem vai
--     virar OS, OM ou OUM fica de fora.
-- Depois: gerar a semana de 26/10 (só 01/11) e a de 02/11, nessa ordem.
-- O CSV da semana de 26/10 repete o que já está postado antes de 01/11 05:00.
-- Pode rodar de novo.
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

UPDATE esc_parametro
   SET valor = '2026-11-01 05:00',
       descricao = 'Início da estrutura nova (T2 de 01/11 em diante)'
 WHERE chave = 'v4_virada';

INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
VALUES ('v4_dia_transicao', '2026-11-01', 'TEXTO', 'v4', 'Dia montado com quem já está escalado no scheduleday, só SRs (vazio = sem transição)')
    AS n ON DUPLICATE KEY UPDATE valor = n.valor, descricao = n.descricao;

-- Conferência
SELECT chave, valor, descricao FROM esc_parametro WHERE chave IN ('v4_virada', 'v4_dia_transicao', 'v4_rotacao_ancora');

-- Quem está escalado em 01/11 (T2 a T6) e entra ou não na transição
SELECT CASE WHEN c.rep_code IS NOT NULL THEN 'SR: entra na transição'
            WHEN n.id IS NOT NULL THEN 'vai virar OS: fica de fora'
            WHEN l.id IS NOT NULL THEN 'vai virar OM/OUM: fica de fora'
            ELSE 'fora da estrutura nova: fica de fora' END AS situacao,
       COUNT(DISTINCT s.codigo) AS pessoas
  FROM `5c_schedule`.scheduleday s
  LEFT JOIN esc_colaborador_config c ON c.rep_code = UPPER(s.codigo) COLLATE utf8mb4_unicode_ci
                                    AND c.vigente_desde = '2026-11-01' AND c.funcao = 'EQUITE'
  LEFT JOIN esc_nucleo n ON n.decurio_code = UPPER(s.codigo) COLLATE utf8mb4_unicode_ci AND n.vigente_desde = '2026-11-01'
  LEFT JOIN esc_lideranca l ON l.rep_code = UPPER(s.codigo) COLLATE utf8mb4_unicode_ci AND l.vigente_ate IS NULL
 WHERE s.shiftDateAndHour >= '2026-11-01T05:00:00' AND s.shiftDateAndHour < '2026-11-02T01:00:00' AND s.modeloId <> 3
 GROUP BY situacao;
