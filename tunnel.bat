@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

title NexTalk Frontend — LocalTunnel (Публичный доступ)

:: 1. Проверяем наличие Node.js / npx
where npx >nul 2>&1
if %ERRORLEVEL% equ 0 goto check_tools_ok

echo ================================================================
echo [ОШИБКА] Утилита 'npx' не найдена!
echo Для работы LocalTunnel необходим Node.js.
echo Пожалуйста, установите Node.js с сайта: https://nodejs.org/
echo ================================================================
echo.
pause
exit /b 1

:check_tools_ok
set "TARGET_PORT="
set "CUSTOM_SUBDOMAIN="
set "LOCAL_HOST_OVERRIDE="
set "MODE="
set "OPEN_BROWSER=0"
set "STARTED_PHP=0"
set "PHP_PID="
set "USER_SUBDOMAIN="

:: 2. Парсинг параметров командной строки
:parse_args
if "%~1"=="" goto after_args

if /i "%~1"=="-p" goto set_port
if /i "%~1"=="--port" goto set_port
if /i "%~1"=="-s" goto set_subdomain
if /i "%~1"=="--subdomain" goto set_subdomain
if /i "%~1"=="-l" goto set_localhost
if /i "%~1"=="--local-host" goto set_localhost
if /i "%~1"=="-o" goto set_open
if /i "%~1"=="--open" goto set_open
if /i "%~1"=="--ospanel" goto set_ospanel
if /i "%~1"=="--standalone" goto set_standalone
if /i "%~1"=="-h" goto show_help
if /i "%~1"=="--help" goto show_help

shift
goto parse_args

:set_port
set "TARGET_PORT=%~2"
shift
shift
goto parse_args

:set_subdomain
set "CUSTOM_SUBDOMAIN=%~2"
shift
shift
goto parse_args

:set_localhost
set "LOCAL_HOST_OVERRIDE=%~2"
shift
shift
goto parse_args

:set_open
set "OPEN_BROWSER=1"
shift
goto parse_args

:set_ospanel
set "MODE=ospanel"
shift
goto parse_args

:set_standalone
set "MODE=standalone"
shift
goto parse_args

:show_help
echo ================================================================
echo           NexTalk Frontend — Справка LocalTunnel
echo ================================================================
echo.
echo Использование: tunnel.bat [опции]
echo.
echo Опции:
echo   -s, --subdomain ^<имя^>     Запросить постоянный поддомен (напр: nextalk-chat)
echo   -p, --port ^<порт^>         Локальный порт (по умолчанию: 80 для OSPanel или 8000)
echo   -l, --local-host ^<хост^>   Переопределить заголовок Host (по умолчанию: nextalk-frontend)
echo   -o, --open                Автоматически открыть ссылку в браузере
echo   --ospanel                 Принудительно использовать OSPanel (порт 80)
echo   --standalone              Принудительно запустить встроенный PHP сервер
echo   -h, --help                Показать эту справку
echo.
echo Примеры:
echo   tunnel.bat
echo   tunnel.bat -s my-messenger
echo   tunnel.bat --ospanel -s my-messenger -o
echo   tunnel.bat --standalone -p 8000
echo.
pause
exit /b 0

:after_args

echo ================================================================
echo           NexTalk Frontend — Запуск в открытый доступ
echo                       (LocalTunnel)
echo ================================================================
echo.

if defined TARGET_PORT goto custom_port_selected
if /i "%MODE%"=="ospanel" goto setup_ospanel
if /i "%MODE%"=="standalone" goto setup_standalone

:: Проверяем, запущен ли веб-сервер OSPanel на 80 порту
netstat -ano | findstr /C:":80 " | findstr /I "LISTENING" >nul 2>&1
if %ERRORLEVEL% neq 0 goto ospanel_not_running

echo [ИНФО] Обнаружен запущенный веб-сервер OSPanel (порт 80).
echo.
echo Выберите режим подключения (автовыбор [1] через 5 сек):
echo   [1] Использовать OSPanel (порт 80, хост nextalk-frontend)
echo   [2] Запустить автономный встроенный PHP сервер (порт 8000)
choice /C 12 /T 5 /D 1 /M "Ваш выбор"
if !ERRORLEVEL! equ 2 goto setup_standalone
goto setup_ospanel

:ospanel_not_running
echo [ИНФО] Веб-сервер OSPanel на 80 порту не обнаружен.
echo        Будет запущен автономный встроенный PHP сервер.
goto setup_standalone

:setup_ospanel
set "PORT=80"
if not defined LOCAL_HOST_OVERRIDE set "LOCAL_HOST_OVERRIDE=nextalk-frontend"
echo.
echo [РЕЖИМ] Веб-сервер OSPanel (порт: 80, Host: %LOCAL_HOST_OVERRIDE%)
goto prompt_subdomain

:setup_standalone
set "PORT=8000"
echo.
echo [РЕЖИМ] Автономный встроенный PHP сервер (порт: %PORT%)
goto start_php_server

:custom_port_selected
set "PORT=%TARGET_PORT%"
if "%PORT%"=="80" goto setup_ospanel
echo [РЕЖИМ] Пользовательский порт: %PORT%
goto start_php_server

