SET NAMES utf8mb4;
-- =====================================================================
-- ES_78_haven_free_sem_assistente_v30_2.sql  (02/11, aplicado pelo Claude via mysql.exe)
-- Pedido do usuário: a Haven Free não precisa de assistente na semana de 02/11.
-- Causa: a página entrou duas vezes na geração de 01/11 ("Haven Free" e "Haven  Free", com dois
-- espaços; corrigido no ES_73 e na 1.18.9). Os dois nomes são a mesma página no site (page 486). Cada
-- um ganhou uma pessoa por turno, e no CSV a do nome com dois espaços virou assistente (#), além
-- de estar em outras páginas no mesmo bloco.
-- A v30.2 é a cópia da v30.1 (plano 93) sem as células do nome com dois espaços a partir de 03/11
-- (o que já passou fica como está). Fica em RASCUNHO para publicar na Grade e repostar só a Haven
-- Free no site (Delete Range de 03 a 08/11 e o CSV da página).
-- =====================================================================

USE `5c_escalas`;

INSERT INTO esc_plano (semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, subversao, status, plano_anterior_id,
       recalculo_id, capacity_calculo, entradas_hash, parametros, resumo, gerado_por)
SELECT semana_inicio, semana_fim, time_nome, estrutura, unidade, versao, 2, 'RASCUNHO', id,
       NULL, capacity_calculo, entradas_hash, JSON_SET(parametros, '$.ajuste', 'ES_78'), resumo, NULL
  FROM esc_plano WHERE id = 93 AND versao = 30 AND subversao = 1
   AND NOT EXISTS (SELECT 1 FROM esc_plano WHERE semana_inicio = '2026-11-02' AND versao = 30 AND subversao = 2);
SET @p = (SELECT id FROM esc_plano WHERE semana_inicio = '2026-11-02' AND versao = 30 AND subversao = 2);

INSERT INTO esc_plano_alocacao (plano_id, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por)
SELECT @p, dia, bloco, turno, page_id, pagina, posto_id, vaga, rep_code, papel, origem, travada, motivo, alterado_em, alterado_por
  FROM esc_plano_alocacao
 WHERE plano_id = 93 AND NOT EXISTS (SELECT 1 FROM esc_plano_alocacao x WHERE x.plano_id = @p)
   AND NOT (CHAR_LENGTH(pagina) = 11 AND REPLACE(pagina, ' ', '') = 'HavenFree' AND dia >= '2026-11-03')
 ORDER BY id;
INSERT INTO esc_plano_folga (plano_id, rep_code, dia, tipo, ref_id)
SELECT @p, rep_code, dia, tipo, ref_id FROM esc_plano_folga
 WHERE plano_id = 93 AND NOT EXISTS (SELECT 1 FROM esc_plano_folga x WHERE x.plano_id = @p) ORDER BY id;

-- Trava as células da Haven Free (um "Gerar" desta semana não põe o assistente de volta).
UPDATE esc_plano_alocacao SET travada = 1, motivo = 'ajuste manual (ES_78): Haven Free sem assistente'
 WHERE plano_id = @p AND page_id = 486 AND dia >= '2026-11-03';

INSERT INTO esc_log (quando, user_id, user_nome, canal, acao, entidade, entidade_id, antes, depois, ip)
SELECT NOW(), NULL, 'Claude (pedido do Planejamento)', 'SISTEMA', 'plano.ajuste', 'esc_plano', @p, '{"plano":93,"versao":"v30.1"}',
       '{"versao":"v30.2","haven_free":"sem o assistente do nome duplicado (Haven  Free) de 03 a 09/11"}', NULL
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM esc_log WHERE acao = 'plano.ajuste' AND entidade_id = @p);

-- Conferência: Haven Free na v30.1 e na v30.2 a partir de 03/11
SELECT p.versao, p.subversao, p.status, COUNT(a.id) AS celulas_haven,
       (SELECT COUNT(*) FROM esc_plano_alocacao t WHERE t.plano_id = p.id) AS total
  FROM esc_plano p LEFT JOIN esc_plano_alocacao a ON a.plano_id = p.id AND a.page_id = 486 AND a.dia >= '2026-11-03' AND a.rep_code IS NOT NULL
 WHERE p.id IN (93, @p) GROUP BY p.id, p.versao, p.subversao, p.status;
