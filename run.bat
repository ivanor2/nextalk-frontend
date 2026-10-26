@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

title NexTalk Frontend Runner

set "PORT=8000"
set "WITH_TUNNEL=0"
set "TUNNEL_ARGS="
set "CUSTOM_SUBDOMAIN="
set "LOCAL_HOST="
set "OPEN_BROWSER=0"
set "IS_OSPANEL=0"
set "HAS_ARGS=0"

:parse_args
if "%~1"=="" goto after_args
set "HAS_ARGS=1"

if /i "%~1"=="-t" goto set_tunnel
if /i "%~1"=="--tunnel" goto set_tunnel
if /i "%~1"=="-p" goto set_port
if /i "%~1"=="--port" goto set_port
if /i "%~1"=="-s" goto set_subdomain
if /i "%~1"=="--subdomain" goto set_subdomain
if /i "%~1"=="-l" goto set_localhost
if /i "%~1"=="--local-host" goto set_localhost
if /i "%~1"=="-o" goto set_open
if /i "%~1"=="--open" goto set_open
if /i "%~1"=="--ospanel" goto set_ospanel
if /i "%~1"=="-h" goto show_help
if /i "%~1"=="--help" goto show_help

shift
goto parse_args

:set_tunnel
set "WITH_TUNNEL=1"
shift
goto parse_args

:set_port
set "PORT=%~2"
set "TUNNEL_ARGS=!TUNNEL_ARGS! --port %~2"
shift
shift
goto parse_args

:set_subdomain
set "CUSTOM_SUBDOMAIN=%~2"
set "TUNNEL_ARGS=!TUNNEL_ARGS! --subdomain %~2"
shift
shift
goto parse_args

:set_localhost
set "LOCAL_HOST=%~2"
set "TUNNEL_ARGS=!TUNNEL_ARGS! --local-host %~2"
shift
shift
goto parse_args

:set_open
set "OPEN_BROWSER=1"
set "TUNNEL_ARGS=!TUNNEL_ARGS! --open"
shift
goto parse_args

:set_ospanel
set "IS_OSPANEL=1"
set "WITH_TUNNEL=1"
set "TUNNEL_ARGS=!TUNNEL_ARGS! --ospanel"
shift
goto parse_args

:show_help
echo ================================================================
echo           NexTalk Frontend — Справка по запуску
echo ================================================================
echo.
echo Использование: run.bat [опции]
echo.
echo Опции:
echo   -t, --tunnel              Запустить вместе с LocalTunnel в открытый доступ
echo   -s, --subdomain ^<имя^>     Запросить постоянный поддомен (напр: nextalk-chat)
echo   -p, --port ^<порт^>         Указать порт локального PHP сервера (по умолчанию: 8000)
echo   -l, --local-host ^<хост^>   Переопределить заголовок Host (по умолчанию: nextalk-frontend)
echo   -o, --open                Автоматически открыть ссылку в браузере
echo   --ospanel                 Подключить туннель к OSPanel (порт 80)
echo   -h, --help                Показать эту справку
echo.
echo Примеры:
echo   run.bat                     (интерактивное меню выбора режима)
echo   run.bat --tunnel            (запуск встроенного PHP сервера с туннелем)
echo   run.bat --tunnel -s mychat  (запуск с постоянным поддоменом mychat.loca.lt)
echo   run.bat --ospanel           (запуск туннеля для OSPanel на 80 порту)
echo   run.bat -p 8080             (запуск только локального сервера на порту 8080)
echo.
pause
exit /b 0

:after_args
if "%HAS_ARGS%"=="1" goto process_args

:interactive_menu
echo ================================================================
echo           NexTalk Frontend — Меню запуска
echo ================================================================
echo.
echo   [1] Локальный запуск (только на этом ПК, порт 8000)
echo   [2] Запуск в открытый доступ через LocalTunnel (для интернета)
echo   [3] Подключить LocalTunnel к работающему OSPanel (порт 80)
echo   [4] Справка по опциям
echo   [0] Выход
echo.
choice /C 12340 /T 10 /D 2 /M "Выберите действие (автовыбор [2] через 10 сек)"
if !ERRORLEVEL! equ 1 goto run_local
if !ERRORLEVEL! equ 2 goto run_tunnel_mode
if !ERRORLEVEL! equ 3 goto run_ospanel_mode
if !ERRORLEVEL! equ 4 goto show_help
if !ERRORLEVEL! equ 5 exit /b 0

