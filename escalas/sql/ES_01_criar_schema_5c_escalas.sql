-- =====================================================================
-- ES_01_criar_schema_5c_escalas.sql
-- 5C Escalas: base própria para guardar as decisões tomadas em tela,
-- as configurações e o RASCUNHO da escala (com o De→Para de cada
-- recálculo).
--
-- O QUE ESTE SCRIPT FAZ
--   Cria o schema `5c_escalas` e as tabelas abaixo. Só isso.
--   NÃO altera nenhuma tabela existente (scheduleday, Planner, Capacity,
--   headcount, CRM continuam sendo só lidos pelo sistema).
--   A escala oficial continua no site da 5C; aqui fica só o rascunho
--   que o sistema gera e os ajustes feitos na grade.
--
-- COMO RODAR
--   Navicat, conexão do servidor .11, rodar o arquivo inteiro.
--   Pode rodar de novo: tudo é CREATE ... IF NOT EXISTS e os dados
--   iniciais usam INSERT IGNORE.
--   Depois: ES_02 (carga inicial) e ES_03 (permissão do usuário do portal).
--
-- PARA DESFAZER: ES_99_desfazer.sql
--
-- GRUPOS
--   A. Cadastros e configuração ........ modelos, colaboradores, parâmetros, acesso
--   B. Decisões tomadas em tela ........ indisponibilidade, pendências do Planner,
--                                        ações de violação, checagem de saídas,
--                                        previsão do funil, sugestões, postagem
--   C. Rascunho da escala .............. plano (versões), alocação, folgas
--   D. Recálculo e De→Para ............. fotos das entradas, De→Para das entradas,
--                                        eventos de recálculo, De→Para da escala
--   E. Auditoria ....................... log
--
-- Convenções: InnoDB, utf8mb4_unicode_ci (mesma do 5c_planning, para os
-- joins com Capacity/Planner sem COLLATE). Quem fez: *_por = user_id do
-- MIS Portal (ou o rep_code, quando vem da Nora).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `5c_escalas`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `5c_escalas`;

-- =====================================================================
-- A. CADASTROS E CONFIGURAÇÃO
-- =====================================================================

-- A1. Modelos de escala (3x1, 5x2, 4x3...). O ciclo é uma string de 1 e 0
-- ('1110' = 3x1). max_seguidos é calculado pelo sistema a partir do ciclo
-- e gravado para o Monitoramento usar sem recalcular.
CREATE TABLE IF NOT EXISTS esc_modelo_escala (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome                  VARCHAR(80)  NOT NULL,
  tipo                  ENUM('CONTINUO','SEMANA') NOT NULL DEFAULT 'CONTINUO'
                        COMMENT 'CONTINUO: folga avança pelo calendário; SEMANA: dias da semana fixos',
  ciclo                 VARCHAR(31)  NOT NULL COMMENT '1 = trabalho, 0 = folga. Ex.: 1110 (3x1), 1111100 (5x2)',
  horas_dia             DECIMAL(4,2) NOT NULL DEFAULT 8.00,
  trilhas               TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'trilhas defasadas do ciclo contínuo',
  admin_a_cada          TINYINT UNSIGNED NULL COMMENT 'dia administrativo a cada N dias (Primaris); NULL = não tem',
  turno_d_conta_inicio  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'turno D (21h-05h) conta no dia em que começa',
  max_seguidos          TINYINT UNSIGNED NOT NULL COMMENT 'maior sequência de trabalho do ciclo; anomalia a partir de max_seguidos + 1',
  ativo                 TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por            INT          NULL,
  atualizado_em         DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por        INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_modelo_nome (nome),
  CONSTRAINT ck_modelo_ciclo CHECK (ciclo REGEXP '^[01]{2,31}$' AND ciclo LIKE '%0%')
) ENGINE=InnoDB COMMENT='Modelos de escala (ciclo de trabalho e folga)';

