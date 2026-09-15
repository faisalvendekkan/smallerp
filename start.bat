@echo off
REM Start SmallERP locally on Windows.  Usage:  start.bat  [port]
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo PHP is not installed, or is not on your PATH.
    echo Download it from https://windows.php.net/download and add the
    echo folder containing php.exe to your PATH.
    echo SmallERP needs PHP 8.1 or newer.
    pause
    exit /b 1
)

php -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);"
if errorlevel 1 (
    echo Your PHP is too old. SmallERP needs 8.1 or newer.
    pause
    exit /b 1
)

php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if errorlevel 1 (
    echo The pdo_sqlite extension is not enabled.
    echo Open php.ini and remove the semicolon before extension=pdo_sqlite
    pause
    exit /b 1
)

if not exist "public\index.php" (
    echo Run this from the SmallERP folder, the one holding README.md
    pause
    exit /b 1
)

set PORT=%1
if "%PORT%"=="" set PORT=8000

if not exist "storage\logs" mkdir "storage\logs"
if not exist "storage\exports" mkdir "storage\exports"

echo.
echo   SmallERP is running
echo.
echo     http://localhost:%PORT%
echo.
echo   First run? The installer will ask you to create an administrator.
echo   Tick "load demo data" to explore a sample Doha trading company.
echo.
echo   Press Ctrl+C to stop.
echo.

start "" http://localhost:%PORT%
php -S localhost:%PORT% -t public
