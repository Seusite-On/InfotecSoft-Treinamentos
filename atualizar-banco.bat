@echo off
cd /d "%~dp0"
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP (
  for /d %%d in ("C:\laragon\bin\php\php-8*") do set "PHP=%%d\php.exe"
)
if not defined PHP (
  echo [ERRO] PHP nao encontrado. Instale/abra o Laragon ^(C:\laragon^) e tente de novo.
  pause
  exit /b 1
)
if not exist storage mkdir storage
if not exist ".env" (
  if exist "C:\sistema-pdv-main\.env" (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$l = Get-Content -LiteralPath 'C:\sistema-pdv-main\.env' | Where-Object { $_ -notmatch '^(DB_DATABASE|APP_PORT|UPLOAD_MAX_MB)=' }; $l + 'DB_DATABASE=treinamentos' + 'APP_PORT=8001' + 'UPLOAD_MAX_MB=2048' | Set-Content -LiteralPath '.env' -Encoding ASCII"
  )
  if not exist ".env" copy /y ".env.example" ".env" >nul
  echo [1/3] Configuracao .env criada ^(mesmo MySQL do sistema-pdv, banco "treinamentos"^).
) else (
  echo [1/3] Configuracao .env encontrada.
)
"%PHP%" database\scripts\run_all.php > storage\migrate_log.txt 2>&1
type storage\migrate_log.txt
echo.
pause
