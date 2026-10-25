<?php
require_once __DIR__ . '/../src/Bootstrap.php';
Auth::requireLogin();

$userId = Auth::userId();
$otherId = (int)($_POST['user_id'] ?? 0);

if ($otherId <= 0 || $otherId == $userId) {
    header('Location: ../index.php');
    exit;
}

$payload = ['target_user_id' => $otherId];
$otherUsername = trim($_POST['username'] ?? '');
if ($otherUsername !== '') {
    $payload['target_username'] = $otherUsername;
}
$response = ApiClient::callNode('POST', '/chats', $payload);

if (isset($response['ok']) && $response['ok']) {
    $chatId = $response['chat_id'];
    header("Location: ../chat.php?id=$chatId");
} else {
    header('Location: ../index.php');
}
exit;
