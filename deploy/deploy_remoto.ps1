# =====================================================================
# Roda NO SERVIDOR, chamado pelo deploy.ps1 (via SSH). Instala o zip do 5C Escalas na pasta
# do portal, do mesmo jeito que o envio manual pelo RDP (descompacta por cima), com cópia de
# segurança antes. -Voltar <arquivo> restaura uma cópia de segurança.
# =====================================================================
param(
    [string]$Zip,
    [string]$Voltar,
    [string]$Destino = 'C:\WebServer\Apache\htdocs\mis_portal\systems',
    [string]$Modulo = '5c_escalas',
    [string]$Pasta = 'C:\WebServer\deploy'
)
$ErrorActionPreference = 'Stop'
$alvo = Join-Path $Destino $Modulo
$backups = Join-Path $Pasta 'backup'
New-Item -ItemType Directory -Force -Path $backups | Out-Null

function Versao([string]$dir) {
    $arq = Join-Path $dir 'lib\versao.php'
    if (-not (Test-Path $arq)) { return '?' }
    $m = Select-String -Path $arq -Pattern "'versao' => '(\d+\.\d+\.\d+)'" | Select-Object -First 1
    if ($m) { return $m.Matches[0].Groups[1].Value } else { return '?' }
}

if ($Voltar) {
    if (-not (Test-Path $Voltar)) { throw "Cópia não encontrada: $Voltar" }
    Expand-Archive -Path $Voltar -DestinationPath $Destino -Force
    "VOLTOU para a versão " + (Versao $alvo) + " ($Voltar)"
    exit 0
}

if (-not $Zip -or -not (Test-Path $Zip)) { throw "Zip não encontrado: $Zip" }

# 1. Confere o zip numa pasta temporária: precisa ter 5c_escalas/index.php e lib/versao.php
$tmp = Join-Path $Pasta ('tmp_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
Expand-Archive -Path $Zip -DestinationPath $tmp -Force
$novo = Join-Path $tmp $Modulo
if (-not (Test-Path (Join-Path $novo 'index.php')) -or -not (Test-Path (Join-Path $novo 'lib\versao.php'))) {
    Remove-Item $tmp -Recurse -Force
    throw 'O zip não tem a pasta 5c_escalas com index.php e lib/versao.php.'
}
$antes = Versao $alvo
$depois = Versao $novo

# 2. Cópia de segurança da pasta que está no ar
$copia = Join-Path $backups ("{0}_{1}_{2}.zip" -f $Modulo, $antes, (Get-Date -Format 'yyyyMMdd_HHmmss'))
if (Test-Path $alvo) { Compress-Archive -Path $alvo -DestinationPath $copia -CompressionLevel Fastest }

# 3. Instala por cima (como o envio manual: arquivos novos substituem, os outros ficam)
New-Item -ItemType Directory -Force -Path $alvo | Out-Null
Copy-Item -Path (Join-Path $novo '*') -Destination $alvo -Recurse -Force
Remove-Item $tmp -Recurse -Force

# 4. Guarda só as 10 cópias mais recentes
Get-ChildItem $backups -Filter "$Modulo*.zip" | Sort-Object LastWriteTime -Descending | Select-Object -Skip 10 | Remove-Item -Force

"OK: $antes -> " + (Versao $alvo)
"Copia de seguranca: $copia"
