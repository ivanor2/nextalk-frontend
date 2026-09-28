<?php
require_once 'db.php';
requireLogin();

$userId = $_SESSION['user_id'];
$otherId = (int)($_POST['user_id'] ?? 0);

if ($otherId <= 0 || $otherId == $userId) {
    header('Location: index.php');
    exit;
}

$response = callNodeApi('POST', '/chats', [
    'target_user_id' => $otherId
]);

if (isset($response['ok']) && $response['ok']) {
    $chatId = $response['chat_id'];
    header("Location: chat.php?id=$chatId");
} else {
    // Fallback on error
    header('Location: index.php');
}
exit;