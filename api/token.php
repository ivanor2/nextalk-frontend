<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['jwt_token'])) {
    echo json_encode([
        'ok' => true,
        'token' => $_SESSION['jwt_token'],
        'source' => 'session'
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'error' => 'No token in session'
    ]);
}
