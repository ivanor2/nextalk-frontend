# ==============================================================================
# NexTalk Frontend — Local Portable PHP Setup (Windows PowerShell)
# Установка портативного PHP в папку .php без прав администратора
# ==============================================================================

[CmdletBinding()]
param(
    [string]$TargetDir = "",
    [switch]$Force
)

$ErrorActionPreference = "Stop"

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

if (-not $TargetDir) {
    $TargetDir = Join-Path $ProjectRoot ".php"
} elseif (-not [System.IO.Path]::IsPathRooted($TargetDir)) {
    $TargetDir = Join-Path $ProjectRoot $TargetDir
}

$PhpExe = Join-Path $TargetDir "php.exe"

if ((Test-Path $PhpExe) -and (-not $Force)) {
    $ver = & $PhpExe -v 2>$null | Select-Object -First 1
    Write-Host " [OK] Портативный PHP уже настроен: $ver" -ForegroundColor Green
    exit 0
}

Write-Host ""
Write-Host " ========================================================" -ForegroundColor Cyan
Write-Host "   Настройка портативного PHP для NexTalk Frontend" -ForegroundColor Cyan
Write-Host "   Целевая папка: $TargetDir" -ForegroundColor Cyan
Write-Host " ========================================================" -ForegroundColor Cyan
Write-Host ""

if (-not (Test-Path $TargetDir)) {
    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
}

$sourceCopied = $false

# 1. Сначала проверяем, есть ли готовый PHP в OSPanel на этой машине
$ospanelPhpDirs = @(
    "C:\OSPanel\modules\php\PHP_8.3",
    "C:\OSPanel\modules\php\PHP_8.2",
    "C:\OSPanel\modules\php\PHP_8.1",
    "C:\OSPanel\modules\php\PHP_8.0",
    "D:\OSPanel\modules\php\PHP_8.2",
    "D:\OSPanel\modules\php\PHP_8.1",
    "E:\OSPanel\modules\php\PHP_8.1"
)

foreach ($src in $ospanelPhpDirs) {
    if ((Test-Path (Join-Path $src "php.exe")) -and (-not $sourceCopied)) {
        Write-Host " [1/2] Обнаружен локальный модуль PHP: $src" -ForegroundColor Yellow
        Write-Host " Копирование в локальную портативную папку '$TargetDir'..." -ForegroundColor Gray
        try {
            Copy-Item -Path "$src\*" -Destination $TargetDir -Recurse -Force
            if (Test-Path $PhpExe) {
                $sourceCopied = $true
                Write-Host " [OK] Модуль успешно скопирован." -ForegroundColor Green
                break
            }
        } catch {
            Write-Host " [WARN] Не удалось скопировать из $src, пробуем альтернативный способ..." -ForegroundColor Yellow
        }
    }
}

# 2. Если OSPanel не найден, скачиваем официальный портативный PHP для Windows
if (-not $sourceCopied) {
    Write-Host " [1/2] Скачивание официального портативного PHP с windows.php.net..." -ForegroundColor Yellow
    $zipUrls = @(
        "https://windows.php.net/downloads/releases/archives/php-8.1.9-nts-Win32-vs16-x64.zip",
        "https://windows.php.net/downloads/releases/php-8.2.27-nts-Win32-vs16-x64.zip"
    )
    $zipFile = Join-Path $ProjectRoot "php_portable_download.zip"
    $downloadSuccess = $false

    foreach ($url in $zipUrls) {
        Write-Host " Загрузка $url..." -ForegroundColor Gray
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            $curlExe = Get-Command "curl.exe" -ErrorAction SilentlyContinue
            if ($curlExe) {
                & $curlExe.Source -fSL --progress-bar $url -o $zipFile
                if ($LASTEXITCODE -eq 0 -and (Test-Path $zipFile) -and ((Get-Item $zipFile).Length -gt 5000000)) {
                    $downloadSuccess = $true
                    break
                }
            }
            if (-not $downloadSuccess) {
                $wc = New-Object System.Net.WebClient
                $wc.DownloadFile($url, $zipFile)
                if ((Test-Path $zipFile) -and ((Get-Item $zipFile).Length -gt 5000000)) {
                    $downloadSuccess = $true
                    break
                }
            }
        } catch {
            Write-Host " [WARN] Не удалось загрузить из $url" -ForegroundColor Yellow
        }
    }

    if (-not $downloadSuccess) {
        Write-Host " [ERROR] Не удалось скачать портативный PHP. Проверьте интернет-соединение." -ForegroundColor Red
        exit 1
    }

    Write-Host " Распаковка архива..." -ForegroundColor Gray
    try {
        Expand-Archive -Path $zipFile -DestinationPath $TargetDir -Force
        Remove-Item -Path $zipFile -Force -ErrorAction SilentlyContinue
    } catch {
        Write-Host " [ERROR] Ошибка распаковки архива: $_" -ForegroundColor Red
        exit 1
    }
}

