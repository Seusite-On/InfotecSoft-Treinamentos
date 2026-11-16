# Publica o portal em https://zenim.me direto deste PC (sem GitHub Actions).
$ErrorActionPreference = 'Continue'
$Root    = Split-Path -Parent $MyInvocation.MyCommand.Path
# Dados do servidor ficam so no seu PC (deploy\servidor.local.json, fora do GitHub)
$CfgFile = Join-Path $Root 'deploy\servidor.local.json'
if (-not (Test-Path $CfgFile)) { Write-Host "[ERRO] Arquivo deploy\servidor.local.json nao encontrado." -ForegroundColor Red; exit 1 }
$Cfg = Get-Content $CfgFile -Raw | ConvertFrom-Json
$SshHost = $Cfg.host; $Port = [int]$Cfg.port; $User = $Cfg.user
$AppDir  = $Cfg.app_dir; $Site = $Cfg.site
$DbName  = $Cfg.db_name; $DbUser = $Cfg.db_user
$OutputEncoding = New-Object System.Text.UTF8Encoding $false

$Log = Join-Path $Root 'storage\publicar_log.txt'
Set-Content $Log ("=== " + (Get-Date)) -Encoding UTF8
function L($t) { Add-Content $Log $t -Encoding UTF8 }
function Etapa($t) { Write-Host ""; Write-Host "==> $t" -ForegroundColor Cyan; L "==> $t" }
function Falha($t) { Write-Host ""; Write-Host "[ERRO] $t" -ForegroundColor Red; L "[ERRO] $t"; Write-Host ""; exit 1 }
# roda um programa mostrando e gravando a saida; codigo de saida em $script:rc
function N($exe, [string[]]$a) { & $exe @a 2>&1 | ForEach-Object { $l = "$_"; Write-Host $l; L $l }; $script:rc = $LASTEXITCODE }

foreach ($c in 'ssh','scp','tar') { if (-not (Get-Command "$c.exe" -ErrorAction SilentlyContinue)) { Falha "$c nao encontrado. Ative o 'Cliente OpenSSH' em Configuracoes > Aplicativos > Recursos opcionais." } }

