<?php
require_once __DIR__ . '/src/Bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($username && $password) {
        // По ТЗ раздел 6.6: узел проксирует запрос на сервер авторизации
        $response = callNodeApi('POST', '/auth/login', [
            'username' => $username,
            'password' => $password,
            'remember' => $remember,
        ]);

        // Если узел оффлайн или не отвечает, обращаемся напрямую к auth-серверу
        if (!isset($response['ok']) || !$response['ok']) {
            $directResp = callAuthApi('POST', '/auth/login', [
                'username' => $username,
                'password' => $password,
                'remember' => $remember,
            ]);
            if (isset($directResp['ok']) && $directResp['ok']) {
                $response = $directResp;
            }
        }

        if (isset($response['ok']) && $response['ok']) {
            $_SESSION['user_id'] = $response['user_id'];
            $_SESSION['username'] = $response['username'];
            $_SESSION['display_name'] = $response['display_name'] ?? $response['username'];
            $_SESSION['jwt_token'] = $response['token'];

            if ($remember) {
                $lifetime = $response['expires_in'] ?? 86400 * 28;
                session_set_cookie_params($lifetime);
                ini_set('session.gc_maxlifetime', $lifetime);
            }

            header('Location: index.php');
            exit;
        } else {
            $error = $response['error'] ?? 'Неверный логин или пароль';
        }
    } else {
        $error = 'Введите логин и пароль';
    }
}
$pageTitle = 'NexTalk — Вход';
$metaDescription = 'Войдите в NexTalk — децентрализованный мессенджер с E2E-шифрованием';
$bodyClass = 'auth-body';
require __DIR__ . '/templates/partials/head.php';
?>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon">💬</div>
            <div class="logo-text">Nex<em>Talk</em></div>
        </div>

        <h2>Вход в аккаунт</h2>
        <p class="auth-subtitle">Введите логин или e-mail и пароль</p>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="field">
                <label for="username">Логин или E-mail</label>
                <input type="text" id="username" name="username" required
                       placeholder="my_login или user@example.com"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username">
            </div>

            <div class="field">
                <label for="password">Пароль</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" required
                           placeholder="Введите пароль"
                           autocomplete="current-password">
                    <button type="button" class="password-toggle" id="togglePass" tabindex="-1">👁</button>
                </div>
            </div>

            <div class="field-check mb-8">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Запомнить устройство</label>
            </div>

            <button type="submit" class="btn-submit">Войти</button>
        </form>

        <div class="auth-switch mb-12">
            <a href="#" id="forgotLink" class="fs-13">Забыли пароль?</a>
        </div>

        <div class="auth-switch">
            Нет аккаунта? <a href="register.php">Зарегистрироваться</a>
        </div>
    </div>

    <!-- Forgot password modal -->
    <div class="modal-overlay" id="forgotModal">
        <div class="modal modal-max-sm">
            <button class="icon-btn modal-close" id="closeForgot">✕</button>
            <h3>🔑 Восстановление доступа</h3>
            <p class="card-desc-lg">
                Введите e-mail, указанный при регистрации. Мы отправим инструкции по восстановлению пароля.
            </p>
            <div class="field">
                <label for="forgotEmail">E-mail</label>
                <input type="email" id="forgotEmail" placeholder="user@example.com">
            </div>
            <button class="btn-submit mb-0" id="forgotSubmit">Отправить</button>
            <div id="forgotResult" class="mt-12 fs-13 text-center"></div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script src="assets/js/auth.js"></script>
</body>
</html>