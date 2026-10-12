<?php
/**
 * NexTalk Messenger — Application Bootstrap
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ApiClient.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Helpers.php';

// ── Backward-Compatibility Procedural Functions ───────────

function callNodeApi(string $method, string $endpoint, ?array $data = null): array {
    return ApiClient::callNode($method, $endpoint, $data);
}

function callAuthApi(string $method, string $endpoint, ?array $data = null, bool $useToken = false): array {
    return ApiClient::callAuth($method, $endpoint, $data, $useToken);
}

function callAuthApiUpload(string $endpoint, array $files, bool $useToken = true): array {
    return ApiClient::callAuthUpload($endpoint, $files, $useToken);
}

function requireLogin(): void {
    Auth::requireLogin();
}

function requireGuest(): void {
    Auth::requireGuest();
}
