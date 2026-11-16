@echo off
cd /d "%~dp0"
title Portal InfotecSoft Treinamentos
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
echo.
echo  ==== Portal InfotecSoft Treinamentos ====
echo.
if not exist ".env" (
  if exist "C:\sistema-pdv-main\.env" (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "$l = Get-Content -LiteralPath 'C:\sistema-pdv-main\.env' | Where-Object { $_ -notmatch '^(DB_DATABASE|APP_PORT|UPLOAD_MAX_MB)=' }; $l + 'DB_DATABASE=treinamentos' + 'APP_PORT=8001' + 'UPLOAD_MAX_MB=2048' | Set-Content -LiteralPath '.env' -Encoding ASCII"
  )
  if not exist ".env" copy /y ".env.example" ".env" >nul
  echo [1/3] Configuracao .env criada ^(mesmo MySQL do sistema-pdv, banco "treinamentos"^).
) else (
  echo [1/3] Configuracao .env encontrada.
)
echo [2/3] Preparando o banco de dados...
"%PHP%" database\scripts\run_all.php > storage\migrate_log.txt 2>&1
if errorlevel 1 (
  echo.
  type storage\migrate_log.txt
  echo.
  echo [ERRO] Nao foi possivel preparar o banco.
  echo   - Abra o Laragon e clique em "Iniciar tudo" ^(o MySQL precisa estar ligado^).
  echo   - Se o erro for de senha, confira DB_PASSWORD no arquivo .env
  echo.
  pause
  exit /b 1
)
echo       Banco "treinamentos" pronto.
set "PORT=8001"
for /f "tokens=2 delims==" %%p in ('findstr /b "APP_PORT=" .env 2^>nul') do set "PORT=%%p"
echo [3/3] Abrindo o portal em http://localhost:%PORT%
echo.
echo   Deixe esta janela ABERTA enquanto usa o portal. Para parar, feche-a.
echo.
start "" /min cmd /c "timeout /t 3 /nobreak >nul & start "" http://localhost:%PORT%"
"%PHP%" -d upload_max_filesize=2048M -d post_max_size=2100M -d max_execution_time=600 -d max_input_time=600 -d memory_limit=512M -S localhost:%PORT% -t public_html
echo.
echo O servidor parou. Se apareceu "Address already in use", a porta %PORT% ja esta em uso:
echo feche a outra janela do portal ou troque APP_PORT no arquivo .env
pause
