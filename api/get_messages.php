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
    $msgs = $response['messages'] ?? [];
    if (!empty($msgs)) {
        LocalStore::saveMessages($chatId, $msgs);
    }
    Helpers::json([
        'ok' => true,
        'messages' => $msgs,
        'count' => count($msgs),
        'offline' => false
    ]);
} else {
    // Fallback на локальное хранилище, если узел недоступен
    $cached = LocalStore::getMessagesAfter($chatId, $lastId, 100);
    Helpers::json([
        'ok' => true,
        'messages' => $cached,
        'count' => count($cached),
        'offline' => true
    ]);
}
