<?php
/**
 * NexTalk Messenger — Add Member to Group Chat API
 */
require_once __DIR__ . '/../src/Bootstrap.php';
Auth::requireLogin();

$userId = Auth::userId();
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$chatId = (int)($_POST['chat_id'] ?? 0);
$targetUserId = (int)($_POST['user_id'] ?? 0);
$targetUsername = trim($_POST['username'] ?? '');

if (!$chatId) {
    Helpers::json(['ok' => false, 'error' => 'chat_id required'], 400);
}

// If username given without ID, search user via Auth API
if ($targetUserId <= 0 && !empty($targetUsername)) {
    $cleanUname = ltrim($targetUsername, '@');
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
    Helpers::json(['ok' => false, 'error' => 'Пользователь не найден'], 404);
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
