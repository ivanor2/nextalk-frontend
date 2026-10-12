<?php
require_once __DIR__ . '/../src/Bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    Helpers::json([]);
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 1) {
    Helpers::json([]);
}

$response = ApiClient::callNode('GET', '/users/search?q=' . urlencode($q) . '&limit=10');

if (isset($response['ok']) && $response['ok']) {
    Helpers::json($response['users'] ?? []);
} else {
    Helpers::json([]);
}
