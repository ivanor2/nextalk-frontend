<?php
require_once __DIR__ . '/../src/Bootstrap.php';
$r = callNodeApi('GET', '/health');
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
