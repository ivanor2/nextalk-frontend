<?php
/**
 * NexTalk Messenger — Authentication & Session Management
 */

class Auth {
    public static function check(): bool {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['jwt_token']);
    }

    public static function requireLogin(): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function requireGuest(): void {
        if (self::check()) {
            header('Location: index.php');
            exit;
        }
    }

    public static function userId(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function username(): ?string {
        return $_SESSION['username'] ?? null;
    }

    public static function displayName(): ?string {
        return $_SESSION['display_name'] ?? $_SESSION['username'] ?? null;
    }

    public static function token(): ?string {
        return $_SESSION['jwt_token'] ?? null;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
