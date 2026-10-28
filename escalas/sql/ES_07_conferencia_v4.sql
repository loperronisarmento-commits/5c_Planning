-- =====================================================================
-- ES_07_conferencia_v4.sql
-- Conferência da escala v4 depois de gerar a semana de 02/11 na tela
-- Gerar escala (a escala antiga vai até 01/11 T6, ver ES_08).
-- Só leitura: nada aqui grava. Rode tudo na mesma conexão (a variável do
-- passo 0 vale para as consultas seguintes).
-- =====================================================================

SET NAMES utf8mb4;
USE `5c_escalas`;

-- 0. Versão mais recente da semana de 02/11
SET @p02 = (SELECT id FROM esc_plano WHERE semana_inicio = '2026-11-02' AND status IN ('RASCUNHO','PUBLICADO')
             ORDER BY versao DESC, subversao DESC LIMIT 1);

-- 1. Plano gerado (estrutura tem que ser V4; resumo do motor)
SELECT id, semana_inicio, CONCAT(versao, '.', subversao) AS versao, status, estrutura,
       JSON_EXTRACT(resumo, '$.vagas_dia')          AS vagas_dia,
       JSON_EXTRACT(resumo, '$.vagas_sem_ninguem')  AS postos_sem_ninguem,
       JSON_EXTRACT(resumo, '$.buffers_ociosos')    AS buffers_de_plantao,
       JSON_EXTRACT(resumo, '$.anomalias')          AS anomalias,
       gerado_em
  FROM esc_plano
 WHERE id = @p02;

-- 2. Células por dia e origem (VAGA_DONO / VAGA_COBERTURA / BUFFER_NUCLEO / LACUNA; 02/11 começa no T2, o T1 vem do scheduleday no CSV)
SELECT a.dia, a.origem, COUNT(*) AS celulas, COUNT(DISTINCT a.rep_code) AS pessoas
  FROM esc_plano_alocacao a
 WHERE a.plano_id = @p02
 GROUP BY a.dia, a.origem
 ORDER BY a.dia, a.origem;

-- 3. Postos sem ninguém por unidade e turno (teste deu: U1 C 17, U2 C 10, U3 A 12, U3 C 14, U3 D 5)
SELECT p.unidade, a.turno,
       COUNT(DISTINCT IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia), a.posto_id) AS posto_dias_sem_ninguem
  FROM esc_plano_alocacao a
  JOIN esc_posto p ON p.id = a.posto_id
 WHERE a.plano_id = @p02 AND a.origem = 'LACUNA'
 GROUP BY p.unidade, a.turno
 ORDER BY p.unidade, a.turno;

-- 4. Pessoa em 2 postos no mesmo turno (esperado: nenhuma linha)
SELECT x.rep_code, x.dia_turno, x.turno, COUNT(DISTINCT x.posto_id) AS postos
  FROM (SELECT a.rep_code, a.turno, a.posto_id,
               IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia) AS dia_turno
          FROM esc_plano_alocacao a
         WHERE a.plano_id = @p02 AND a.rep_code IS NOT NULL) x
 GROUP BY x.rep_code, x.dia_turno, x.turno
HAVING COUNT(DISTINCT x.posto_id) > 1;

-- 5. Pessoa fora do turno dela (esperado: nenhuma linha)
SELECT a.rep_code, c.turno AS turno_da_pessoa, a.turno AS turno_da_celula, a.dia, a.bloco, a.pagina
  FROM esc_plano_alocacao a
  JOIN esc_colaborador_config c ON c.rep_code = a.rep_code AND c.vigente_desde = '2026-11-01'
 WHERE a.plano_id = @p02 AND c.turno <> a.turno
 LIMIT 50;

-- 6. Assistente sem regular na mesma página e bloco (esperado: nenhuma linha)
SELECT a.dia, a.bloco, a.pagina
  FROM esc_plano_alocacao a
 WHERE a.plano_id = @p02 AND a.rep_code IS NOT NULL
 GROUP BY a.dia, a.bloco, a.page_id, a.pagina
HAVING SUM(a.papel = 'ASSISTENTE') > 0 AND SUM(a.papel = 'REGULAR') = 0;

-- 7. Dias em posto por pessoa (rotação: 4 ou 5; quem tem menos completou os dias como buffer de plantão, que não entra aqui)
SELECT d.dias_em_posto, COUNT(*) AS pessoas
  FROM (SELECT a.rep_code, COUNT(DISTINCT IF(a.bloco = 'T1', DATE_SUB(a.dia, INTERVAL 1 DAY), a.dia)) AS dias_em_posto
          FROM esc_plano_alocacao a
         WHERE a.plano_id = @p02 AND a.rep_code IS NOT NULL
         GROUP BY a.rep_code) d
 GROUP BY d.dias_em_posto
 ORDER BY d.dias_em_posto;

-- 8. Pessoas diferentes como regular por página e turno (teste deu média 2,8; menos é melhor)
SELECT ROUND(AVG(n), 2) AS media, MAX(n) AS maximo, COUNT(*) AS paginas_turno
  FROM (SELECT a.page_id, a.turno, COUNT(DISTINCT a.rep_code) AS n
          FROM esc_plano_alocacao a
         WHERE a.plano_id = @p02 AND a.rep_code IS NOT NULL AND a.papel = 'REGULAR'
         GROUP BY a.page_id, a.turno) t;

-- 9. Quem está no T6 de 01/11 (turno D postado, continua no T1 de 02/11 até 05:00)
--    e entra no turno A de 02/11 às 05:00 na estrutura nova: 16 h seguidas (esperado: nenhuma linha; se houver, trocar à mão)
SELECT DISTINCT UPPER(s.codigo) AS rep_code, s.colaboradorNome AS nome, a.pagina AS pagina_02nov_turno_a
  FROM `5c_schedule`.scheduleday s
  JOIN esc_plano_alocacao a ON a.rep_code = UPPER(s.codigo) COLLATE utf8mb4_unicode_ci
                           AND a.plano_id = @p02 AND a.dia = '2026-11-02' AND a.bloco = 'T2'
 WHERE s.shiftDateAndHour = '2026-11-01T21:00:00' AND s.modeloId <> 3
 ORDER BY rep_code;
