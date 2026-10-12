<?php
/**
 * NexTalk Messenger — Presentation & Format Helpers
 */

class Helpers {
    public static function e(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function avatarLetter(?string $name): string {
        $name = trim($name ?? '');
        if ($name === '') return '?';
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }

    public static function avatarUrl(?string $path): ?string {
        if (!$path) return null;
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }
        return AUTH_API_URL . '/' . ltrim($path, '/');
    }

    public static function json($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