:start_php_server
set "PHP_BIN="
where php >nul 2>&1
if %ERRORLEVEL% equ 0 set "PHP_BIN=php"
if not defined PHP_BIN if exist "C:\OSPanel\modules\php\PHP_8.3\php.exe" set "PHP_BIN=C:\OSPanel\modules\php\PHP_8.3\php.exe"
if not defined PHP_BIN if exist "C:\OSPanel\modules\php\PHP_8.2\php.exe" set "PHP_BIN=C:\OSPanel\modules\php\PHP_8.2\php.exe"
if not defined PHP_BIN if exist "C:\OSPanel\modules\php\PHP_8.1\php.exe" set "PHP_BIN=C:\OSPanel\modules\php\PHP_8.1\php.exe"
if not defined PHP_BIN if exist "C:\OSPanel\modules\php\PHP_8.0\php.exe" set "PHP_BIN=C:\OSPanel\modules\php\PHP_8.0\php.exe"
if not defined PHP_BIN if exist "C:\OSPanel\modules\php\PHP_7.4\php.exe" set "PHP_BIN=C:\OSPanel\modules\php\PHP_7.4\php.exe"
if not defined PHP_BIN (
    for /d %%D in ("C:\OSPanel\modules\php\PHP_*") do (
        if exist "%%D\php.exe" set "PHP_BIN=%%D\php.exe"
    )
)

if not defined PHP_BIN (
    echo [ОШИБКА] PHP не найден ни в PATH, ни в каталогах OSPanel!
    echo Убедитесь, что PHP установлен.
    pause
    exit /b 1
)

netstat -ano | findstr /C:":%PORT% " | findstr /I "LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo [ИНФО] Порт %PORT% уже слушается. Подключаемся к существующему серверу.
    goto prompt_subdomain
)

echo [ИНФО] Запуск встроенного сервера PHP (%PHP_BIN%) на http://127.0.0.1:%PORT%...
start "NexTalk_PHP_Server_%PORT%" /MIN cmd /c ""!PHP_BIN!" -S 127.0.0.1:%PORT%"
set "STARTED_PHP=1"
timeout /t 2 /nobreak >nul 2>&1 || ping -n 3 127.0.0.1 >nul

for /f "tokens=5" %%P in ('netstat -ano ^| findstr /C:":%PORT% " ^| findstr /I "LISTENING"') do (
    set "PHP_PID=%%P"
)

:prompt_subdomain
if defined CUSTOM_SUBDOMAIN goto check_tunnel_password
echo.
echo Хотите запросить определенный поддомен (например, nextalk-chat)?
echo Нажмите Enter для получения случайного адреса loca.lt.
set /p "USER_SUBDOMAIN=Желаемый поддомен (Enter - случайный): "
if defined USER_SUBDOMAIN set "CUSTOM_SUBDOMAIN=!USER_SUBDOMAIN!"

:check_tunnel_password
set "TUNNEL_PASSWORD="
for /f "delims=" %%I in ('curl.exe -s --max-time 4 https://loca.lt/mytunnelpassword 2^>nul') do (
    set "TUNNEL_PASSWORD=%%I"
)
if not defined TUNNEL_PASSWORD (
    for /f "delims=" %%I in ('curl.exe -s --max-time 4 https://api.ipify.org 2^>nul') do (
        set "TUNNEL_PASSWORD=%%I"
    )
)
if not defined TUNNEL_PASSWORD (
    for /f "delims=" %%I in ('curl.exe -s --max-time 4 https://ipv4.icanhazip.com 2^>nul') do (
        set "TUNNEL_PASSWORD=%%I"
    )
)

echo.
echo ================================================================
if defined TUNNEL_PASSWORD (
    echo  ПАРОЛЬ ДЛЯ ВХОДА В ТУННЕЛЬ [Localtunnel Password / IP]:
    echo  ^>^>^>  !TUNNEL_PASSWORD!  ^<^<^<
    echo.
    echo  ИНСТРУКЦИЯ:
    echo  При первом открытии страницы loca.lt в браузере появится запрос:
    echo    "Tunnel Password"
    echo  Скопируйте и вставьте указанный выше IP адрес: !TUNNEL_PASSWORD!
    echo  и нажмите кнопку "Click to Submit".
) else (
    echo  Внимание: если loca.lt запросит "Tunnel Password",
    echo  введите ваш внешний IP адрес: https://ipv4.icanhazip.com
)
echo ================================================================
echo.
echo [ИНФО] Запуск LocalTunnel для порта %PORT%...
echo [ИНФО] Для остановки туннеля нажмите Ctrl+C.
echo.

set "LT_ARGS=--port %PORT%"
if defined LOCAL_HOST_OVERRIDE set "LT_ARGS=!LT_ARGS! --local-host %LOCAL_HOST_OVERRIDE%"
if defined CUSTOM_SUBDOMAIN set "LT_ARGS=!LT_ARGS! --subdomain %CUSTOM_SUBDOMAIN%"
if "%OPEN_BROWSER%"=="1" set "LT_ARGS=!LT_ARGS! --open"

call npx --yes localtunnel !LT_ARGS!
set "LT_EXIT_CODE=%ERRORLEVEL%"

if "%STARTED_PHP%"=="1" (
    echo.
    echo [ИНФО] Остановка встроенного PHP сервера...
    if defined PHP_PID taskkill /F /PID !PHP_PID! >nul 2>&1
    taskkill /FI "WINDOWTITLE eq NexTalk_PHP_Server_%PORT%*" /F /T >nul 2>&1
)

echo.
if defined LT_EXIT_CODE if %LT_EXIT_CODE% neq 0 (
    echo [ПРЕДУПРЕЖДЕНИЕ] LocalTunnel завершил работу с кодом: %LT_EXIT_CODE%
    echo Возможные причины:
    echo   - Запрошенный поддомен уже занят (попробуйте другой)
    echo   - Временный сбой на серверах loca.lt
    echo.
)
echo [ИНФО] Работа туннеля завершена.
pause
