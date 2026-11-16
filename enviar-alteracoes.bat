@echo off
cd /d "%~dp0"
title Enviar alteracoes para o GitHub
set "GIT="
where git >nul 2>nul && set "GIT=git"
if not defined GIT if exist "C:\laragon\bin\git\cmd\git.exe" set "GIT=C:\laragon\bin\git\cmd\git.exe"
if not defined GIT (
  echo [ERRO] Git nao encontrado. Instale em https://git-scm.com/download/win
  pause
  exit /b 1
)
"%GIT%" status --short
echo.
set "MSG="
set /p MSG=Descreva o que voce alterou (Enter = "Atualizacao do portal"): 
if not defined MSG set "MSG=Atualizacao do portal"
"%GIT%" add -A
"%GIT%" commit -m "%MSG%"
"%GIT%" push
echo.
echo Enviado! Acompanhe a publicacao em: GitHub ^> seu repositorio ^> aba Actions
pause