:run_tunnel_mode
call "%~dp0tunnel.bat"
exit /b !ERRORLEVEL!

:run_ospanel_mode
call "%~dp0tunnel.bat" --ospanel
exit /b !ERRORLEVEL!

:process_args
if "%WITH_TUNNEL%"=="1" goto delegate_to_tunnel

:run_local
goto start_local_server

:delegate_to_tunnel
call "%~dp0tunnel.bat" %TUNNEL_ARGS%
exit /b !ERRORLEVEL!

:start_local_server
echo.
echo ================================================================
echo   NexTalk Frontend — Локальный запуск (порт: %PORT%)
echo ================================================================
echo.

set "PHP_CMD="
where php >nul 2>&1
if %ERRORLEVEL% equ 0 set "PHP_CMD=php"
if not defined PHP_CMD if exist "C:\OSPanel\modules\php\PHP_8.3\php.exe" set "PHP_CMD=C:\OSPanel\modules\php\PHP_8.3\php.exe"
if not defined PHP_CMD if exist "C:\OSPanel\modules\php\PHP_8.2\php.exe" set "PHP_CMD=C:\OSPanel\modules\php\PHP_8.2\php.exe"
if not defined PHP_CMD if exist "C:\OSPanel\modules\php\PHP_8.1\php.exe" set "PHP_CMD=C:\OSPanel\modules\php\PHP_8.1\php.exe"
if not defined PHP_CMD if exist "C:\OSPanel\modules\php\PHP_8.0\php.exe" set "PHP_CMD=C:\OSPanel\modules\php\PHP_8.0\php.exe"
if not defined PHP_CMD if exist "C:\OSPanel\modules\php\PHP_7.4\php.exe" set "PHP_CMD=C:\OSPanel\modules\php\PHP_7.4\php.exe"
if not defined PHP_CMD (
    for /d %%D in ("C:\OSPanel\modules\php\PHP_*") do (
        if exist "%%D\php.exe" set "PHP_CMD=%%D\php.exe"
    )
)

if not defined PHP_CMD (
    echo [ОШИБКА] PHP не найден ни в PATH, ни в каталогах OSPanel!
    echo Убедитесь, что PHP установлен.
    pause
    exit /b 1
)

netstat -ano | findstr /C:":%PORT% " | findstr /I "LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo [ИНФО] Порт %PORT% уже кем-то слушается.
    echo Сервер доступен по адресу: http://127.0.0.1:%PORT%
    echo.
    echo Нажмите любую клавишу для завершения...
    pause >nul
    exit /b 0
)

echo [ИНФО] Запуск встроенного веб-сервера PHP (%PHP_CMD%)...
start "NexTalk_Local_PHP_%PORT%" /MIN cmd /c ""%PHP_CMD%" -S 127.0.0.1:%PORT%"
timeout /t 2 /nobreak >nul 2>&1 || ping -n 3 127.0.0.1 >nul

set "LOCAL_PID="
for /f "tokens=5" %%P in ('netstat -ano ^| findstr /C:":%PORT% " ^| findstr /I "LISTENING"') do (
    set "LOCAL_PID=%%P"
)

echo.
echo ================================================================
echo   NexTalk Frontend успешно запущен!
echo   Локальный адрес: http://127.0.0.1:%PORT%
echo.
echo   Для входа в открытый доступ используйте: tunnel.bat
echo   или запустите: run.bat --tunnel
echo ================================================================
echo.
echo Нажмите любую клавишу для остановки сервера...
pause >nul

echo [ИНФО] Остановка локального сервера...
if defined LOCAL_PID (
    taskkill /F /PID !LOCAL_PID! >nul 2>&1
)
taskkill /FI "WINDOWTITLE eq NexTalk_Local_PHP_%PORT%*" /F /T >nul 2>&1
echo [ИНФО] Сервер остановлен.
