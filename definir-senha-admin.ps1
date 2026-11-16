# Define a senha do administrador do portal publicado (zenim.me). A senha e digitada por voce e nao fica salva.
$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Cfg = Get-Content (Join-Path $Root 'deploy\servidor.local.json') -Raw | ConvertFrom-Json
$Key = Join-Path $env:USERPROFILE '.ssh\zenim_deploy'
if (-not (Test-Path $Key)) { Write-Host '[ERRO] Chave SSH nao encontrada. Rode o publicar-hostinger.bat uma vez antes.' -ForegroundColor Red; exit 1 }
Write-Host ''
Write-Host 'Nova senha do administrador (8+ caracteres, com letras e numeros).' -ForegroundColor Cyan
$a = Read-Host 'Digite a nova senha' -AsSecureString
$b = Read-Host 'Repita a nova senha' -AsSecureString
$pa = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($a))
$pb = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($b))
if ($pa -ne $pb) { Write-Host '[ERRO] As senhas nao conferem.' -ForegroundColor Red; exit 1 }
$OutputEncoding = New-Object System.Text.UTF8Encoding $false
$cmd = 'cd ' + $Cfg.app_dir + ' && PHP=$( [ -x /opt/alt/php83/usr/bin/php ] && echo /opt/alt/php83/usr/bin/php || command -v php ) && $PHP database/scripts/set_admin_password.php'
$pa | & ssh.exe -i $Key -p "$($Cfg.port)" -o BatchMode=yes -o ConnectTimeout=20 "$($Cfg.user)@$($Cfg.host)" $cmd
if ($LASTEXITCODE -eq 0) { Write-Host ''; Write-Host 'PRONTO! Entre no portal com o usuario admin e a nova senha.' -ForegroundColor Green }
else { Write-Host '[ERRO] Nao foi possivel alterar a senha.' -ForegroundColor Red }
