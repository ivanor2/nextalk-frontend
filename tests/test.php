<?php
require_once __DIR__ . '/../src/Bootstrap.php';
$response = callNodeApi('POST', '/auth/login', [
    'username' => 'test1',
    'password' => '123456'
]);
echo json_encode($response);