# 1) Chave SSH: copia para a pasta do usuario com permissao restrita (o ssh do Windows exige isso)
Etapa 'Preparando a chave SSH'
$KeyDir = Join-Path $env:USERPROFILE '.ssh'
$Key    = Join-Path $KeyDir 'zenim_deploy'
$KeyTxt = Join-Path $Root 'deploy\CHAVE-PRIVADA-GITHUB.txt'
if (Test-Path $KeyTxt) {
  $txt = Get-Content $KeyTxt -Raw
  $m = [regex]::Match($txt, '-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----')
  if (-not $m.Success) { Falha 'O arquivo deploy\CHAVE-PRIVADA-GITHUB.txt nao tem a chave.' }
  New-Item -ItemType Directory -Force $KeyDir | Out-Null
  if (Test-Path $Key) { icacls $Key /reset | Out-Null }
  [IO.File]::WriteAllText($Key, ($m.Value -replace "`r", '') + "`n")
  icacls $Key /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
} elseif (-not (Test-Path $Key)) { Falha 'Chave nao encontrada (deploy\CHAVE-PRIVADA-GITHUB.txt).' }
$SshOpts = @('-i', $Key, '-o', 'StrictHostKeyChecking=accept-new', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3')
$SshArgs = $SshOpts + @('-p', "$Port", "$User@$SshHost")
function Remoto($cmd) { N ssh.exe ($SshArgs + $cmd); if ($script:rc -ne 0) { Falha "Comando no servidor falhou: $cmd" } }


# 2) Empacota o projeto
Etapa 'Empacotando arquivos'
$Tgz = Join-Path $env:TEMP 'zenim-site.tgz'
if (Test-Path $Tgz) { Remove-Item $Tgz -Force }
$ex = './.git','./.github','./frontend','./deploy','./.env','./vendor','./storage/videos','./storage/thumbs','./storage/security','./storage/*.txt','./*.bat','./*.ps1','./index.html','./LEIA-ME.txt'
$tarArgs = @('-czf', $Tgz) + ($ex | ForEach-Object { "--exclude=$_" }) + @('-C', $Root, '.')
N tar.exe $tarArgs; if ($script:rc -ne 0) { Falha 'Erro ao empacotar.' }
$t = "Pacote: {0:N1} MB" -f ((Get-Item $Tgz).Length / 1MB); Write-Host $t; L $t

# 3) Envia e extrai
Etapa 'Enviando para a Hostinger'
# Envia o pacote pela entrada do ssh e ja extrai, numa unica conexao (com limite de tempo e ate 3 tentativas)
$Out = Join-Path $env:TEMP 'zenim-ssh-out.txt'; $Err = Join-Path $env:TEMP 'zenim-ssh-err.txt'
$remote = "mkdir -p $AppDir && cat > zenim-site.tgz && tar xzf zenim-site.tgz -C $AppDir && rm -f zenim-site.tgz && echo ENVIO_OK && (test -f $AppDir/.env && echo ENV_OK || echo ENV_FALTA)"
$ok = $false; $envOk = $false
for ($t = 1; $t -le 3 -and -not $ok; $t++) {
  if ($t -gt 1) { Write-Host "Conexao lenta, tentando de novo ($t/3)..." -ForegroundColor Yellow; L "retry $t"; Start-Sleep 5 }
  $argLine = (($SshArgs | ForEach-Object { '"' + $_ + '"' }) -join ' ') + ' "' + $remote + '"'
  $p = Start-Process -FilePath 'ssh.exe' -ArgumentList $argLine -RedirectStandardInput $Tgz -RedirectStandardOutput $Out -RedirectStandardError $Err -NoNewWindow -PassThru
  if (-not $p.WaitForExit(120000)) { try { $p.Kill() } catch {} ; L 'tempo esgotado no envio'; continue }
  $o = (Get-Content $Out -Raw -ErrorAction SilentlyContinue) + (Get-Content $Err -Raw -ErrorAction SilentlyContinue)
  if ($o) { L $o }
  if ($o -match 'ENVIO_OK') { $ok = $true; $envOk = $o -match 'ENV_OK' } else { Write-Host $o }
}
if (-not $ok) { Falha 'Nao foi possivel enviar para a Hostinger (conexao). Tente de novo em alguns minutos.' }
Write-Host 'Arquivos enviados.'

# 4) .env do servidor (so pede a senha do banco na primeira vez)
if (-not $envOk) {
  L '.env ausente no servidor: pedindo senha do banco'
  Etapa 'Configurando o banco do servidor (primeira vez)'
  $sec = Read-Host "Digite a senha do banco $DbName (nao aparece na tela)" -AsSecureString
  $pw  = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
  $envTxt = "DB_HOST=localhost`nDB_PORT=3306`nDB_USERNAME=$DbUser`nDB_PASSWORD=$pw`nDB_DATABASE=$DbName`nUPLOAD_MAX_MB=2048`n"
  $envTxt | & ssh.exe @SshArgs "tr -d '\r' > $AppDir/.env && chmod 600 $AppDir/.env"
  if ($LASTEXITCODE -ne 0) { Falha 'Erro ao gravar o .env no servidor.' }
}

# 5) Banco + testes
Etapa 'Atualizando banco e testando'
$cmd = 'cd ' + $AppDir + ' && mkdir -p storage/videos storage/thumbs storage/security && rm -f public_html/default.php && PHP=$( [ -x /opt/alt/php83/usr/bin/php ] && echo /opt/alt/php83/usr/bin/php || command -v php ) && $PHP -v | head -1 && $PHP database/scripts/migrate.php && $PHP database/scripts/seed.php && $PHP tests/smoke.php'
N ssh.exe ($SshArgs + $cmd)
if ($script:rc -ne 0) {
  Write-Host 'Se o erro for de acesso ao banco, a senha pode estar errada: rode de novo com  publicar-hostinger.bat senha' -ForegroundColor Yellow
  Falha 'Atualizacao do banco/teste falhou.'
}

# 6) Confere o site
Etapa "Conferindo $Site"
Start-Sleep 2
try { $r = Invoke-WebRequest "$Site/api/state" -UseBasicParsing -TimeoutSec 30; $code = $r.StatusCode } catch { $code = $_.Exception.Response.StatusCode.value__ }
Write-Host "GET $Site/api/state -> HTTP $code"; L "HTTP $code"
if ($code -ne 200) { Falha 'O site nao respondeu 200.' }
Write-Host ""; Write-Host "PUBLICADO! Acesse $Site" -ForegroundColor Green; L 'PUBLICADO'
