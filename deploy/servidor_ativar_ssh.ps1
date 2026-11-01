# =====================================================================
# Ativa o OpenSSH no servidor do portal (209.159.144.11) para o deploy do 5C Escalas
# sem RDP. Rodar UMA vez, no servidor, no PowerShell aberto como Administrador.
# Windows Server 2019 ou mais novo (no 2016 o OpenSSH precisa ser instalado à parte).
#   - Porta 2222, só com chave (senha desligada).
#   - A chave abaixo é a parte PÚBLICA da chave do deploy (a privada fica só no computador
#     do Luis, em ~/.ssh/5c_deploy).
# Para desfazer: Stop-Service sshd; Set-Service sshd -StartupType Disabled;
#                Remove-NetFirewallRule -Name sshd-5c
# =====================================================================
$porta = 2222
$chave = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIM75kxwl6vy33AUN49pxE1vRPpRV/TesxlwpWvcXH7cj 5c-deploy@DESKTOP-K8SE7J9'

# 1. Instala e liga o serviço (a primeira partida cria o sshd_config)
Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
Set-Service -Name sshd -StartupType Automatic
Start-Service sshd

# 2. Porta própria e só chave
$cfg = 'C:\ProgramData\ssh\sshd_config'
(Get-Content $cfg) `
    -replace '^#?Port\s+\d+', "Port $porta" `
    -replace '^#?PubkeyAuthentication\s+\w+', 'PubkeyAuthentication yes' `
    -replace '^#?PasswordAuthentication\s+\w+', 'PasswordAuthentication no' |
    Set-Content $cfg -Encoding ascii

# 3. Chave do deploy (usuário administrador do Windows: arquivo único do OpenSSH)
$ak = 'C:\ProgramData\ssh\administrators_authorized_keys'
if (-not (Test-Path $ak) -or -not (Select-String -Path $ak -SimpleMatch $chave -Quiet)) {
    Add-Content -Path $ak -Value $chave -Encoding ascii
}
# Só Administradores (S-1-5-32-544) e SYSTEM (S-1-5-18) podem ler o arquivo; senão o OpenSSH o ignora.
icacls $ak /inheritance:r /grant '*S-1-5-32-544:F' /grant '*S-1-5-18:F' | Out-Null

# 4. Firewall do Windows
if (-not (Get-NetFirewallRule -Name sshd-5c -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name sshd-5c -DisplayName 'OpenSSH 5C deploy' -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort $porta | Out-Null
}
Restart-Service sshd

# 5. Conferência: mande estas 3 linhas para o Claude
"Servico: " + (Get-Service sshd).Status
"Usuario: " + (whoami)
"Escutando na porta ${porta}: " + [bool](Get-NetTCPConnection -LocalPort $porta -State Listen -ErrorAction SilentlyContinue)
