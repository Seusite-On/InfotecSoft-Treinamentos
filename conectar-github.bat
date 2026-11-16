@echo off
cd /d "%~dp0"
set "GIT_CONFIG_COUNT=1"
set "GIT_CONFIG_KEY_0=safe.directory"
set "GIT_CONFIG_VALUE_0=C:/InfotecSoft-Treinamentos"
title Conectar ao GitHub - InfotecSoft Treinamentos
set "GIT="
where git >nul 2>nul && set "GIT=git"
if not defined GIT if exist "C:\Program Files\Git\cmd\git.exe" set "GIT=C:\Program Files\Git\cmd\git.exe"
if not defined GIT if exist "C:\laragon\bin\git\cmd\git.exe" set "GIT=C:\laragon\bin\git\cmd\git.exe"
if not defined GIT (
  echo [ERRO] Git nao encontrado. Feche esta janela, reinicie o computador e tente de novo.
  echo [ERRO] Git nao encontrado > storage\git_log.txt
  pause
  exit /b 1
)
if not exist storage mkdir storage
echo Conectando a pasta ao GitHub... (na primeira vez abre uma janela para autorizar)
call :main > storage\git_log.txt 2>&1
type storage\git_log.txt
echo.
pause
exit /b

:main
echo === %date% %time% ===
"%GIT%" --version
echo HOME=%HOME% USERPROFILE=%USERPROFILE%
"%GIT%" config --global --list --show-origin
"%GIT%" config --global --get-all safe.directory | findstr /i /c:"C:/InfotecSoft-Treinamentos" >nul || "%GIT%" config --global --add safe.directory C:/InfotecSoft-Treinamentos
if not exist ".github\workflows" mkdir ".github\workflows"
copy /y "deploy\deploy.yml" ".github\workflows\deploy.yml"
if not exist ".git" "%GIT%" init -b main
"%GIT%" config user.name >nul 2>nul || "%GIT%" config user.name "Seusite-On"
"%GIT%" config user.email >nul 2>nul || "%GIT%" config user.email "Seusite-On@users.noreply.github.com"
"%GIT%" remote remove origin 2>nul
"%GIT%" remote add origin https://github.com/Seusite-On/InfotecSoft-Treinamentos.git
"%GIT%" add -A
"%GIT%" commit -m "Portal de treinamentos - versao inicial"
"%GIT%" branch -M main
"%GIT%" -c credential.helper= -c credential.helper=manager push -u origin main
echo PUSH_EXIT=%errorlevel%
"%GIT%" status -sb
"%GIT%" log --oneline -3
exit /b
