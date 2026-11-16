@echo off
cd /d "%~dp0"
title Definir senha do administrador (zenim.me)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0definir-senha-admin.ps1"
echo.
pause
