#!/usr/bin/env bash

# NexTalk Frontend — LocalTunnel Launcher (Bash / Git Bash / Linux / WSL)

echo "================================================================"
echo "          NexTalk Frontend — Запуск в открытый доступ"
echo "                      (LocalTunnel)"
echo "================================================================"
echo ""

# 1. Проверяем наличие npx
if ! command -v npx &> /dev/null; then
    echo "[ОШИБКА] npx не найден! Установите Node.js (https://nodejs.org/)."
    exit 1
fi

PORT=""
SUBDOMAIN=""
LOCAL_HOST=""
MODE=""
OPEN_BROWSER=0
STARTED_PHP=0
PHP_PID=""

# 2. Парсинг аргументов
while [[ $# -gt 0 ]]; do
  case $1 in
    -p|--port)
      PORT="$2"
      shift 2
      ;;
    -s|--subdomain)
      SUBDOMAIN="$2"
      shift 2
      ;;
    -l|--local-host)
      LOCAL_HOST="$2"
      shift 2
      ;;
    -o|--open)
      OPEN_BROWSER=1
      shift
      ;;
    --ospanel)
      MODE="ospanel"
      shift
      ;;
    --standalone)
      MODE="standalone"
      shift
      ;;
    -h|--help)
      echo "Использование: ./tunnel.sh [опции]"
      echo ""
      echo "Опции:"
      echo "  -s, --subdomain <имя>     Запросить постоянный поддомен (напр: nextalk-chat)"
      echo "  -p, --port <порт>         Локальный порт (80 для OSPanel или 8000)"
      echo "  -l, --local-host <хост>   Переопределить заголовок Host (по умолчанию: nextalk-frontend)"
      echo "  -o, --open                Автоматически открыть ссылку в браузере"
      echo "  --ospanel                 Использовать OSPanel (порт 80)"
      echo "  --standalone              Запустить встроенный PHP сервер"
      echo "  -h, --help                Показать справку"
      echo ""
      echo "Примеры:"
      echo "  ./tunnel.sh"
      echo "  ./tunnel.sh -s my-messenger"
      echo "  ./tunnel.sh --ospanel -s my-chat -o"
      exit 0
      ;;
    *)
      shift
      ;;
  esac
done

# 3. Выбор режима (OSPanel или встроенный PHP)
if [ -z "$PORT" ]; then
    if [ "$MODE" = "ospanel" ]; then
        PORT=80
        [ -z "$LOCAL_HOST" ] && LOCAL_HOST="nextalk-frontend"
    elif [ "$MODE" = "standalone" ]; then
        PORT=8000
    else
        # Проверяем, слушается ли 80 порт
        if (echo > /dev/tcp/127.0.0.1/80) >/dev/null 2>&1; then
            echo "[ИНФО] Обнаружен работающий веб-сервер на порту 80."
            echo "По умолчанию используется режим OSPanel (порт 80, хост nextalk-frontend)."
            PORT=80
            [ -z "$LOCAL_HOST" ] && LOCAL_HOST="nextalk-frontend"
        else
            PORT=8000
        fi
    fi
fi

# 4. Если порт не 80 и не занят, запускаем автономный PHP
if [ "$PORT" != "80" ]; then
    if ! (echo > /dev/tcp/127.0.0.1/$PORT) >/dev/null 2>&1; then
        PHP_BIN=""
        if command -v php &> /dev/null; then
            PHP_BIN="php"
        elif [ -f "/c/OSPanel/modules/php/PHP_8.3/php.exe" ]; then
            PHP_BIN="/c/OSPanel/modules/php/PHP_8.3/php.exe"
        elif [ -f "/c/OSPanel/modules/php/PHP_8.1/php.exe" ]; then
            PHP_BIN="/c/OSPanel/modules/php/PHP_8.1/php.exe"
        elif [ -f "C:/OSPanel/modules/php/PHP_8.1/php.exe" ]; then
            PHP_BIN="C:/OSPanel/modules/php/PHP_8.1/php.exe"
        fi

        if [ -z "$PHP_BIN" ]; then
            echo "[ОШИБКА] PHP не найден. Установите PHP и добавьте его в PATH."
            exit 1
        fi

        echo "[ИНФО] Запуск встроенного PHP сервера на http://127.0.0.1:$PORT..."
        "$PHP_BIN" -S 127.0.0.1:$PORT >/dev/null 2>&1 &
        PHP_PID=$!
        STARTED_PHP=1
        sleep 1
    fi
else
    [ -z "$LOCAL_HOST" ] && LOCAL_HOST="nextalk-frontend"
fi

cleanup() {
    echo ""
    if [ $STARTED_PHP -eq 1 ] && [ -n "$PHP_PID" ]; then
        echo "[ИНФО] Остановка встроенного PHP сервера (PID: $PHP_PID)..."
        kill "$PHP_PID" 2>/dev/null
    fi
    echo "[ИНФО] Туннель остановлен."
    exit 0
}

trap cleanup SIGINT SIGTERM EXIT

# 5. Получение пароля LocalTunnel (внешний IP)
TUNNEL_PASSWORD=$(curl -s --max-time 4 https://loca.lt/mytunnelpassword 2>/dev/null || true)
if [ -z "$TUNNEL_PASSWORD" ]; then
    TUNNEL_PASSWORD=$(curl -s --max-time 4 https://api.ipify.org 2>/dev/null || true)
fi
if [ -z "$TUNNEL_PASSWORD" ]; then
    TUNNEL_PASSWORD=$(curl -s --max-time 4 https://ipv4.icanhazip.com 2>/dev/null || true)
fi

echo "================================================================"
if [ -n "$TUNNEL_PASSWORD" ]; then
    echo " ПАРОЛЬ ДЛЯ ВХОДА (Localtunnel Password / IP):"
    echo " >>> $TUNNEL_PASSWORD <<<"
    echo ""
    echo " ИНСТРУКЦИЯ:"
    echo " При первом открытии страницы loca.lt в браузере запросит"
    echo " 'Tunnel Password' (Friendly Reminder)."
    echo " Скопируйте и вставьте указанный выше IP: $TUNNEL_PASSWORD"
    echo " и нажмите кнопку 'Click to Submit'."
else
    echo " Внимание: если loca.lt запросит Tunnel Password, введите ваш внешний IP."
fi
echo "================================================================"
echo ""
echo "[ИНФО] Запуск LocalTunnel для порта $PORT..."
echo "Для остановки нажмите Ctrl+C."
echo ""

# 6. Формирование аргументов localtunnel
LT_ARGS="--port $PORT"
if [ -n "$LOCAL_HOST" ]; then
    LT_ARGS="$LT_ARGS --local-host $LOCAL_HOST"
fi
if [ -n "$SUBDOMAIN" ]; then
    LT_ARGS="$LT_ARGS --subdomain $SUBDOMAIN"
fi
if [ $OPEN_BROWSER -eq 1 ]; then
    LT_ARGS="$LT_ARGS --open"
fi

npx --yes localtunnel $LT_ARGS
