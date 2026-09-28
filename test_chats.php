<?php
require_once 'db.php';
// Mock session for test
$_SESSION['user_id'] = 6;
$_SESSION['username'] = 'test_search';
$_SESSION['jwt_token'] = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0ZXN0X3NlYXJjaCIsInVzZXJfaWQiOjYsImlhdCI6MTc5MDYwODU0NSwiZXhwIjoxNzkxMjEzMzQ1LCJpc3MiOiJuZXh0YWxrLWF1dGgifQ.F2-3_L3F7E5u4q24n8fC-7L8p1_H11x7Y57F-5M-E9E';

$response = callNodeApi('POST', '/chats', ['target_user_id' => 3]);
echo json_encode($response);
