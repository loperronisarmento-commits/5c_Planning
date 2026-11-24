-- ES_221 (24/11/2026): carga inicial do Plano de Ação (item C da revisão de 24/11).
-- 1) Os dados a conferir da auditoria de 24/11 (D2 a D4) viram ações DADOS, para decidir caso a caso.
-- 2) As sugestões abertas de semanas que já passaram (3.042) fecham como descartadas: a semana passou.
--    As da semana atual e seguinte o Plano de Ação lê sozinho (lacunas e carga).
SET NAMES utf8mb4;

-- Antes
SELECT s.status, COUNT(*) AS n FROM `5c_escalas`.esc_sugestao s JOIN `5c_escalas`.esc_plano p ON p.id = s.plano_id
 WHERE s.status = 'ABERTA' GROUP BY p.semana_fim < CURDATE(), s.status;
SELECT COUNT(*) AS acoes FROM `5c_escalas`.esc_acao WHERE ref LIKE 'aud:%';

-- 1a) D3: SR em vaga de equipe de posto de outra unidade ou turno (contra "SR só na própria unidade", 1.35).
INSERT INTO `5c_escalas`.esc_acao (ref, tipo, titulo, detalhe, envolve, origem, unidade, turno, link_tela, dados, visto_em)
SELECT CONCAT('aud:D3:', v.id), 'DADOS',
       CONCAT('Vaga de equipe fora da unidade: ', UPPER(v.rep_code), ' em posto U', p.unidade, ' ', p.turno),
       CONCAT('Auditoria de 24/11 (D3): o SR é da U', c.unidade, ' ', c.turno, ' e está na vaga ', v.vaga, ' de um posto da U', p.unidade, ' ', p.turno,
              '. Fechar a vaga (vigente_ate) ou mover o SR, se a gestão quiser.'),
       UPPER(v.rep_code), 'auditoria', p.unidade, p.turno, 'colaboradores',
       JSON_OBJECT('vaga_id', v.id, 'posto_id', v.posto_id), NOW()
  FROM `5c_escalas`.esc_vaga_equipe v
  JOIN `5c_escalas`.esc_posto p ON p.id = v.posto_id
  JOIN `5c_escalas`.esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
       AND c.vigente_desde <= CURDATE() AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE())
 WHERE v.id IN (4022, 4035, 4135, 4239)
ON DUPLICATE KEY UPDATE visto_em = NOW();

-- 1b) D4: dois OS da U3 D com o núcleo do cadastro trocado em relação a esc_nucleo (desde 23/11).
INSERT INTO `5c_escalas`.esc_acao (ref, tipo, titulo, detalhe, envolve, origem, unidade, turno, link_tela, visto_em) VALUES
 ('aud:D4:N163267', 'DADOS', 'OS N163267 com o núcleo trocado no cadastro (U3 D)',
  'Auditoria de 24/11 (D4): o cadastro diz núcleo 9, mas esc_nucleo e fc_estrutura_lider dizem OS do núcleo 8 (n2). Confirmar qual está certo (troca Natasha ⇄ Nathan de 17/11) e alinhar os dois.',
  'N163267', 'auditoria', 3, 'D', 'organograma', NOW()),
 ('aud:D4:N069703', 'DADOS', 'OS N069703 com o núcleo trocado no cadastro (U3 D)',
  'Auditoria de 24/11 (D4): o cadastro diz núcleo 8, mas esc_nucleo e fc_estrutura_lider dizem OS do núcleo 9 (n3). Confirmar qual está certo e alinhar os dois.',
  'N069703', 'auditoria', 3, 'D', 'organograma', NOW())
ON DUPLICATE KEY UPDATE visto_em = NOW();

-- 1c) D2: vagas de equipe com SR de outro núcleo (efeito do ES_171), uma ação por unidade.
INSERT INTO `5c_escalas`.esc_acao (ref, tipo, titulo, detalhe, envolve, origem, unidade, link_tela, dados, visto_em)
SELECT CONCAT('aud:D2:U', x.unidade), 'DADOS',
       CONCAT(x.n, ' vagas de equipe com SR de outro núcleo na U', x.unidade),
       'Auditoria de 24/11 (D2): o ES_171 devolveu os SRs ao núcleo antigo e os postos ficaram com o dono da divisão dos OS. Decidir: refazer a divisão dos OS pelo núcleo atual ou mover as vagas. Enquanto isso, o Gerar trata como "de outro núcleo".',
       CONCAT(x.srs, ' SRs'), 'auditoria', x.unidade, 'equilibrio', JSON_OBJECT('vagas', x.n), NOW()
  FROM (SELECT p.unidade, COUNT(*) AS n, COUNT(DISTINCT UPPER(v.rep_code)) AS srs
          FROM `5c_escalas`.esc_vaga_equipe v
          JOIN `5c_escalas`.esc_posto p ON p.id = v.posto_id
          JOIN `5c_escalas`.esc_colaborador_config c ON UPPER(c.rep_code) = UPPER(v.rep_code) AND c.funcao = 'EQUITE'
               AND c.vigente_desde <= CURDATE() AND (c.vigente_ate IS NULL OR c.vigente_ate >= CURDATE())
         WHERE v.vigente_desde <= CURDATE() AND (v.vigente_ate IS NULL OR v.vigente_ate >= CURDATE())
           AND p.vigente_desde <= CURDATE() AND (p.vigente_ate IS NULL OR p.vigente_ate >= CURDATE())
           AND c.nucleo_id <> IF(v.vaga = 'DONO', p.nucleo_dono_id, p.nucleo_cobertura_id)
         GROUP BY p.unidade) x
ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), visto_em = NOW();

-- 2) Sugestões abertas de semanas que já passaram: descartadas (a semana passou).
UPDATE `5c_escalas`.esc_sugestao s JOIN `5c_escalas`.esc_plano p ON p.id = s.plano_id
   SET s.status = 'DESCARTADA', s.motivo_descarte = 'semana passou (Plano de Ação, 24/11)', s.decidido_em = NOW()
 WHERE s.status = 'ABERTA' AND p.semana_fim < CURDATE();

-- Depois
SELECT s.status, COUNT(*) AS n FROM `5c_escalas`.esc_sugestao s JOIN `5c_escalas`.esc_plano p ON p.id = s.plano_id
 WHERE s.status = 'ABERTA' GROUP BY p.semana_fim < CURDATE(), s.status;
SELECT ref, titulo FROM `5c_escalas`.esc_acao WHERE ref LIKE 'aud:%' ORDER BY ref;
