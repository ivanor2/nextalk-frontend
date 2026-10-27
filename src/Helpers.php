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
        return 'api/avatar.php?path=' . urlencode(ltrim($path, '/'));
    }

    public static function renderAvatar(?string $avatarPath, ?string $name, string $sizeClass = '', string $extraClass = '', string $id = ''): string {
        $url = self::avatarUrl($avatarPath);
        $letter = self::avatarLetter($name);
        $classes = trim('avatar ' . $sizeClass . ' ' . $extraClass);
        $idAttr = $id ? ' id="' . self::e($id) . '"' : '';

        if ($url) {
            return '<div class="' . self::e($classes) . '"' . $idAttr . '><img src="' . self::e($url) . '" alt="' . self::e($name ?? '') . '" class="avatar-img"></div>';
        }
        return '<div class="' . self::e($classes) . '"' . $idAttr . '>' . $letter . '</div>';
    }

    public static function formatBytes(int $bytes): string {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, '.', '') . ' KB';
        return number_format($bytes / 1048576, 1, '.', '') . ' MB';
    }

    public static function renderMessageBubble(?string $content): string {
        $content = $content ?? '';
        $trimmed = trim($content);

        if (preg_match('/^\[attachment:(\{.*\})\]$/s', $trimmed, $matches)) {
            $data = json_decode($matches[1], true);
            if (is_array($data) && !empty($data['url'])) {
                $url = self::e($data['url']);
                $filename = self::e($data['filename'] ?? 'файл');
                $size = (int)($data['size'] ?? 0);
                $sizeStr = self::formatBytes($size);
                $isImage = !empty($data['is_image']);

                if ($isImage) {
                    return '<a href="' . $url . '" target="_blank" rel="noopener">' .
                           '<img src="' . $url . '" class="img-attachment" alt="' . $filename . '">' .
                           '</a>' .
                           '<div class="file-desc-sub">' . $filename . ' (' . $sizeStr . ')</div>';
                } else {
                    return '<a href="' . $url . '" target="_blank" download="' . $filename . '" class="file-attachment-card">' .
                           '<div class="file-icon">📄</div>' .
                           '<div class="file-details">' .
                           '<div class="file-name">' . $filename . '</div>' .
                           '<div class="file-size">' . $sizeStr . ' · Нажмите чтобы скачать</div>' .
                           '</div>' .
                           '</a>';
                }
            }
        }

        return nl2br(self::e($content));
    }

    public static function formatPreview(?string $content): string {
        if (!$content) return 'Нет сообщений';
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '[attachment:')) {
            if (preg_match('/^\[attachment:(\{.*\})\]$/s', $trimmed, $matches)) {
                $data = json_decode($matches[1], true);
                if (is_array($data) && !empty($data['filename'])) {
                    $icon = !empty($data['is_image']) ? '🖼️ ' : '📎 ';
                    return $icon . $data['filename'];
                }
            }
            return '📎 Вложение';
        }
        return $content;
    }

    public static function json($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
