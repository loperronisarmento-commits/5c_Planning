# =====================================================================
# Sobe o 5C Escalas para o servidor do portal sem RDP (SSH na porta 2222, chave ~/.ssh/5c_deploy).
# Configuração única no servidor: deploy/servidor_ativar_ssh.ps1.
#   .\deploy\deploy.ps1                 → envia escalas/sistema/5c_escalas.zip e instala
#   .\deploy\deploy.ps1 -Listar         → mostra as cópias de segurança no servidor
#   .\deploy\deploy.ps1 -Voltar <nome>  → restaura uma cópia (nome do arquivo de -Listar)
# =====================================================================
param(
    [string]$Usuario = 'Administrator',
    [string]$Servidor = '209.159.144.11',
    [int]$Porta = 2222,
    [string]$Voltar,
    [switch]$Listar
)
$ErrorActionPreference = 'Stop'
$raiz = Split-Path $PSScriptRoot -Parent
$chave = Join-Path $HOME '.ssh\5c_deploy'
$zip = Join-Path $raiz 'escalas\sistema\5c_escalas.zip'
$remoto = 'C:/WebServer/deploy'
$opts = @('-i', $chave, '-p', "$Porta", '-o', 'StrictHostKeyChecking=accept-new', '-o', 'BatchMode=yes')
$dest = "$Usuario@$Servidor"

function Remoto([string]$cmd) {
    & ssh @opts $dest "powershell -NoProfile -ExecutionPolicy Bypass -Command `"$cmd`""
    if ($LASTEXITCODE -ne 0) { throw "Falhou no servidor (código $LASTEXITCODE)." }
}

Remoto "New-Item -ItemType Directory -Force -Path $remoto/backup | Out-Null"

if ($Listar) {
    Remoto "Get-ChildItem $remoto/backup -Filter *.zip | Sort-Object LastWriteTime -Descending | Select-Object Name, LastWriteTime | Format-Table -AutoSize"
    exit 0
}

# O script do servidor vai junto a cada deploy (fica sempre na versão do repositório)
& scp -i $chave -P $Porta -o BatchMode=yes (Join-Path $PSScriptRoot 'deploy_remoto.ps1') "${dest}:$remoto/deploy_remoto.ps1"
if ($LASTEXITCODE -ne 0) { throw 'Não deu para enviar o deploy_remoto.ps1.' }

if ($Voltar) {
    Remoto "& '$remoto/deploy_remoto.ps1' -Voltar '$remoto/backup/$Voltar'"
    exit 0
}

if (-not (Test-Path $zip)) { throw "Zip não encontrado: $zip" }
$versao = (Select-String -Path (Join-Path $raiz 'escalas\sistema\5c_escalas\lib\versao.php') -Pattern "'versao' => '(\d+\.\d+\.\d+)'" | Select-Object -First 1).Matches[0].Groups[1].Value
$nome = "5c_escalas_$versao.zip"
Write-Host "Enviando $nome ..."
& scp -i $chave -P $Porta -o BatchMode=yes $zip "${dest}:$remoto/$nome"
if ($LASTEXITCODE -ne 0) { throw 'Não deu para enviar o zip.' }
Remoto "& '$remoto/deploy_remoto.ps1' -Zip '$remoto/$nome'"
