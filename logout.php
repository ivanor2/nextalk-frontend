<?php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")
        ->execute([$_SESSION['user_id']]);
}
session_destroy();
header('Location: login.php');
exit;