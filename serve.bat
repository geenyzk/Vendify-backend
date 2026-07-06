@echo off
setlocal

set "HERD_PHP=%USERPROFILE%\.config\herd\bin\php.bat"

if not exist "%HERD_PHP%" (
    echo Herd PHP was not found at "%HERD_PHP%".
    echo Install Laravel Herd or update this script to point to your working php.exe.
    exit /b 1
)

"%HERD_PHP%" artisan serve --host=127.0.0.1 --port=8000 --no-reload
