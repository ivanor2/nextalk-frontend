#!/usr/bin/env bash

# NexTalk Frontend Runner (Bash / PowerShell / Git Bash)

PORT=8000
WITH_TUNNEL=0
SUBDOMAIN=""
LOCAL_HOST=""
OPEN_BROWSER=0
IS_OSPANEL=0
HAS_ARGS=0
FORWARD_ARGS=()

while [[ $# -gt 0 ]]; do
  HAS_ARGS=1
  case $1 in
    -p|--port)
      PORT="$2"
      FORWARD_ARGS+=("$1" "$2")
      shift 2
      ;;
    -t|--tunnel)
      WITH_TUNNEL=1
      shift
      ;;
    -s|--subdomain)
      SUBDOMAIN="$2"
      FORWARD_ARGS+=("$1" "$2")
      shift 2
      ;;
    -l|--local-host)
      LOCAL_HOST="$2"
      FORWARD_ARGS+=("$1" "$2")
      shift 2
      ;;
    -o|--open)
      OPEN_BROWSER=1
      FORWARD_ARGS+=("$1")
      shift
      ;;
    --ospanel)
      IS_OSPANEL=1
      WITH_TUNNEL=1
      FORWARD_ARGS+=("$1")
      shift
      ;;
    -h|--help)
      echo "=================================================="
      echo "  NexTalk Frontend — Справка по запуску"
      echo "=================================================="
      echo "Использование: ./run.sh [опции]"
      echo ""
      echo "Опции:"
      echo "  -t, --tunnel              Запустить с LocalTunnel в открытый доступ"
      echo "  -s, --subdomain <имя>     Запросить постоянный поддомен (напр: nextalk-chat)"
      echo "  -p, --port <порт>         Локальный порт PHP (по умолчанию: 8000)"
      echo "  -l, --local-host <хост>   Заголовок Host (по умолчанию: nextalk-frontend)"
      echo "  -o, --open                Автоматически открыть ссылку в браузере"
      echo "  --ospanel                 Подключить туннель к OSPanel (порт 80)"
      echo "  -h, --help                Показать справку"
      exit 0
      ;;
    *)
      FORWARD_ARGS+=("$1")
      shift
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Если запущен без параметров, предлагаем интерактивный выбор
if [ $HAS_ARGS -eq 0 ]; then
    echo "================================================================"
    echo "          NexTalk Frontend — Меню запуска"
    echo "================================================================"
    echo "  [1] Локальный запуск (только на этом ПК, порт 8000)"
    echo "  [2] Запуск в открытый доступ через LocalTunnel (для интернета)"
    echo "  [3] Подключить LocalTunnel к работающему OSPanel (порт 80)"
    echo "  [0] Выход"
    echo "================================================================"
    read -t 10 -p "Выберите действие (автовыбор [2] через 10 сек): " choice || choice=2
    echo ""
    case $choice in
      1)
        WITH_TUNNEL=0
        ;;
      3)
        exec "$SCRIPT_DIR/tunnel.sh" --ospanel
        ;;
      0)
        exit 0
        ;;
      2|*)
        exec "$SCRIPT_DIR/tunnel.sh"
        ;;
    esac
fi

if [ $WITH_TUNNEL -eq 1 ]; then
    exec "$SCRIPT_DIR/tunnel.sh" "${FORWARD_ARGS[@]}"
fi

echo "=================================================="
echo "  Запуск NexTalk Frontend на встроенном PHP сервере"
echo "  Порт: $PORT"
echo "=================================================="

if ! command -v php &> /dev/null; then
    if [ -f "/c/OSPanel/modules/php/PHP_8.3/php.exe" ]; then
        export PATH="/c/OSPanel/modules/php/PHP_8.3:$PATH"
    elif [ -f "/c/OSPanel/modules/php/PHP_8.1/php.exe" ]; then
        export PATH="/c/OSPanel/modules/php/PHP_8.1:$PATH"
    elif [ -f "C:/OSPanel/modules/php/PHP_8.1/php.exe" ]; then
        export PATH="C:/OSPanel/modules/php/PHP_8.1:$PATH"
    else
        echo "[ERROR] PHP не найден в системе. Установите PHP и добавьте его в PATH."
        exit 1
    fi
fi

php -S 127.0.0.1:$PORT &
PHP_PID=$!
echo "[INFO] PHP сервер запущен (PID: $PHP_PID) на http://127.0.0.1:$PORT"
echo ""
echo "Для входа в открытый доступ используйте: ./tunnel.sh или ./run.sh --tunnel"
echo "Нажмите Ctrl+C для остановки сервера."

cleanup() {
    echo -e "\n[INFO] Остановка PHP сервера (PID: $PHP_PID)..."
    kill $PHP_PID 2>/dev/null
    exit 0
}

trap cleanup SIGINT SIGTERM
wait $PHP_PID
