<?php
/**
 * etl_auto/config.php — configuração da execução automática das ETLs.
 * Rodado pelo Agendador de Tarefas do Windows a cada 15 min (ver README.md).
 */
return [
    // Liga/desliga geral (também dá para pausar pela tela etl_auto/index.php)
    'enabled' => true,

    // Fuso usado para "hoje" (escala) e para a hora da repescagem
    'timezone' => 'America/Sao_Paulo',

    // Janela das vendas do Infloww: de N minutos atrás até a hora da execução.
    // 60 min cobre o atraso típico da API do Infloww (até ~1 h para uma venda
    // aparecer); como roda a cada 15 min, cada venda é vista ~4 vezes.
    'window_minutes' => 60,

    // Repescagem diária: na primeira execução bem-sucedida dentro desta hora,
    // as vendas do Infloww são buscadas nas últimas N horas (a API do Infloww
    // pode demorar até ~1 h para mostrar uma venda — esta rodada pega o que
    // chegou com atraso maior que a janela, e status alterados depois).
    'catchup_hour'  => 3,
    'catchup_hours' => 48,

    // Tarefa do Agendador de Tarefas (criada pelo instalar_tarefa.bat) e intervalo.
    // Usados pela tela: contagem regressiva e botão "Rodar agora".
    'task_name'        => 'MIS - ETL automatica',
    'interval_minutes' => 15,

    // Não começa um ciclo se houve ETL manual nos últimos N minutos
    'manual_quiet_minutes' => 5,

    // Tempo máximo de cada etapa (s) — passou disso, a etapa é encerrada
    'step_timeout_seconds' => 600,

    // Grupos de ETL (true = roda a cada ciclo)
    'groups' => [
        'cadastros_5c'    => true,  // Historic Planner (HeadCount e Pages: events do MySQL)
        'schedule'        => true,  // escala do dia (de 1 h atrás até agora; a API filtra por data)
        'infloww_vinculos'=> true,  // all creators, creators, employees, assigned creators
        'infloww_vendas'  => true,  // transações, sales records, reembolsos (janela de 1 h)
    ],

    // Executável do PHP para os processos filhos (padrão: o mesmo que roda o runner)
    'php_binary' => PHP_BINARY,

    // Pasta "systems" do portal (padrão: a pasta acima desta)
    'systems_dir' => dirname(__DIR__),

    // Logs do runner: quantos dias manter
    'log_retention_days' => 30,

    // Quem pode pausar/retomar pela tela etl_auto/index.php (login do portal)
    'admin_emails' => [
        'luis.sarmento@5ccompany.com',
        'mauricio.sarmento@5ccompany.com',
        'amanda.santos@5ccompany.com',
    ],
];
