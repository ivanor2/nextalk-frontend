<?php
require_once __DIR__ . '/../src/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json(['ok' => false, 'error' => 'Не авторизован'], 401);
}

$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($chatId <= 0) {
    Helpers::json(['ok' => false, 'error' => 'Не указан chat_id'], 400);
}

$response = ApiClient::callNode('GET', "/messages?chat_id=$chatId&last_id=$lastId&limit=100");

if (isset($response['ok']) && $response['ok']) {
    Helpers::json([
        'ok' => true,
        'messages' => $response['messages'] ?? [],
        'count' => count($response['messages'] ?? [])
    ]);
} else {
    Helpers::json(['ok' => false, 'error' => $response['error'] ?? 'API Error'], 400);
}
