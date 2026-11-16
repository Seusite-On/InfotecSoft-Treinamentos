@echo off
cd /d "%~dp0"
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  for /d %%d in ("C:\laragon\bin\php\php-8*") do set "PHP=%%d\php.exe"
)
if not exist storage mkdir storage
"%PHP%" database\scripts\seed_teste.php > storage\seed_teste_log.txt 2>&1
type storage\seed_teste_log.txt
echo.
pause