-- A2. Configuração de cada colaborador (com vigência). O turno é fixo:
-- no 3x1 o turno não roda, só a folga avança.
CREATE TABLE IF NOT EXISTS esc_colaborador_config (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  rep_code            VARCHAR(30)  NOT NULL,
  modelo_id           INT UNSIGNED NOT NULL,
  tipo                ENUM('TIME','BUFFER') NOT NULL DEFAULT 'TIME',
  turno               ENUM('A','C','D') NOT NULL COMMENT 'A = T2/T3 (05-13), C = T4/T5 (13-21), D = T6/T1 (21-05)',
  trilha              TINYINT UNSIGNED NULL COMMENT 'posição no ciclo; recalculada pela releitura do realizado',
  ancora              DATE         NULL COMMENT 'data de referência do ciclo',
  folgas_fixas        CHAR(7)      NULL COMMENT 'modelo SEMANA: seg..dom, 1 = folga (ex.: 0000011)',
  pode_assistente     TINYINT(1)   NOT NULL DEFAULT 1,
  vigente_desde       DATE         NOT NULL,
  vigente_ate         DATE         NULL,
  observacao          VARCHAR(255) NULL,
  origem              ENUM('CARGA_HEADCOUNT','TELA','RELEITURA') NOT NULL DEFAULT 'TELA',
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por          INT          NULL,
  atualizado_em       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por      INT          NULL,
  PRIMARY KEY (id),
  KEY ix_colab_rep (rep_code, vigente_desde),
  KEY ix_colab_modelo (modelo_id),
  CONSTRAINT fk_colab_modelo FOREIGN KEY (modelo_id) REFERENCES esc_modelo_escala (id),
  CONSTRAINT ck_colab_folgas CHECK (folgas_fixas IS NULL OR folgas_fixas REGEXP '^[01]{7}$'),
  CONSTRAINT ck_colab_vigencia CHECK (vigente_ate IS NULL OR vigente_ate >= vigente_desde)
) ENGINE=InnoDB COMMENT='Turno fixo, modelo e posição no ciclo de cada colaborador';

