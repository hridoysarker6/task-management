@echo off
rem Starts the Task Management app (no Laragon virtual host needed).
rem Then open http://localhost:8000 in the browser.
set "PATH=D:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;D:\laragon\bin\composer;D:\laragon\bin\nodejs\node-v22;%PATH%"
pushd "%~dp0"
echo Starting Laravel on http://localhost:8000  (close this window to stop)
echo If the page looks unstyled, run "npm run build" once.
php artisan serve --host=127.0.0.1 --port=8000
popd
