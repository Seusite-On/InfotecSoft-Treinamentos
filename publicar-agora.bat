@echo off
cd /d "%~dp0"
set "GIT_CONFIG_COUNT=1"
set "GIT_CONFIG_KEY_0=safe.directory"
set "GIT_CONFIG_VALUE_0=C:/InfotecSoft-Treinamentos"
title Publicar no GitHub
set "GIT="
where git >nul 2>nul && set "GIT=git"
if not defined GIT if exist "C:\laragon\bin\git\cmd\git.exe" set "GIT=C:\laragon\bin\git\cmd\git.exe"
if not exist ".github\workflows" mkdir ".github\workflows"
copy /y "deploy\deploy.yml" ".github\workflows\deploy.yml" >nul
(
"%GIT%" add -A
"%GIT%" commit -m "Deploy para hospedagem compartilhada Hostinger (zenim.me)" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01LAtTZ9d76jyjDMLh1WEmRu"
"%GIT%" -c credential.helper= -c credential.helper=manager push
echo PUSH_EXIT=%ERRORLEVEL%
"%GIT%" log --oneline -3
) > storage\git_log.txt 2>&1
type storage\git_log.txt
timeout /t 8
