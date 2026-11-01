# Alternativa ao servidor_ativar_ssh.ps1 quando o Add-WindowsCapability trava (Windows Update):
# instala o OpenSSH pelo pacote oficial da Microsoft no GitHub (Win32-OpenSSH) e faz o mesmo resto.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$porta = 2222
$chave = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIM75kxwl6vy33AUN49pxE1vRPpRV/TesxlwpWvcXH7cj 5c-deploy@DESKTOP-K8SE7J9'
$rel = Invoke-RestMethod 'https://api.github.com/repos/PowerShell/Win32-OpenSSH/releases' -Headers @{ 'User-Agent' = '5c-deploy' }
$msi = ($rel | ForEach-Object { $_.assets } | Where-Object { $_.name -like 'OpenSSH-Win64*.msi' } | Select-Object -First 1)
"Baixando " + $msi.name
$arq = Join-Path $env:TEMP $msi.name
Invoke-WebRequest $msi.browser_download_url -OutFile $arq -UseBasicParsing
Start-Process msiexec.exe -ArgumentList "/i `"$arq`" /qn ADDLOCAL=Server" -Wait
Set-Service -Name sshd -StartupType Automatic
Start-Service sshd
$cfg = 'C:\ProgramData\ssh\sshd_config'
(Get-Content $cfg) -replace '^#?Port\s+\d+', "Port $porta" -replace '^#?PubkeyAuthentication\s+\w+', 'PubkeyAuthentication yes' -replace '^#?PasswordAuthentication\s+\w+', 'PasswordAuthentication no' | Set-Content $cfg -Encoding ascii
$ak = 'C:\ProgramData\ssh\administrators_authorized_keys'
if (-not (Test-Path $ak) -or -not (Select-String -Path $ak -SimpleMatch $chave -Quiet)) { Add-Content -Path $ak -Value $chave -Encoding ascii }
icacls $ak /inheritance:r /grant '*S-1-5-32-544:F' /grant '*S-1-5-18:F' | Out-Null
if (-not (Get-NetFirewallRule -Name sshd-5c -ErrorAction SilentlyContinue)) { New-NetFirewallRule -Name sshd-5c -DisplayName 'OpenSSH 5C deploy' -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort $porta | Out-Null }
Restart-Service sshd
"Servico: " + (Get-Service sshd).Status
"Usuario: " + (whoami)
"Escutando na porta ${porta}: " + [bool](Get-NetTCPConnection -LocalPort $porta -State Listen -ErrorAction SilentlyContinue)
