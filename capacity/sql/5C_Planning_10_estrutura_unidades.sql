-- =====================================================================
-- 5C | Forecast de WFM — 10. Estrutura em 3 unidades (vale a partir de 01/11/2026)
-- Schema: 5c_planning        Versão 1.0 — 27/10/2026
--
-- Leva para o banco a conta de capacity da estrutura nova (posts.py de 27/10):
--   unidade → carteira (C1/C2) → página, postos por página e turno,
--   necessidade = titulares (postos × 1,5, 5x2 rotativo de 21 dias) + buffer por unidade e turno
--   (absenteísmo + falha administrativa + reforço de pico), e quem cobre (220 reps).
--
-- Só ACRESCENTA objetos. Nada do que já existe muda: fc_escala_diaria,
-- fc_buffer_diario, fc_escala_pagina, fc_impacto_paginas e as visões vw_fc_1..6
-- continuam iguais (o Planning tool e o 5C Escalas leem essas tabelas).
--
-- Ordem: 10 (este) → 11 (procedimento) → 12 (carga da estrutura de 01/11) → 13 (validação).
-- No Navicat: abrir em "Nova consulta" e executar tudo.
-- =====================================================================
SET NAMES utf8mb4;
USE `5c_planning`;

-- ---------------------------------------------------------------------
-- 1. Parâmetros novos (os de antes continuam valendo: limite_padrao 35,
--    limite_compartilhada 28, limite_skylar 40, skylar_minimo 4,
--    limite_sla80 20, max_paginas_rep 3, fans_sem_dado 10)
-- ---------------------------------------------------------------------
-- Fator decidido em 27/10: 5x2 rotativo em ciclo de 21 dias (5x2, 5x2, 4x3) por núcleo; cada pessoa
-- trabalha 14 de 21 dias → 21 ÷ 14 = 1,5 pessoa por posto. O quadro fixo é enxuto (só isso);
-- toda falta é coberta pelos buffers.
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('fator_pessoas_posto',      1.50, 'Pessoas por posto: 5x2 rotativo em ciclo de 21 dias, 14 dias trabalhados (21 ÷ 14). Quadro fixo enxuto')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = VALUES(descricao);

-- Buffer por unidade e turno (decidido em 27/10): cobre todas as faltas e o reforço de pico.
-- O buffer é contado no total de cada turno (as 3 unidades juntas) e distribuído de forma desigual,
-- com um mínimo por núcleo (decisão de 28/10: evita o custo de arredondar núcleo a núcleo):
--   presentes por dia (unidade) = (postos × taxa de falta + pico) ÷ (1 − taxa de falta)
--   buffer do turno = ↑ Σ presentes × núcleos ÷ (núcleos − núcleos de folga)   (× 3 ÷ 2: o buffer segue a folga do núcleo)
--   cada unidade recebe a sua parte proporcional aos presentes, nunca menos que buffer_minimo_nucleo × núcleos;
--   dentro da unidade, o Planning tool divide entre os núcleos (desigual, respeitando o mínimo)
--   taxa de falta = absenteísmo + peso_af_buffer × falha administrativa (abs_adh_af, por turno)
--   pico = percentil_pico dos dias: no pior horário do dia, soma dos fãs que passam da capacidade
--          dos postos de cada página (com tolerância até teto_pico_fans_rep por rep) ÷ limite_padrao
INSERT INTO fc_parametros (chave, valor, descricao) VALUES
 ('nucleos_por_turno',        3,    'Núcleos (equipes de OS) por unidade e turno'),
 ('buffer_minimo_nucleo',     2,    'Buffer mínimo de cada núcleo; acima disso a distribuição entre núcleos é livre (contagem global por turno)'),
 ('nucleos_folga_dia',        1,    'Núcleos de folga por dia no 5x2 rotativo de 21 dias (2 de 3 trabalham)'),
 ('janela_buffer_dias',       56,   'Dias de histórico de absenteísmo e falha administrativa (abs_adh_af) para o buffer'),
 ('peso_af_buffer',           1.00, 'Peso da falha administrativa no buffer: AF deixa o posto descoberto (trabalhou fora da escala ou em outro turno)'),
 ('janela_pico_dias',         28,   'Dias de histórico (fc_fans_pagina_hora) para medir os picos acima dos postos'),
 ('percentil_pico',           0.50, 'Pico coberto pelo buffer: 0,50 = pico de um dia típico (mediana dos picos diários)'),
 ('teto_pico_fans_rep',       40,   'Só conta como pico o que passa de 40 fãs/h por rep (teto individual da Política); planejamento é 35'),
 ('teto_apoio',               40,   'Acima disto (fãs/h no turno) o titular fica com 35 e o excedente vira apoio (estudo Sweet Spot: gatilho de +1)'),
 ('assistente_fixo_minimo',   2,    'Postos mínimos por turno nas páginas com assistente fixo (titular + 1)'),
 ('desconto_mesma_modelo',    0.10, 'Perda de capacidade por página extra da mesma modelo no agrupamento: 35 × (1 − 10% × (n−1))'),
 ('desconto_modelo_diferente',0.20, 'Perda de capacidade por página extra de modelo diferente: 35 × (1 − 20% × (n−1))'),
 ('janela_postos_dias',       14,   'Dias de histórico (fc_fans_pagina_hora) para a carga de cada página nos postos'),
 ('carga_minima_posto',       0.50, 'Página com menos fãs/h que isto no turno não ocupa posto (ex.: OFTV parada)')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao);

