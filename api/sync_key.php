<?php
/**
 * NexTalk Messenger — E2EE Key Synchronization API
 */
require_once __DIR__ . '/../src/Bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json(['ok' => false, 'error' => 'Не авторизован'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $publicKey = trim($input['public_key'] ?? '');

    if (!$publicKey) {
        Helpers::json(['ok' => false, 'error' => 'public_key обязателен'], 400);
    }

    $response = callAuthApi('PUT', '/profile', ['public_key' => $publicKey], true);
    if (!empty($response['ok'])) {
        $_SESSION['public_key'] = $publicKey;
        Helpers::json(['ok' => true, 'public_key' => $publicKey]);
    } else {
        Helpers::json(['ok' => false, 'error' => $response['error'] ?? 'Ошибка синхронизации ключа'], 500);
    }
} elseif ($method === 'GET') {
    $targetId = (int)($_GET['user_id'] ?? 0);
    if ($targetId > 0) {
        $uResp = callAuthApi('GET', "/users/$targetId", null, true);
        if (!empty($uResp['ok']) && !empty($uResp['user'])) {
            Helpers::json([
                'ok' => true,
                'user_id' => $targetId,
                'public_key' => $uResp['user']['public_key'] ?? null,
                'username' => $uResp['user']['username'] ?? '',
                'display_name' => $uResp['user']['display_name'] ?? ''
            ]);
        } else {
            Helpers::json(['ok' => false, 'error' => 'Пользователь не найден'], 404);
        }
    } else {
        // Return current user's profile with public_key
        $myResp = callAuthApi('GET', '/profile', null, true);
        $myKey = $myResp['user']['public_key'] ?? null;
        Helpers::json(['ok' => true, 'public_key' => $myKey]);
    }
} else {
    Helpers::json(['ok' => false, 'error' => 'Метод не поддерживается'], 405);
}

