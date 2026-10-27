<?php
/**
 * NexTalk Messenger — Avatar Delivery & Proxy
 * Safely serves avatar images across localhost, LAN, and tunnels
 */
require_once __DIR__ . '/../src/Bootstrap.php';

$path = trim($_GET['path'] ?? '');
if (empty($path)) {
    http_response_code(404);
    exit('Avatar not specified');
}

// Security: Prevent directory traversal
$filename = basename($path);
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowedExts = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp'
];

if (!isset($allowedExts[$ext])) {
    http_response_code(400);
    exit('Invalid file type');
}

$mime = $allowedExts[$ext];

// 1. Check local frontend uploads
$localFrontendPath = dirname(__DIR__) . '/uploads/avatars/' . $filename;
if (file_exists($localFrontendPath)) {
    header("Content-Type: $mime");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($localFrontendPath));
    readfile($localFrontendPath);
    exit;
}

// 2. Check local nextalk-auth uploads directory
$authDiskPath = 'C:/OSPanel/domains/nextalk-auth/uploads/avatars/' . $filename;
if (file_exists($authDiskPath)) {
    // Copy to frontend uploads for fast local serving next time
    $uploadDir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    @copy($authDiskPath, $localFrontendPath);

    header("Content-Type: $mime");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . filesize($authDiskPath));
    readfile($authDiskPath);
    exit;
}

// 3. Fallback: fetch from AUTH_API_URL
$remoteUrl = AUTH_API_URL . '/' . ltrim($path, '/');
$context = stream_context_create([
    'http' => [
        'timeout'       => 5,
        'ignore_errors' => true
    ]
]);
$content = @file_get_contents($remoteUrl, false, $context);

if ($content !== false && !empty($content)) {
    $uploadDir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    @file_put_contents($localFrontendPath, $content);

    header("Content-Type: $mime");
    header("Cache-Control: public, max-age=86400");
    header("Content-Length: " . strlen($content));
    echo $content;
    exit;
}

http_response_code(404);
exit('Avatar not found');
