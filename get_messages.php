<?php
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Не авторизован']);
    exit;
}

$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($chatId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Не указан chat_id']);
    exit;
}

$response = callNodeApi('GET', "/messages?chat_id=$chatId&last_id=$lastId&limit=100");

if (isset($response['ok']) && $response['ok']) {
    echo json_encode([
        'ok' => true,
        'messages' => $response['messages'],
        'count' => count($response['messages'])
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'error' => $response['error'] ?? 'API Error']);
}