<?php
/**
 * NexTalk Messenger — Environment & Configuration Loader
 */

// Load .env file if available
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        list($key, $val) = explode('=', $line, 2) + [null, null];
        if ($key && $val !== null) {
            putenv(trim($key) . '=' . trim($val));
        }
    }
}


// Service URLs
if (!defined('NODE_API_URL')) define('NODE_API_URL', getenv('NODE_API_URL') ?: 'http://localhost:3001');
if (!defined('AUTH_API_URL')) define('AUTH_API_URL', getenv('AUTH_API_URL') ?: 'http://nextalk-auth');
if (!defined('APP_URL'))      define('APP_URL', getenv('APP_URL') ?: 'http://messenger');

// Smart WebSocket URL resolution (detects HTTPS / LocalTunnel automatically)
$isHttpsOrTunnel = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['HTTP_HOST']) && str_ends_with($_SERVER['HTTP_HOST'], '.loca.lt'))
    || (getenv('API_MODE') === 'tunnel');

$tunnelWs = getenv('TUNNEL_WS_URL') ?: 'wss://nextalk-dev.loca.lt';
$localWs  = getenv('WS_NODE_URL') ?: 'ws://127.0.0.1:3001';

if (!defined('WS_NODE_URL')) {
    define('WS_NODE_URL', $isHttpsOrTunnel ? $tunnelWs : $localWs);
}
