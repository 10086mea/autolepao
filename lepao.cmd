@echo off
setlocal
chcp 65001 >nul
"%~dp0runtime\php.exe" -n -d "extension_dir=%~dp0runtime\ext" -d extension=curl -d extension=openssl "%~dp0lepao.phar" %*
set "result=%errorlevel%"
if not "%result%"=="0" (
  echo.
  pause
)
exit /b %result%
