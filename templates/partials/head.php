<?php
$pageTitle = $pageTitle ?? 'NexTalk — Децентрализованный мессенджер';
$metaDescription = $metaDescription ?? 'NexTalk — безопасный децентрализованный мессенджер с E2E-шифрованием';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '') ?>">
