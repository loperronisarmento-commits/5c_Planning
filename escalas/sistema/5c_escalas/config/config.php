<?php
// ===============================================
// ARQUIVO: 5c_escalas/config/config.php
// Configuração LOCAL do 5C Escalas (versão de teste).
//
// Mesmo padrão dos outros módulos do portal: helpers/ e config/
// COMPARTILHADOS ficam em mis_portal/api/ (SHARED_ROOT). Daqui até lá:
// sobe 3 níveis (config/ -> 5c_escalas/ -> systems/ -> mis_portal/) e
// desce em api/. Ajuste só aqui se a estrutura mudar.
// ===============================================

if (!defined('SHARED_ROOT')) {
    define('SHARED_ROOT', __DIR__ . '/../../../api/');
}

if (!defined('MODULE_URL')) {
    // Caminho físico real (o alias curto /escalas do .htaccess da raiz só
    // aponta para o index.php; CSS, JS e downloads usam este caminho).
    define('MODULE_URL', '/mis_portal/systems/5c_escalas');
}

// Gravação: só no schema 5c_escalas (ver lib/db.php). Planner, escala
// postada, Capacity, headcount e CRM continuam só sendo lidos.

// Acessos do Schedule Anomalies: usados uma vez, para a primeira carga
// de 5c_escalas.esc_acesso quando ela estiver vazia (ver lib/acesso.php).
define('ESC_TABELA_ACESSO', '`5c_wfm_mis_web`.`mis_module_access_schedule_anomalies`');

// Limite de tempo por consulta (ms), para não prender o MySQL.
define('ESC_MAX_EXECUCAO_MS', 30000);

// Rascunho clicável das telas (privado; abre para quem tem acesso).
define('ESC_URL_RASCUNHO', 'https://claude.ai/artifact/BJDKQYLPz119juqs4hcBP8');

// Módulos existentes ligados no menu.
define('ESC_URL_ANOMALIAS', '/schedule_anomalies');
define('ESC_URL_AUDITORIA', '/mis_portal/systems/5c_schedule_access_audit/');
