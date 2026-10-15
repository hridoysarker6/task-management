@echo off
rem Starts the Task Management app (no Laragon virtual host needed).
rem Then open http://localhost:8000 in the browser.
set "PATH=D:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;D:\laragon\bin\composer;D:\laragon\bin\nodejs\node-v22;%PATH%"
pushd "%~dp0"

rem Notifications and email are sent in the background: this worker sends them, and the scheduler runs the daily reminders.
rem Without them the bell stays empty. Close their windows to stop them.
start "Task Management - queue worker" cmd /k php artisan queue:work --tries=3 --sleep=2
start "Task Management - scheduler" cmd /k php artisan schedule:work

echo Starting Laravel on http://localhost:8000  (close this window to stop)
echo If the page looks unstyled, run "npm run build" once.
php artisan serve --host=127.0.0.1 --port=8000
popd
