<?php
/**
 * NexTalk Messenger — Add Member to Group Chat API
 */
require_once __DIR__ . '/../src/Bootstrap.php';
Auth::requireLogin();

$userId = Auth::userId();
$currentUsername = Auth::username();
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$chatId = (int)($_POST['chat_id'] ?? 0);
$targetUserId = (int)($_POST['user_id'] ?? 0);
$targetUsername = trim($_POST['username'] ?? '');

if (!$chatId) {
    if ($isAjax) {
        Helpers::json(['ok' => false, 'error' => 'chat_id required'], 400);
    }
    header('Location: ../index.php');
    exit;
}

if (empty($targetUsername) && $targetUserId <= 0) {
    $err = 'Укажите имя пользователя (@username)';
    if ($isAjax) {
        Helpers::json(['ok' => false, 'error' => $err], 400);
    }
    header("Location: ../chat.php?id={$chatId}&error=" . urlencode($err));
    exit;
}

// If username given without ID, search user via Auth API
if ($targetUserId <= 0 && !empty($targetUsername)) {
    $cleanUname = ltrim($targetUsername, '@');

    if (strcasecmp($cleanUname, $currentUsername) === 0 || $cleanUname == $userId) {
        $err = 'Вы уже являетесь участником этой группы';
        if ($isAjax) {
            Helpers::json(['ok' => false, 'error' => $err], 400);
        }
        header("Location: ../chat.php?id={$chatId}&error=" . urlencode($err));
        exit;
    }

    $search = callAuthApi('GET', '/users/search?q=' . urlencode($cleanUname), null, true);
    if (!empty($search['ok']) && !empty($search['users'])) {
        foreach ($search['users'] as $u) {
            if (strcasecmp($u['username'], $cleanUname) === 0) {
                $targetUserId = (int)$u['id'];
                $targetUsername = $u['username'];
                break;
            }
        }
    }
}

if ($targetUserId <= 0) {
    $err = 'Пользователь не найден';
    if ($isAjax) {
        Helpers::json(['ok' => false, 'error' => $err], 404);
    }
    header("Location: ../chat.php?id={$chatId}&error=" . urlencode($err));
    exit;
}

if ($targetUserId === $userId) {
    $err = 'Вы уже являетесь участником этой группы';
    if ($isAjax) {
        Helpers::json(['ok' => false, 'error' => $err], 400);
    }
    header("Location: ../chat.php?id={$chatId}&error=" . urlencode($err));
    exit;
}

// Call node API to add member
$resp = ApiClient::callNode('POST', "/chats/{$chatId}/members", [
    'user_id' => $targetUserId,
    'username' => $targetUsername
]);

if (!empty($resp['ok'])) {
    if ($isAjax) {
        Helpers::json(['ok' => true, 'user_id' => $targetUserId, 'username' => $targetUsername]);
    }
    header("Location: ../chat.php?id={$chatId}");
    exit;
}

$errMsg = $resp['error'] ?? 'Не удалось добавить участника';
if ($isAjax) {
    Helpers::json(['ok' => false, 'error' => $errMsg], 400);
}
header("Location: ../chat.php?id={$chatId}&error=" . urlencode($errMsg));
exit;
