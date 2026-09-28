<?php
require_once 'db.php';
$response = callNodeApi('POST', '/auth/login', [
    'username' => 'test1',
    'password' => '123456'
]);
echo json_encode($response);
