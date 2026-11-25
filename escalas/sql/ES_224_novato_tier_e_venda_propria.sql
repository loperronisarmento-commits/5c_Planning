SET NAMES utf8mb4;
-- =====================================================================
-- ES_224 (24/11/2026, 5C Escalas 1.39.0): parâmetros das correções da escala da U1.
--   Rampa do new hire (SR nível 0 do cargo), pela semana na operação (desde o primeiro cadastro de SR):
--     1ª semana assistente ou buffer (cobrindo página, só até o Tier F); 2ª regular até o Tier F; 3ª e 4ª
--     regular até o Tier E (assistente ou buffer com preferência da 1ª à 4ª); depois, regular até o Tier E.
--     v4_novato_teto_f (16000): teto do Tier F em 5c_crm.tiers; v4_novato_receita_max (32000): teto do Tier E.
--   v4_rateio_venda_propria (1): o realizado da página vai para o SR pela venda própria (venda direta e tip;
--     assinatura, live tip, live, recurring e post não são do SR), no KPI e na meta na página; 0 volta ao
--     rateio pelas mensagens.
-- O código já usa esses valores por padrão; o script só os põe na tela de Parâmetros.
-- =====================================================================
USE `5c_escalas`;

SELECT 'antes' AS momento, chave, valor FROM esc_parametro WHERE chave IN ('v4_novato_teto_f', 'v4_novato_receita_max', 'v4_rateio_venda_propria');
INSERT INTO esc_parametro (chave, valor, tipo, grupo, descricao)
SELECT x.chave, x.valor, x.tipo, t.grupo, x.descricao
  FROM (SELECT 'v4_novato_teto_f' AS chave, '16000' AS valor, 'DECIMAL' AS tipo,
               'Rampa do new hire: na 1ª e na 2ª semana na operação, regular só em página com faturamento de 30 dias até este valor (US$; 16 mil = teto do Tier F). 0 = sem limite (1.39.0, ES_224)' AS descricao
        UNION ALL SELECT 'v4_novato_receita_max', '32000', 'DECIMAL',
               'Rampa do new hire: da 3ª semana na operação em diante, regular só em página com faturamento de 30 dias até este valor (US$; 32 mil = teto do Tier E). 0 = sem limite (1.39.0, ES_224)'
        UNION ALL SELECT 'v4_rateio_venda_propria', '1', 'BOOL',
               'Realizado da página para o SR pela venda própria (venda direta e tip) no KPI e na meta na página; 0 = pela fatia de mensagens (1.39.0, ES_224)') x
  JOIN esc_parametro t ON t.chave = 'v4_so_propria_unidade'
 WHERE NOT EXISTS (SELECT 1 FROM esc_parametro p WHERE p.chave = x.chave);
SELECT 'depois' AS momento, chave, valor, tipo, grupo FROM esc_parametro WHERE chave IN ('v4_novato_teto_f', 'v4_novato_receita_max', 'v4_rateio_venda_propria');