# 3. Настройка php.ini для портативного режима
Write-Host " [2/2] Настройка php.ini и расширений..." -ForegroundColor Yellow
$iniFile = Join-Path $TargetDir "php.ini"
$iniDevelopment = Join-Path $TargetDir "php.ini-development"

if (-not (Test-Path $iniFile)) {
    if (Test-Path $iniDevelopment) {
        Copy-Item -Path $iniDevelopment -Destination $iniFile -Force
    }
}

$tempDir = Join-Path $TargetDir "temp"
if (-not (Test-Path $tempDir)) {
    New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
}

$sessionDir = Join-Path $TargetDir "sessions"
if (-not (Test-Path $sessionDir)) {
    New-Item -ItemType Directory -Path $sessionDir -Force | Out-Null
}

# Формируем гарантированно рабочий минимальный конфиг php.ini
$extDir = Join-Path $TargetDir "ext"
$iniContent = @"
[PHP]
engine = On
short_open_tag = On
precision = 14
output_buffering = 4096
zlib.output_compression = Off
implicit_flush = Off
serialize_precision = -1
zend.enable_gc = On
zend.multibyte = Off

max_execution_time = 300
max_input_time = 60
memory_limit = 256M

error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = On
display_startup_errors = On
log_errors = On
error_log = "$($tempDir.Replace('\', '/'))/php_errors.log"

variables_order = "GPCS"
request_order = "GP"
register_argc_argv = Off
auto_globals_jit = On

post_max_size = 64M
upload_max_filesize = 64M
max_file_uploads = 20

default_mimetype = "text/html"
default_charset = "UTF-8"

extension_dir = "ext"

extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3

[Session]
session.save_handler = files
session.save_path = "$($sessionDir.Replace('\', '/'))"
session.use_strict_mode = 0
session.use_cookies = 1
session.use_only_cookies = 1
session.name = NEXTALKSESSID
session.auto_start = 0
session.cookie_lifetime = 2419200
session.cookie_path = /
session.cookie_domain =
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.serialize_handler = php
session.gc_probability = 1
session.gc_divisor = 1000
session.gc_maxlifetime = 2419200
"@

Set-Content -Path $iniFile -Value $iniContent -Encoding UTF8

if (Test-Path $PhpExe) {
    $testOut = & $PhpExe -n -c "$iniFile" -r "echo 'PHP_PORTABLE_READY';" 2>$null
    if ($testOut -like "*PHP_PORTABLE_READY*") {
        Write-Host ""
        Write-Host " ========================================================" -ForegroundColor Green
        Write-Host "   Портативный PHP успешно настроен и готов к работе!" -ForegroundColor Green
        Write-Host "   Файл: $PhpExe" -ForegroundColor Green
        Write-Host " ========================================================" -ForegroundColor Green
        exit 0
    }
}

Write-Host " [WARN] php.exe сконфигурирован, проверка завершена." -ForegroundColor Yellow
exit 0

