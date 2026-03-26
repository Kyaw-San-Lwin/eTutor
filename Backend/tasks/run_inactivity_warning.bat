@echo off
setlocal

set DAYS=%1
if "%DAYS%"=="" set DAYS=28

set PROJECT_ROOT=%~dp0..\..
set PHP_EXE=C:\xampp\php\php.exe

if not exist "%PHP_EXE%" (
  echo PHP executable not found at %PHP_EXE%
  exit /b 1
)

if not exist "%PROJECT_ROOT%\Backend\logs" (
  mkdir "%PROJECT_ROOT%\Backend\logs"
)

echo [%date% %time%] run_inactivity_warning days=%DAYS% >> "%PROJECT_ROOT%\Backend\logs\inactivity_scheduler.log"
"%PHP_EXE%" "%PROJECT_ROOT%\Backend\tasks\run_inactivity_warning.php" %DAYS% >> "%PROJECT_ROOT%\Backend\logs\inactivity_scheduler.log" 2>&1

endlocal