-- ---------------------------------------------------------------------
-- 2. Cadastro da estrutura (preenchido pelo script 12; depois, manutenção manual)
-- ---------------------------------------------------------------------
-- 2.1 Carteiras: 2 por unidade (C1/C2)
CREATE TABLE IF NOT EXISTS fc_carteira (
  carteira     VARCHAR(8)   NOT NULL PRIMARY KEY COMMENT 'U1C1 … U3C2',
  unidade      TINYINT      NOT NULL COMMENT '1 a 3',
  codigo       CHAR(2)      NOT NULL COMMENT 'C1 ou C2',
  nome         VARCHAR(80)  NOT NULL COMMENT 'ex.: U1 · C1',
  maior_pagina VARCHAR(150) NULL,
  ativo        TINYINT      NOT NULL DEFAULT 1,
  atualizado_em TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_unidade_codigo (unidade, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 Página → carteira, com a regra de atendimento. Mudou de carteira: feche a linha
--     (vigente_ate) e abra outra; o cálculo usa a linha vigente na data.
CREATE TABLE IF NOT EXISTS fc_pagina_carteira (
  pagina          VARCHAR(150) NOT NULL COMMENT 'igual a 5c_pages.pages.name e ao CREATOR do msgdashboard',
  vigente_desde   DATE         NOT NULL,
  vigente_ate     DATE         NULL COMMENT 'NULL = vigente; o dia informado já não vale',
  carteira        VARCHAR(8)   NOT NULL,
  modelo          VARCHAR(150) NOT NULL COMMENT 'criadora; páginas da mesma modelo agrupam com menos perda (10%)',
  regra           ENUM('PADRAO','SKYLAR','SLA80','ASSISTENTE_FIXO','EXCLUSIVA') NOT NULL DEFAULT 'PADRAO'
                  COMMENT 'SKYLAR: limite 40, mín. 4 · SLA80: limite 20, nunca agrupa · ASSISTENTE_FIXO: mín. 2 por turno · EXCLUSIVA: rep próprio, nunca agrupa',
  ordem           SMALLINT     NOT NULL DEFAULT 999 COMMENT 'desempate do agrupamento (ordem da carteira)',
  fas_h_estimado  DECIMAL(6,2) NULL COMMENT 'fãs/h usados quando a página não tem chat na janela (receita ÷ 720 × k do tier)',
  obs             VARCHAR(255) NULL,
  atualizado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (pagina, vigente_desde),
  KEY ix_carteira (carteira, vigente_desde),
  CONSTRAINT fk_pc_carteira FOREIGN KEY (carteira) REFERENCES fc_carteira (carteira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 Liderança: OUM (unidade), OM (unidade × turno) e OS (3 por OM, rodízio 5x2)
CREATE TABLE IF NOT EXISTS fc_estrutura_lider (
  nome           VARCHAR(120) NOT NULL,
  vigente_desde  DATE         NOT NULL,
  vigente_ate    DATE         NULL,
  nivel          ENUM('OUM','OM','OS') NOT NULL,
  unidade        TINYINT      NOT NULL,
  turno          CHAR(1)      NULL COMMENT 'A, C ou D (OUM sem turno)',
  om             VARCHAR(120) NULL COMMENT 'OM do OS',
  PRIMARY KEY (nome, vigente_desde),
  KEY ix_unidade (unidade, turno, nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.4 Reps na estrutura (quem cobre cada unidade e turno). Foto de 27/10 no script 12;
--     quando o Planning tool tiver o cadastro próprio, esta tabela passa a ser alimentada por ele.
CREATE TABLE IF NOT EXISTS fc_estrutura_rep (
  code           VARCHAR(30)  NOT NULL COMMENT '= 5c_headcount.headcount.code',
  vigente_desde  DATE         NOT NULL,
  vigente_ate    DATE         NULL,
  unidade        TINYINT      NOT NULL,
  carteira       VARCHAR(8)   NOT NULL,
  turno          CHAR(1)      NOT NULL COMMENT 'A = T2/T3 · C = T4/T5 · D = T6/T1',
  nivel          TINYINT      NOT NULL COMMENT '0 a 5 (Lv0 3% … Lv5 4,5%)',
  os             VARCHAR(120) NULL COMMENT 'supervisor de referência',
  ficticio       TINYINT      NOT NULL DEFAULT 0 COMMENT '1 = vaga a contratar (não entra no "tem")',
  PRIMARY KEY (code, vigente_desde),
  KEY ix_unidade_turno (unidade, turno),
  CONSTRAINT fk_er_carteira FOREIGN KEY (carteira) REFERENCES fc_carteira (carteira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Resultados (recalculados todo dia pelo sp_fc_postos; histórico por data_calculo)
-- ---------------------------------------------------------------------
-- 3.1 Carga de cada página no turno (auditoria do cálculo)
CREATE TABLE IF NOT EXISTS fc_pagina_carga (
  data_calculo  DATE         NOT NULL,
  pagina        VARCHAR(150) NOT NULL,
  turno         CHAR(1)      NOT NULL,
  carteira      VARCHAR(8)   NOT NULL,
  modelo        VARCHAR(150) NOT NULL,
  regra         VARCHAR(16)  NOT NULL,
  ordem         SMALLINT     NOT NULL,
  fans_h        DECIMAL(10,4) NOT NULL COMMENT 'maior média de bloco de 4 h dos 2 blocos do turno',
  origem        ENUM('CHAT','ESTIMADA','SEM_DADO') NOT NULL,
  ocupa_posto   TINYINT      NOT NULL COMMENT '0 = abaixo de carga_minima_posto',
  PRIMARY KEY (data_calculo, pagina, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 Postos por carteira e turno
CREATE TABLE IF NOT EXISTS fc_postos (
  data_calculo     DATE         NOT NULL,
  carteira         VARCHAR(8)   NOT NULL,
  turno            CHAR(1)      NOT NULL,
  posto            SMALLINT     NOT NULL COMMENT 'sequência dentro da carteira e turno',
  unidade          TINYINT      NOT NULL,
  tipo             ENUM('titular','assistente','double','apoio') NOT NULL
                   COMMENT 'titular = 1 página · double = 2–3 páginas agrupadas · apoio = excedente de página grande + pequenas · assistente = 2º rep da mesma página',
  pagina_principal VARCHAR(150) NOT NULL,
  n_paginas        TINYINT      NOT NULL,
  carga            DECIMAL(6,1) NOT NULL COMMENT 'fãs/h do posto',
  capacidade       DECIMAL(5,1) NOT NULL,
  no_teto          TINYINT      NOT NULL DEFAULT 0 COMMENT 'titular sozinho entre 35 e 40 fãs/h',
  exclusiva        TINYINT      NOT NULL DEFAULT 0,
  excedente        DECIMAL(6,1) NULL COMMENT 'fãs/h acima de 35 que foram para apoio/assistente',
  PRIMARY KEY (data_calculo, carteira, turno, posto),
  KEY ix_unidade (data_calculo, unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 Páginas de cada posto (fração = 1 ÷ páginas do posto; soma das frações = postos)
CREATE TABLE IF NOT EXISTS fc_postos_pagina (
  data_calculo  DATE         NOT NULL,
  carteira      VARCHAR(8)   NOT NULL,
  turno         CHAR(1)      NOT NULL,
  posto         SMALLINT     NOT NULL,
  pagina        VARCHAR(150) NOT NULL,
  fans_h        DECIMAL(6,1) NOT NULL,
  apoio         TINYINT      NOT NULL DEFAULT 0 COMMENT '1 = excedente de página grande',
  fracao        DECIMAL(6,4) NOT NULL,
  PRIMARY KEY (data_calculo, carteira, turno, posto, pagina),
  KEY ix_pagina (data_calculo, pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.4 Necessidade por unidade e turno
CREATE TABLE IF NOT EXISTS fc_necessidade_estrutura (
  data_calculo  DATE     NOT NULL,
  unidade       TINYINT  NOT NULL,
  turno         CHAR(1)  NOT NULL,
  postos        SMALLINT NOT NULL,
  titulares     SMALLINT NOT NULL COMMENT 'arredondar para cima (postos × fator_pessoas_posto): quadro fixo enxuto',
  taxa_abs      DECIMAL(6,4) NOT NULL COMMENT 'absenteísmo do turno (janela_buffer_dias)',
  taxa_af       DECIMAL(6,4) NOT NULL COMMENT 'falha administrativa do turno (janela_buffer_dias)',
  faltas_dia    DECIMAL(6,2) NOT NULL COMMENT 'postos × (taxa_abs + peso_af_buffer × taxa_af)',
  pico_dia      DECIMAL(6,2) NOT NULL COMMENT 'reps a mais no pior horário de um dia (percentil_pico)',
  presentes_dia DECIMAL(8,4) NOT NULL COMMENT 'buffers presentes por dia: (faltas_dia + pico_dia) ÷ (1 − taxa de falta)',
  buffer_minimo SMALLINT NOT NULL COMMENT 'buffer_minimo_nucleo × nucleos_por_turno',
  buffer        SMALLINT NOT NULL COMMENT 'parte da unidade no buffer do turno (fc_buffer_turno), nunca abaixo do mínimo',
  precisa       SMALLINT NOT NULL,
  tem           SMALLINT NOT NULL COMMENT 'reps reais vigentes em fc_estrutura_rep',
  saldo         SMALLINT NOT NULL COMMENT 'tem − precisa (negativo = falta)',
  PRIMARY KEY (data_calculo, unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.5 Buffer de cada turno, contado no total das 3 unidades
CREATE TABLE IF NOT EXISTS fc_buffer_turno (
  data_calculo   DATE         NOT NULL,
  turno          CHAR(1)      NOT NULL,
  presentes_dia  DECIMAL(8,4) NOT NULL COMMENT 'soma das unidades',
  buffer_global  SMALLINT     NOT NULL COMMENT '↑ presentes × núcleos ÷ núcleos trabalhando',
  buffer_minimo  SMALLINT     NOT NULL COMMENT 'mínimo somado (núcleos × buffer_minimo_nucleo)',
  buffer         SMALLINT     NOT NULL COMMENT 'o maior entre o global e o mínimo: soma das unidades',
  PRIMARY KEY (data_calculo, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6 Necessidade por carteira e turno (base dos 3 OS do turno)
CREATE TABLE IF NOT EXISTS fc_necessidade_carteira (
  data_calculo  DATE       NOT NULL,
  carteira      VARCHAR(8) NOT NULL,
  turno         CHAR(1)    NOT NULL,
  postos        SMALLINT   NOT NULL,
  titulares     SMALLINT   NOT NULL,
  tem           SMALLINT   NOT NULL,
  PRIMARY KEY (data_calculo, carteira, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Tabelas de trabalho do agrupamento (uso interno do sp_fc_postos)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fc_wrk_pc_item (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  pagina  VARCHAR(150) NOT NULL,
  modelo  VARCHAR(150) NOT NULL,
  f       DECIMAL(6,1) NOT NULL,
  apoio   TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_pc_bin (
  bin           INT          NOT NULL PRIMARY KEY,
  n             TINYINT      NOT NULL,
  soma          DECIMAL(7,1) NOT NULL,
  apoio         TINYINT      NOT NULL,
  modelo_unico  VARCHAR(150) NULL COMMENT 'NULL = modelos diferentes no grupo',
  primeira      VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_buf_taxa (
  turno     CHAR(1)      NOT NULL PRIMARY KEY,
  taxa_abs  DECIMAL(6,4) NOT NULL,
  taxa_af   DECIMAL(6,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_pico_dia (
  unidade  TINYINT      NOT NULL,
  turno    CHAR(1)      NOT NULL,
  dia      DATE         NOT NULL,
  mx       DECIMAL(8,4) NOT NULL COMMENT 'reps a mais no pior horário do dia',
  PRIMARY KEY (unidade, turno, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_pico (
  unidade  TINYINT      NOT NULL,
  turno    CHAR(1)      NOT NULL,
  pico     DECIMAL(8,4) NOT NULL,
  dias     SMALLINT     NOT NULL,
  PRIMARY KEY (unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_buffer_aloc (
  unidade  TINYINT  NOT NULL,
  turno    CHAR(1)  NOT NULL,
  buffer   SMALLINT NOT NULL,
  PRIMARY KEY (unidade, turno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fc_wrk_pc_bin_item (
  bin     INT          NOT NULL,
  seq     TINYINT      NOT NULL,
  pagina  VARCHAR(150) NOT NULL,
  modelo  VARCHAR(150) NOT NULL,
  f       DECIMAL(6,1) NOT NULL,
  apoio   TINYINT      NOT NULL,
  PRIMARY KEY (bin, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Visões (sempre o cálculo mais recente)
-- ---------------------------------------------------------------------
-- (7) Necessidade da estrutura por unidade e turno
CREATE OR REPLACE VIEW vw_fc_7_necessidade_estrutura AS
SELECT CONCAT('U', n.unidade) AS `Unidade`, n.turno AS `Turno`,
       n.postos AS `Postos`, n.titulares AS `Titulares`,
       ROUND(n.taxa_abs * 100, 1) AS `Absenteísmo %`, ROUND(n.taxa_af * 100, 1) AS `Falha adm. %`,
       n.faltas_dia AS `Faltas por dia`, n.pico_dia AS `Pico por dia`,
       ROUND(n.presentes_dia, 1) AS `Buffers presentes por dia`, n.buffer_minimo AS `Buffer mínimo`, n.buffer AS `Buffer`,
       n.precisa AS `Precisa`, n.tem AS `Tem`, n.saldo AS `Saldo`,
       n.data_calculo AS `Data do cálculo`
FROM fc_necessidade_estrutura n
WHERE n.data_calculo = (SELECT MAX(data_calculo) FROM fc_necessidade_estrutura);

-- (8) Postos por página e turno (soma das frações)
CREATE OR REPLACE VIEW vw_fc_8_postos_pagina AS
SELECT pp.pagina AS `Página`, c.nome AS `Carteira`, pp.turno AS `Turno`,
       ROUND(SUM(pp.fracao), 2) AS `Postos`,
       ROUND(MAX(pc.fans_h), 1) AS `Fãs/h`,
       GROUP_CONCAT(DISTINCT p.tipo ORDER BY p.tipo SEPARATOR ', ') AS `Como é atendida`,
       MAX(pc.origem) AS `Origem da carga`,
       pp.data_calculo AS `Data do cálculo`
FROM fc_postos_pagina pp
JOIN fc_postos p ON p.data_calculo = pp.data_calculo AND p.carteira = pp.carteira AND p.turno = pp.turno AND p.posto = pp.posto
JOIN fc_carteira c ON c.carteira = pp.carteira
LEFT JOIN fc_pagina_carga pc ON pc.data_calculo = pp.data_calculo AND pc.pagina = pp.pagina AND pc.turno = pp.turno
WHERE pp.data_calculo = (SELECT MAX(data_calculo) FROM fc_postos_pagina)
GROUP BY pp.data_calculo, pp.pagina, c.nome, pp.turno;

-- (9) Resumo por carteira (postos, titulares e reps de cada turno)
CREATE OR REPLACE VIEW vw_fc_9_carteiras AS
SELECT c.nome AS `Carteira`, c.maior_pagina AS `Maior página`,
       SUM(IF(n.turno='A', n.postos, 0)) AS `Postos A`, SUM(IF(n.turno='C', n.postos, 0)) AS `Postos C`, SUM(IF(n.turno='D', n.postos, 0)) AS `Postos D`,
       SUM(IF(n.turno='A', n.titulares, 0)) AS `Titulares A`, SUM(IF(n.turno='C', n.titulares, 0)) AS `Titulares C`, SUM(IF(n.turno='D', n.titulares, 0)) AS `Titulares D`,
       SUM(IF(n.turno='A', n.tem, 0)) AS `Tem A`, SUM(IF(n.turno='C', n.tem, 0)) AS `Tem C`, SUM(IF(n.turno='D', n.tem, 0)) AS `Tem D`,
       MAX(n.data_calculo) AS `Data do cálculo`
FROM fc_necessidade_carteira n
JOIN fc_carteira c ON c.carteira = n.carteira
WHERE n.data_calculo = (SELECT MAX(data_calculo) FROM fc_necessidade_carteira)
GROUP BY c.carteira, c.nome, c.maior_pagina;

-- (10) Manutenção: páginas ativas sem carteira e páginas com carteira que saíram do cadastro
CREATE OR REPLACE VIEW vw_fc_10_paginas_fora_da_estrutura AS
SELECT 'Ativa sem carteira' AS `Situação`, s.pagina AS `Página`, NULL AS `Carteira`, s.dia AS `Foto do cadastro`
FROM fc_pagina_status_diario s
WHERE s.dia = (SELECT MAX(dia) FROM fc_pagina_status_diario) AND s.ativo = 1
  AND NOT EXISTS (SELECT 1 FROM fc_pagina_carteira pc
                  WHERE pc.pagina = s.pagina AND pc.vigente_ate IS NULL)
UNION ALL
SELECT 'Na carteira, mas inativa', pc.pagina, pc.carteira, s.dia
FROM fc_pagina_carteira pc
JOIN fc_pagina_status_diario s ON s.pagina = pc.pagina
     AND s.dia = (SELECT MAX(dia) FROM fc_pagina_status_diario)
WHERE pc.vigente_ate IS NULL AND s.ativo = 0;