-- A3. Parâmetros (regras de formação, mérito, prazos). Chave/valor.
CREATE TABLE IF NOT EXISTS esc_parametro (
  chave           VARCHAR(60)  NOT NULL,
  valor           VARCHAR(255) NOT NULL,
  tipo            ENUM('INT','DECIMAL','BOOL','TEXTO') NOT NULL DEFAULT 'TEXTO',
  grupo           VARCHAR(30)  NOT NULL DEFAULT 'geral',
  descricao       VARCHAR(255) NULL,
  atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por  INT          NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB COMMENT='Parâmetros do 5C Escalas';

-- A4. Acesso por tela (perfil por usuário do portal). Fica aqui para não
-- mexer no 5c_wfm_mis_web.
CREATE TABLE IF NOT EXISTS esc_acesso (
  user_id         INT          NOT NULL COMMENT 'user_id do MIS Portal',
  nome            VARCHAR(120) NULL,
  perfil          ENUM('ADMIN','WFM','PLANEJAMENTO','GESTOR_TIME','LEITURA') NOT NULL DEFAULT 'LEITURA',
  times           VARCHAR(500) NULL COMMENT 'GESTOR_TIME: times que pode ver, separados por vírgula',
  ativo           TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por      INT          NULL,
  atualizado_em   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por  INT          NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB COMMENT='Quem acessa o 5C Escalas e com qual perfil';

-- =====================================================================
-- B. DECISÕES TOMADAS EM TELA
-- =====================================================================

-- B1. Pedido de indisponibilidade (site, Nora ou lançado pelo WFM).
CREATE TABLE IF NOT EXISTS esc_indisponibilidade (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rep_code            VARCHAR(30)  NOT NULL,
  tipo                ENUM('UM_DIA','VARIOS_DIAS','RECORRENTE') NOT NULL,
  canal               ENUM('NORA','SITE','WFM') NOT NULL,
  motivo_categoria    ENUM('SAUDE','ESTUDO','PESSOAL','OUTRO') NOT NULL,
  motivo_texto        VARCHAR(500) NULL,
  anexo_url           VARCHAR(500) NULL COMMENT 'atestado (foto enviada pela Nora ou upload)',
  recorrencia         JSON         NULL COMMENT 'RECORRENTE: {"dias_semana":[3],"frequencia":"semanal","ate":"2026-12-16"}',
  urgente             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'pedido com menos de 72 h',
  status              ENUM('PENDENTE','APROVADO','RECUSADO','PARCIAL','CANCELADO') NOT NULL DEFAULT 'PENDENTE',
  solicitado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  solicitado_por      VARCHAR(30)  NULL COMMENT 'rep_code (Nora/site) ou user_id (WFM)',
  decidido_em         DATETIME     NULL,
  decidido_por        INT          NULL,
  comentario_decisao  VARCHAR(500) NULL COMMENT 'obrigatório ao recusar (regra da tela)',
  PRIMARY KEY (id),
  KEY ix_indisp_rep (rep_code, solicitado_em),
  KEY ix_indisp_status (status, urgente)
) ENGINE=InnoDB COMMENT='Pedidos de indisponibilidade';

-- B2. Cada data do pedido, com decisão própria (recorrente pode ser
-- aprovado inteiro ou data a data).
CREATE TABLE IF NOT EXISTS esc_indisponibilidade_data (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  indisponibilidade_id BIGINT UNSIGNED NOT NULL,
  dia                 DATE         NOT NULL,
  turno               ENUM('A','C','D') NOT NULL,
  blocos              SET('T1','T2','T3','T4','T5','T6') NULL COMMENT 'NULL = turno todo',
  status              ENUM('PENDENTE','APROVADO','RECUSADO','CANCELADO') NOT NULL DEFAULT 'PENDENTE',
  decidido_em         DATETIME     NULL,
  decidido_por        INT          NULL,
  comentario          VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_indisp_dia (indisponibilidade_id, dia, turno),
  KEY ix_indisp_data_dia (dia, status),
  CONSTRAINT fk_indisp_data FOREIGN KEY (indisponibilidade_id) REFERENCES esc_indisponibilidade (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Datas de cada pedido de indisponibilidade';

-- B3. Mudança de formação pedida aqui, aguardando aplicação no Planner
-- (não há API de escrita). Fecha sozinha quando a leitura do
-- historicplanner bate com o pedido.
CREATE TABLE IF NOT EXISTS esc_estrutura_pendencia (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo                ENUM('ADICIONAR','MOVER','REMOVER','PASSAR_TERTIUS','PROMOVER') NOT NULL,
  rep_code            VARCHAR(30)  NULL,
  time_de             VARCHAR(100) NULL,
  party_de            VARCHAR(100) NULL,
  time_para           VARCHAR(100) NULL,
  party_para          VARCHAR(100) NULL,
  papel_para          VARCHAR(40)  NULL,
  turno_para          ENUM('A','C','D') NULL,
  vale_a_partir       DATE         NOT NULL,
  motivo              VARCHAR(255) NULL,
  instrucao           VARCHAR(500) NOT NULL COMMENT 'texto pronto para aplicar no Planner',
  status              ENUM('ABERTA','ATRASADA','DIVERGENTE','APLICADA','CANCELADA') NOT NULL DEFAULT 'ABERTA',
  conferida_em        DATETIME     NULL COMMENT 'quando a leitura do historicplanner confirmou',
  divergencia         VARCHAR(255) NULL,
  violacao_acao_id    BIGINT UNSIGNED NULL COMMENT 'ação de correção que originou a pendência',
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por          INT          NULL,
  atualizado_em       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por      INT          NULL,
  PRIMARY KEY (id),
  KEY ix_pend_status (status, vale_a_partir),
  KEY ix_pend_rep (rep_code)
) ENGINE=InnoDB COMMENT='Pendências para aplicar no Planner';

-- B4. Ação de correção registrada para uma party fora da regra.
-- A violação em si é calculada ao vivo; aqui fica a decisão.
CREATE TABLE IF NOT EXISTS esc_violacao_acao (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  time_nome           VARCHAR(100) NOT NULL,
  party               VARCHAR(100) NOT NULL,
  regra               ENUM('SEM_PRIMARIS','DOIS_PRIMARIS','SECUNDUS','SEM_PAGINA') NOT NULL,
  acao                VARCHAR(500) NOT NULL,
  responsavel         VARCHAR(120) NULL,
  prazo               DATE         NULL,
  situacao            ENUM('ABERTA','EM_CORRECAO','JUSTIFICADA','RESOLVIDA') NOT NULL DEFAULT 'ABERTA',
  justificativa       VARCHAR(500) NULL,
  justificada_ate     DATE         NULL COMMENT 'enquanto justificada, a party não bloqueia a geração',
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por          INT          NULL,
  atualizado_em       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por      INT          NULL,
  PRIMARY KEY (id),
  KEY ix_viol_party (party, regra, situacao)
) ENGINE=InnoDB COMMENT='Ações de correção das violações de formação';

-- B5. Checagem de cada saída de página detectada pelo Capacity
-- (fc_pagina_eventos). O efeito só entra na escala depois de confirmado.
CREATE TABLE IF NOT EXISTS esc_saida_checagem (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  evento_id           INT          NOT NULL COMMENT '5c_planning.fc_pagina_eventos.id',
  page_id             INT          NULL,
  pagina              VARCHAR(150) NOT NULL,
  data_evento         DATE         NOT NULL,
  efeito_reps_turno   DECIMAL(6,2) NULL,
  efeito_pessoas      DECIMAL(6,2) NULL,
  decisao             ENUM('PENDENTE','CONFIRMADA','NAO_SAIU') NOT NULL DEFAULT 'PENDENTE',
  observacao          VARCHAR(500) NULL,
  rechecar_em         DATE         NULL COMMENT 'NAO_SAIU: perguntar de novo se a página seguir parada',
  decidido_em         DATETIME     NULL,
  decidido_por        INT          NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saida_evento (evento_id),
  KEY ix_saida_decisao (decisao, data_evento)
) ENGINE=InnoDB COMMENT='Checagem das saídas de página antes de aplicar na escala';

-- B6. Previsão ajustada e destino de cada página do funil (card do CRM).
-- O CRM não muda; isto vale só para escala e Capacity.
CREATE TABLE IF NOT EXISTS esc_pagina_previsao (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  crm_card_id         INT          NOT NULL COMMENT '5c_crm.pipeline_cards.id',
  pagina              VARCHAR(150) NULL,
  perfil              ENUM('MAIN','VIP','FREE','OFTV') NULL,
  base                ENUM('ARQUETIPO','REFERENCIA','INFORMADO') NOT NULL DEFAULT 'ARQUETIPO',
  pagina_referencia   VARCHAR(150) NULL,
  fans_h_a            DECIMAL(8,2) NULL,
  fans_h_c            DECIMAL(8,2) NULL,
  fans_h_d            DECIMAL(8,2) NULL,
  probabilidade       DECIMAL(4,2) NULL,
  data_prevista       DATE         NULL,
  destino_time        VARCHAR(100) NULL,
  destino_party       VARCHAR(100) NULL,
  destino_motivo      VARCHAR(255) NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  criado_por          INT          NULL,
  atualizado_em       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por      INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_previsao_card (crm_card_id)
) ENGINE=InnoDB COMMENT='Ajuste de previsão e destino das páginas do funil';

-- B7. Sugestões (workload, cobertura, mérito, folga, estrutura).
-- Nada é aplicado sozinho: aqui fica a decisão e o motivo.
CREATE TABLE IF NOT EXISTS esc_sugestao (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id            BIGINT UNSIGNED NULL,
  recalculo_id        BIGINT UNSIGNED NULL,
  tipo                ENUM('WORKLOAD','COBERTURA','MERITO','FOLGA','ESTRUTURA') NOT NULL,
  titulo              VARCHAR(255) NOT NULL,
  descricao           VARCHAR(1000) NULL,
  efeito              VARCHAR(500) NULL,
  dados               JSON         NULL COMMENT 'o que aplicar (pessoa, dia, bloco, página)',
  status              ENUM('ABERTA','APLICADA','DESCARTADA','EXPIRADA') NOT NULL DEFAULT 'ABERTA',
  motivo_descarte     VARCHAR(500) NULL,
  decidido_em         DATETIME     NULL,
  decidido_por        INT          NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sug_status (status, tipo),
  KEY ix_sug_plano (plano_id)
) ENGINE=InnoDB COMMENT='Sugestões de troca e a decisão tomada';

-- B8. Postagem das trocas de página no Schedule Ajustment (sem API).
CREATE TABLE IF NOT EXISTS esc_troca_postagem (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  corte               VARCHAR(19)  NOT NULL COMMENT 'timestamp do Planned Move (historic_page_planner)',
  page_id             INT          NOT NULL,
  pagina              VARCHAR(150) NOT NULL,
  party_de            VARCHAR(100) NULL,
  party_para          VARCHAR(100) NOT NULL,
  semana              DATE         NOT NULL COMMENT 'segunda-feira da semana do CSV',
  date_schedule       DATE         NOT NULL,
  date_end            DATE         NOT NULL,
  csv                 MEDIUMTEXT   NOT NULL,
  csv_hash            CHAR(64)     NOT NULL,
  apagado_em          DATETIME     NULL,
  apagado_por         INT          NULL,
  postado_em          DATETIME     NULL,
  postado_por         INT          NULL,
  conferido_em        DATETIME     NULL COMMENT 'leitura do scheduleday bateu com o CSV',
  divergencias        INT          NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_troca_postagem (corte, page_id, semana)
) ENGINE=InnoDB COMMENT='Controle de apagar/postar das trocas de página';

-- B9. Cada CSV gerado ou postagem de escala (para conferir com o
-- scheduleday depois).
CREATE TABLE IF NOT EXISTS esc_exportacao (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id            BIGINT UNSIGNED NULL,
  formato             ENUM('T1T6','LINHA_POR_TURNO') NOT NULL,
  page_id             INT          NULL,
  pagina              VARCHAR(150) NULL,
  semana              DATE         NOT NULL,
  date_schedule       DATE         NULL,
  date_end            DATE         NULL,
  csv_hash            CHAR(64)     NOT NULL,
  gerado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gerado_por          INT          NULL,
  postado_em          DATETIME     NULL,
  conferido_em        DATETIME     NULL,
  divergencias        INT          NULL,
  PRIMARY KEY (id),
  KEY ix_export_plano (plano_id, semana)
) ENGINE=InnoDB COMMENT='CSVs gerados para postagem';

-- =====================================================================
-- C. RASCUNHO DA ESCALA (próprio do sistema; não é a escala oficial)
-- =====================================================================

-- C1. Cada versão do plano de uma semana: v3, v3.1 (recálculo), v4...
CREATE TABLE IF NOT EXISTS esc_plano (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  semana_inicio       DATE         NOT NULL COMMENT 'segunda-feira',
  semana_fim          DATE         NOT NULL,
  time_nome           VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'vazio = todos os times',
  versao              SMALLINT UNSIGNED NOT NULL,
  subversao           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'recálculos: 3.1, 3.2...',
  status              ENUM('RASCUNHO','PUBLICADO','SUBSTITUIDO','DESCARTADO') NOT NULL DEFAULT 'RASCUNHO',
  plano_anterior_id   BIGINT UNSIGNED NULL,
  recalculo_id        BIGINT UNSIGNED NULL COMMENT 'evento que gerou esta versão (NULL = geração manual)',
  capacity_calculo    DATE         NULL COMMENT 'data_calculo do Capacity usado',
  entradas_hash       CHAR(64)     NULL COMMENT 'hash das fotos de entrada usadas (ver esc_entrada_foto)',
  parametros          JSON         NULL,
  resumo              JSON         NULL COMMENT 'lacunas, buffers usados, anomalias programadas...',
  gerado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gerado_por          INT          NULL COMMENT 'NULL = recálculo automático',
  publicado_em        DATETIME     NULL,
  publicado_por       INT          NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_plano_versao (semana_inicio, time_nome, versao, subversao),
  KEY ix_plano_status (semana_inicio, status),
  CONSTRAINT fk_plano_anterior FOREIGN KEY (plano_anterior_id) REFERENCES esc_plano (id)
) ENGINE=InnoDB COMMENT='Versões do rascunho da escala';

-- C2. Cada célula do plano: dia × bloco × página × pessoa.
-- rep_code NULL = lacuna. travada = ajuste manual que o recálculo respeita.
CREATE TABLE IF NOT EXISTS esc_plano_alocacao (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id            BIGINT UNSIGNED NOT NULL,
  dia                 DATE         NOT NULL COMMENT 'dia do calendário do bloco',
  bloco               ENUM('T1','T2','T3','T4','T5','T6') NOT NULL,
  turno               ENUM('A','C','D') NOT NULL,
  page_id             INT          NOT NULL,
  pagina              VARCHAR(150) NOT NULL,
  rep_code            VARCHAR(30)  NULL,
  papel               ENUM('REGULAR','ASSISTENTE') NOT NULL DEFAULT 'REGULAR',
  origem              ENUM('PARTY_DONA','OUTRA_PARTY','TERTIUS','TERTIUS_ASSISTENTE','BUFFER','MANUAL','LACUNA') NOT NULL,
  travada             TINYINT(1)   NOT NULL DEFAULT 0,
  motivo              VARCHAR(255) NULL COMMENT 'o porquê mostrado no painel da grade',
  alterado_em         DATETIME     NULL,
  alterado_por        INT          NULL,
  PRIMARY KEY (id),
  KEY ix_aloc_celula (plano_id, dia, bloco, page_id),
  KEY ix_aloc_pessoa (plano_id, rep_code, dia),
  CONSTRAINT fk_aloc_plano FOREIGN KEY (plano_id) REFERENCES esc_plano (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Alocações do rascunho da escala';

-- C3. Folgas do período no plano (ciclo, indisponibilidade, admin).
CREATE TABLE IF NOT EXISTS esc_plano_folga (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plano_id            BIGINT UNSIGNED NOT NULL,
  rep_code            VARCHAR(30)  NOT NULL,
  dia                 DATE         NOT NULL,
  tipo                ENUM('CICLO','FOLGA_FIXA','INDISPONIBILIDADE','ADMIN') NOT NULL,
  ref_id              BIGINT UNSIGNED NULL COMMENT 'esc_indisponibilidade_data.id quando for indisponibilidade',
  PRIMARY KEY (id),
  UNIQUE KEY uk_folga (plano_id, rep_code, dia, tipo),
  CONSTRAINT fk_folga_plano FOREIGN KEY (plano_id) REFERENCES esc_plano (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Folgas do rascunho da escala';

-- C4. Releitura do realizado (scheduleday): onde cada pessoa está no ciclo.
CREATE TABLE IF NOT EXISTS esc_ciclo_estado (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rep_code            VARCHAR(30)  NOT NULL,
  lido_em             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ate_dia             DATE         NOT NULL COMMENT 'último dia realizado considerado',
  dias_seguidos       TINYINT UNSIGNED NOT NULL,
  ultima_folga        DATE         NULL,
  trilha_calculada    TINYINT UNSIGNED NULL,
  divergente_config   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'realizado diferente do ciclo configurado',
  PRIMARY KEY (id),
  UNIQUE KEY uk_ciclo (rep_code, ate_dia)
) ENGINE=InnoDB COMMENT='Posição real de cada pessoa no ciclo';

-- =====================================================================
-- D. RECÁLCULO E DE→PARA
-- Fluxo: a cada leitura, o sistema tira uma "foto" (hash) de cada
-- entrada. Se o hash mudou, grava o De→Para da entrada (o que mudou:
-- quem trocou de party, qual página entrou, quanto o Capacity mudou),
-- abre um evento de recálculo, gera a nova versão do plano e grava o
-- De→Para da escala (quem sai, quem entra em cada bloco).
-- Dia já escalado nunca é retirado sozinho: vira sugestão de workload.
-- =====================================================================

-- D1. Foto de cada fonte de entrada.
CREATE TABLE IF NOT EXISTS esc_entrada_foto (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fonte               ENUM('CAPACITY','ESTRUTURA','MEMBROS','PAGINAS','TROCAS_PAGINA','HEADCOUNT','INDISPONIBILIDADE','COLABORADORES','MODELOS','PARAMETROS') NOT NULL,
  lido_em             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  referencia          VARCHAR(40)  NULL COMMENT 'ex.: data_calculo do Capacity, maior id do historic',
  hash                CHAR(64)     NOT NULL,
  linhas              INT UNSIGNED NOT NULL DEFAULT 0,
  conteudo            JSON         NULL COMMENT 'versão resumida, chave -> valores, para montar o De→Para',
  PRIMARY KEY (id),
  KEY ix_foto_fonte (fonte, lido_em)
) ENGINE=InnoDB COMMENT='Fotos das entradas para detectar mudanças';

-- D2. De→Para das entradas: o que mudou entre duas fotos.
CREATE TABLE IF NOT EXISTS esc_entrada_diferenca (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  foto_de_id          BIGINT UNSIGNED NULL,
  foto_para_id        BIGINT UNSIGNED NOT NULL,
  fonte               ENUM('CAPACITY','ESTRUTURA','MEMBROS','PAGINAS','TROCAS_PAGINA','HEADCOUNT','INDISPONIBILIDADE','COLABORADORES','MODELOS','PARAMETROS') NOT NULL,
  chave               VARCHAR(150) NOT NULL COMMENT 'rep_code, página, dia|bloco|página...',
  campo               VARCHAR(60)  NOT NULL COMMENT 'party, papel, turno, reps_dedicados, status...',
  valor_de            VARCHAR(255) NULL,
  valor_para          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_entdif_foto (foto_para_id),
  KEY ix_entdif_chave (fonte, chave),
  CONSTRAINT fk_entdif_de FOREIGN KEY (foto_de_id) REFERENCES esc_entrada_foto (id),
  CONSTRAINT fk_entdif_para FOREIGN KEY (foto_para_id) REFERENCES esc_entrada_foto (id)
) ENGINE=InnoDB COMMENT='De→Para das entradas (Capacity, estrutura, membros, páginas...)';

-- D3. Evento de recálculo: o gatilho e o que ele causou.
CREATE TABLE IF NOT EXISTS esc_recalculo_evento (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gatilho             ENUM('CAPACITY','ESTRUTURA','MEMBROS','PAGINA_ENTRADA','PAGINA_SAIDA','TROCA_PAGINA','INDISPONIBILIDADE','FALTA','COLABORADOR','MODELO','PARAMETRO','MANUAL') NOT NULL,
  descricao           VARCHAR(500) NOT NULL,
  foto_id             BIGINT UNSIGNED NULL COMMENT 'foto da entrada que mudou',
  origem_ref          VARCHAR(60)  NULL COMMENT 'id na tabela de origem (evento de página, pedido, pendência...)',
  detectado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  semana_inicio       DATE         NULL,
  plano_de_id         BIGINT UNSIGNED NULL,
  plano_para_id       BIGINT UNSIGNED NULL,
  status              ENUM('DETECTADO','AGUARDANDO_CHECAGEM','RECALCULADO','SEM_EFEITO','ERRO') NOT NULL DEFAULT 'DETECTADO',
  efeito              JSON         NULL COMMENT 'reps/h por turno, buffers adicionados, sugestões criadas',
  processado_em       DATETIME     NULL,
  erro                VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY ix_recalc_status (status, detectado_em),
  KEY ix_recalc_semana (semana_inicio),
  CONSTRAINT fk_recalc_foto FOREIGN KEY (foto_id) REFERENCES esc_entrada_foto (id),
  CONSTRAINT fk_recalc_de FOREIGN KEY (plano_de_id) REFERENCES esc_plano (id),
  CONSTRAINT fk_recalc_para FOREIGN KEY (plano_para_id) REFERENCES esc_plano (id)
) ENGINE=InnoDB COMMENT='Gatilhos de recálculo e seu efeito';

-- D4. De→Para da escala: cada célula que mudou entre duas versões.
CREATE TABLE IF NOT EXISTS esc_plano_diferenca (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recalculo_id        BIGINT UNSIGNED NULL,
  plano_de_id         BIGINT UNSIGNED NOT NULL,
  plano_para_id       BIGINT UNSIGNED NOT NULL,
  dia                 DATE         NOT NULL,
  bloco               ENUM('T1','T2','T3','T4','T5','T6') NOT NULL,
  page_id             INT          NOT NULL,
  pagina              VARCHAR(150) NOT NULL,
  tipo                ENUM('ENTRA','SAI','TROCA','PAPEL','LACUNA_ABERTA','LACUNA_FECHADA') NOT NULL,
  rep_de              VARCHAR(30)  NULL,
  rep_para            VARCHAR(30)  NULL,
  papel_de            ENUM('REGULAR','ASSISTENTE') NULL,
  papel_para          ENUM('REGULAR','ASSISTENTE') NULL,
  motivo              VARCHAR(255) NULL,
  aplicado            TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = não aplicado sozinho (ex.: retirar dia escalado)',
  sugestao_id         BIGINT UNSIGNED NULL COMMENT 'sugestão criada quando não foi aplicado',
  PRIMARY KEY (id),
  KEY ix_pdif_planos (plano_de_id, plano_para_id),
  KEY ix_pdif_celula (dia, bloco, page_id),
  KEY ix_pdif_pessoa (rep_de, rep_para),
  CONSTRAINT fk_pdif_recalc FOREIGN KEY (recalculo_id) REFERENCES esc_recalculo_evento (id),
  CONSTRAINT fk_pdif_de FOREIGN KEY (plano_de_id) REFERENCES esc_plano (id),
  CONSTRAINT fk_pdif_para FOREIGN KEY (plano_para_id) REFERENCES esc_plano (id),
  CONSTRAINT fk_pdif_sug FOREIGN KEY (sugestao_id) REFERENCES esc_sugestao (id)
) ENGINE=InnoDB COMMENT='De→Para da escala entre versões do plano';

-- Ligações que dependem de tabelas criadas depois.
-- (Criadas aqui para o script poder rodar em ordem numa base vazia.)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = '5c_escalas' AND CONSTRAINT_NAME = 'fk_plano_recalc');
SET @sql := IF(@fk = 0,
  'ALTER TABLE esc_plano ADD CONSTRAINT fk_plano_recalc FOREIGN KEY (recalculo_id) REFERENCES esc_recalculo_evento (id)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = '5c_escalas' AND CONSTRAINT_NAME = 'fk_sug_plano');
SET @sql := IF(@fk = 0,
  'ALTER TABLE esc_sugestao ADD CONSTRAINT fk_sug_plano FOREIGN KEY (plano_id) REFERENCES esc_plano (id), ADD CONSTRAINT fk_sug_recalc FOREIGN KEY (recalculo_id) REFERENCES esc_recalculo_evento (id)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- =====================================================================
-- E. AUDITORIA
-- =====================================================================

CREATE TABLE IF NOT EXISTS esc_log (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quando              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id             INT          NULL,
  user_nome           VARCHAR(120) NULL,
  canal               ENUM('SITE','NORA','SISTEMA') NOT NULL DEFAULT 'SITE',
  acao                VARCHAR(60)  NOT NULL COMMENT 'ex.: modelo.salvar, indisp.aprovar, sugestao.descartar',
  entidade            VARCHAR(60)  NOT NULL,
  entidade_id         VARCHAR(60)  NULL,
  antes               JSON         NULL,
  depois              JSON         NULL,
  ip                  VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  KEY ix_log_quando (quando),
  KEY ix_log_entidade (entidade, entidade_id)
) ENGINE=InnoDB COMMENT='Quem mudou o quê, com antes e depois';

-- =====================================================================
-- DADOS INICIAIS
-- =====================================================================

INSERT IGNORE INTO esc_modelo_escala (nome, tipo, ciclo, horas_dia, trilhas, admin_a_cada, turno_d_conta_inicio, max_seguidos) VALUES
  ('3x1',                  'CONTINUO', '1110',    8.00, 4, NULL, 1, 3),
  ('Primaris 3x1 + admin', 'CONTINUO', '1110',    8.00, 4, 12,   1, 3),
  ('5x2 fixo',             'SEMANA',   '1111100', 8.00, 1, NULL, 1, 5),
  ('4x3',                  'SEMANA',   '1111000', 8.00, 1, NULL, 1, 4);

INSERT IGNORE INTO esc_parametro (chave, valor, tipo, grupo, descricao) VALUES
  ('primaris_por_party',          '1',    'INT',     'formacao',  'Primaris por party'),
  ('secundus_por_party',          '2',    'INT',     'formacao',  'Secundus por party (máximo)'),
  ('sem_primaris_bloqueia',       '1',    'BOOL',    'formacao',  'Party sem Primaris fica fora da geração'),
  ('sem_pagina_bloqueia',         '1',    'BOOL',    'formacao',  'Party sem página ativa fica fora da geração'),
  ('limite_fans_rep',             '35',   'INT',     'geracao',   'Fãs/h por rep regular'),
  ('limite_pagina_compartilhada', '28',   'INT',     'geracao',   'Até este volume a página divide rep'),
  ('presenca',                    '0.75', 'DECIMAL', 'geracao',   'Presença do 3x1 (3 de 4 dias)'),
  ('merito_alvo_destaque',        '0.70', 'DECIMAL', 'merito',    'Alvo de turnos como regular: Destaque'),
  ('merito_alvo_entrega_falta',   '0.50', 'DECIMAL', 'merito',    'Alvo: entrega, mas falta'),
  ('merito_alvo_presente_abaixo', '0.50', 'DECIMAL', 'merito',    'Alvo: presente, abaixo da meta'),
  ('merito_alvo_atencao',         '0.30', 'DECIMAL', 'merito',    'Alvo: atenção'),
  ('merito_alvo_novato',          '0.50', 'DECIMAL', 'merito',    'Alvo: novatos (menos de 30 dias)'),
  ('indisp_antecedencia_h',       '72',   'INT',     'indisp',    'Menos que isso = pedido urgente'),
  ('indisp_recorrente_max_meses', '3',    'INT',     'indisp',    'Prazo máximo do pedido recorrente'),
  ('pendencia_planner_atraso_d',  '2',    'INT',     'prazos',    'Dias até a pendência do Planner virar Atrasada'),
  ('saida_rechecar_d',            '3',    'INT',     'prazos',    'Dias para perguntar de novo quando "não saiu"'),
  ('workload_aviso_h',            '24',   'INT',     'prazos',    'Antecedência para avisar remoção de turno por workload');
