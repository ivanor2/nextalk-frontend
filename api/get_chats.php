<?php
require_once __DIR__ . '/../src/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json(['ok' => false, 'error' => 'Не авторизован'], 401);
}

$response = ApiClient::callNode('GET', '/chats');
if (isset($response['ok']) && $response['ok']) {
    Helpers::json([
        'ok' => true,
        'chats' => $response['chats'] ?? [],
        'total_unread' => $response['total_unread'] ?? 0
    ]);
} else {
    Helpers::json(['ok' => false, 'error' => $response['error'] ?? 'API Error'], 400);
}
