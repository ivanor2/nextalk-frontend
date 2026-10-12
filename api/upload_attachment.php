<?php
require_once __DIR__ . '/../src/Bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    Helpers::json(['ok' => false, 'error' => 'Файл не передан'], 400);
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    Helpers::json(['ok' => false, 'error' => 'Ошибка загрузки файла (код: ' . $file['error'] . ')'], 400);
}

// 10 MB limit per TZ section 4.4 and 6.4
$maxBytes = 10 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    Helpers::json(['ok' => false, 'error' => 'Размер файла превышает лимит 10 МБ'], 400);
}

$uploadDir = dirname(__DIR__) . '/uploads/attachments';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

// Disallow dangerous executable extensions
$forbidden = ['php', 'phtml', 'php5', 'phar', 'exe', 'bat', 'cmd', 'sh', 'cgi', 'pl'];
if (in_array($ext, $forbidden)) {
    Helpers::json(['ok' => false, 'error' => 'Запрещённый тип файла'], 400);
}

$cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
$newFilename = 'att_' . bin2hex(random_bytes(8)) . '_' . time() . ($ext ? '.' . $ext : '');
$targetPath = $uploadDir . '/' . $newFilename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $targetPath);
    finfo_close($finfo);

    $isImage = str_starts_with($mime, 'image/') && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);

    Helpers::json([
        'ok' => true,
        'attachment' => [
            'filename' => $origName,
            'url'      => 'uploads/attachments/' . $newFilename,
            'size'     => $file['size'],
            'mime'     => $mime,
            'is_image' => $isImage
        ]
    ]);
} else {
    Helpers::json(['ok' => false, 'error' => 'Не удалось сохранить файл на сервере'], 500);
}
