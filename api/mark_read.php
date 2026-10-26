<?php
require_once __DIR__ . '/../src/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json(['ok' => false, 'error' => 'Не авторизован'], 401);
}

$chatId = (int)($_POST['chat_id'] ?? $_GET['chat_id'] ?? 0);
if ($chatId <= 0) {
    Helpers::json(['ok' => false, 'error' => 'Invalid chat_id'], 400);
}

$response = ApiClient::callNode('POST', "/chats/$chatId/read");
Helpers::json($response);
