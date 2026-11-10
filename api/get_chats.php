<?php
require_once __DIR__ . '/../src/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json(['ok' => false, 'error' => 'Не авторизован'], 401);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$response = ApiClient::callNode('GET', '/chats');

if (isset($response['ok']) && $response['ok']) {
    $chats = $response['chats'] ?? [];
    if (!empty($chats) && $userId > 0) {
        LocalStore::saveChats($userId, $chats);
    }
    Helpers::json([
        'ok' => true,
        'chats' => $chats,
        'total_unread' => $response['total_unread'] ?? 0,
        'offline' => false
    ]);
} else {
    // Fallback на локальное хранилище при недоступности узла
    $cached = LocalStore::getChats($userId);
    Helpers::json([
        'ok' => true,
        'chats' => $cached,
        'total_unread' => 0,
        'offline' => true
    ]);
}
