<?php
session_start();

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    define('NODE_API_URL', $env['NODE_API_URL'] ?? 'http://127.0.0.1:3001/api');
} else {
    define('NODE_API_URL', 'http://127.0.0.1:3001/api'); // fallback
}

function callNodeApi($method, $endpoint, $data = null) {
    $url = NODE_API_URL . $endpoint;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => $method,
            'ignore_errors' => true
        ]
    ];
    if (isset($_SESSION['jwt_token'])) {
        $options['http']['header'] .= "Authorization: Bearer " . $_SESSION['jwt_token'] . "\r\n";
    }
    if ($data !== null) {
        $options['http']['content'] = json_encode($data);
    }
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    if ($result === false) return ['ok' => false, 'error' => 'Node offline'];
    return json_decode($result, true);
}

function requireLogin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['jwt_token'])) {
        header('Location: login.php');
        exit;
    }
}