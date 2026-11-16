@echo off
cd /d "%~dp0"
title Publicar em zenim.me
set "GIT_CONFIG_COUNT=1"
set "GIT_CONFIG_KEY_0=safe.directory"
set "GIT_CONFIG_VALUE_0=C:/InfotecSoft-Treinamentos"
set "GIT="
where git >nul 2>nul && set "GIT=git"
if not defined GIT if exist "C:\laragon\bin\git\cmd\git.exe" set "GIT=C:\laragon\bin\git\cmd\git.exe"
if /i "%~1"=="senha" (
  echo Apagando a configuracao do banco no servidor para digitar a senha de novo...
  ssh -i "%USERPROFILE%\.ssh\zenim_deploy" -p 65002 -o BatchMode=yes u846229461@149.62.37.243 "rm -f domains/zenim.me/.env"
)
if defined GIT (
  echo ==^> Salvando versao no GitHub
  if not exist ".github\workflows" mkdir ".github\workflows"
  copy /y "deploy\deploy.yml" ".github\workflows\deploy.yml" >nul
  "%GIT%" add -A
  "%GIT%" commit -q -m "Atualizacao do portal %date% %time:~0,5%"
  "%GIT%" -c credential.helper= -c credential.helper=manager push -q
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0publicar-hostinger.ps1"
echo.
pause
