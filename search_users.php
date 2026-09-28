<?php
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$response = callNodeApi('GET', '/users/search?q=' . urlencode($q) . '&limit=10');

if (isset($response['ok']) && $response['ok']) {
    echo json_encode($response['users'], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([]);
}