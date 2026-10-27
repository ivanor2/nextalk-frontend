<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$success = '';
$error = '';

// Загрузка профиля
$profile = callAuthApi('GET', '/profile', null, true);
$user = $profile['user'] ?? ['username' => $username, 'display_name' => $username, 'email' => '', 'status_text' => '', 'avatar_path' => null];

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $response = callAuthApi('PUT', '/profile', [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'status_text' => trim($_POST['status_text'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
        ], true);

        if (isset($response['ok']) && $response['ok']) {
            $success = 'Профиль обновлён';
            // Refresh profile data
            $profile = callAuthApi('GET', '/profile', null, true);
            $user = $profile['user'] ?? $user;
            $_SESSION['display_name'] = $user['display_name'] ?? $username;
        } else {
            $error = $response['error'] ?? 'Ошибка обновления';
        }
    }

    if ($action === 'change_password') {
        $response = callAuthApi('POST', '/profile/password', [
            'current_password' => $_POST['current_password'] ?? '',
            'new_password' => $_POST['new_password'] ?? '',
        ], true);

        if (isset($response['ok']) && $response['ok']) {
            $success = 'Пароль успешно изменён';
        } else {
            $error = $response['error'] ?? 'Ошибка смены пароля';
        }
    }

    if ($action === 'upload_avatar' && !empty($_FILES['avatar']['tmp_name'])) {
        $response = callAuthApiUpload('/profile/avatar', ['avatar' => $_FILES['avatar']]);
        if (isset($response['ok']) && $response['ok']) {
            $user['avatar_path'] = $response['avatar_path'];
            $_SESSION['avatar_path'] = $response['avatar_path'];

            // Also save locally in frontend uploads directory
            $destDir = __DIR__ . '/uploads/avatars';
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            $targetFile = $destDir . '/' . basename($response['avatar_path']);
            @copy($_FILES['avatar']['tmp_name'], $targetFile);

            $avatarUrl = Helpers::avatarUrl($user['avatar_path']);

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'avatar_path' => $response['avatar_path'],
                    'avatar_url' => $avatarUrl
                ]);
                exit;
            }
            $success = 'Аватар обновлён';
        } else {
            $err = $response['error'] ?? 'Ошибка загрузки аватара';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $err]);
                exit;
            }
            $error = $err;
        }
    }
}

$avatarUrl = Helpers::avatarUrl($user['avatar_path'] ?? null);
if (!empty($user['avatar_path'])) {
    $_SESSION['avatar_path'] = $user['avatar_path'];
}
$initial = mb_strtoupper(mb_substr($user['display_name'] ?: $username, 0, 1));
$defaultNode = defined('WS_NODE_URL') ? WS_NODE_URL : 'ws://127.0.0.1:3001';

$pageTitle = 'NexTalk — Профиль';
$metaDescription = 'Настройки профиля в NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">
    <?php
    $activeTab = 'profile';
    require __DIR__ . '/templates/partials/sidebar.php';
    ?>

    <!-- MAIN AREA -->
    <main class="chat-area overflow-y-auto">
        <div class="page-content">
            <div class="page-header">
                <h1 class="page-title">👤 Профиль</h1>
                <p class="page-subtitle">Управляйте своей учётной записью</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Avatar section -->
            <div class="settings-card">
                <h3 class="card-title">Аватар</h3>
                <div class="avatar-section">
                    <div class="profile-avatar-large">
                        <?php if ($avatarUrl): ?>
                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Аватар">
                        <?php else: ?>
                            <?= $initial ?>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="avatar-upload">
                        <input type="hidden" name="action" value="upload_avatar">
                        <label for="avatarFile" class="btn btn-ghost cursor-pointer">
                            📷 Выбрать фото
                        </label>
                        <input type="file" id="avatarFile" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
                        <button type="submit" class="btn btn-primary d-none" id="avatarSubmit">Загрузить</button>
                        <span class="field-hint">JPEG, PNG, WebP, GIF · до 2 МБ</span>
                    </form>
                </div>
            </div>

            <!-- Profile info -->
            <div class="settings-card">
                <h3 class="card-title">Личные данные</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="field">
                        <label>Логин</label>
                        <input type="text" value="<?= htmlspecialchars($username) ?>" disabled class="disabled-input">
                        <div class="field-hint">Логин нельзя изменить</div>
                    </div>

                    <div class="field">
                        <label for="display_name">Отображаемое имя</label>
                        <input type="text" id="display_name" name="display_name" maxlength="64"
                               value="<?= htmlspecialchars($user['display_name'] ?? '') ?>"
                               placeholder="Как вас называть">
                    </div>

                    <div class="field">
                        <label for="status_text">Статус</label>
                        <input type="text" id="status_text" name="status_text" maxlength="100"
                               value="<?= htmlspecialchars($user['status_text'] ?? '') ?>"
                               placeholder="Расскажите о себе (до 100 символов)">
                        <div class="field-hint"><span id="statusCounter"><?= mb_strlen($user['status_text'] ?? '') ?></span>/100</div>
                    </div>

                    <div class="field">
                        <label for="profile_email">E-mail</label>
                        <input type="email" id="profile_email" name="email"
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                               placeholder="user@example.com">
                    </div>

                    <button type="submit" class="btn-submit btn-submit-max-300">Сохранить изменения</button>
                </form>
            </div>

            <!-- Change password -->
            <div class="settings-card">
                <h3 class="card-title">🔒 Смена пароля</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">

                    <div class="field">
                        <label for="current_password">Текущий пароль</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>

                    <div class="field">
                        <label for="new_password">Новый пароль</label>
                        <input type="password" id="new_password" name="new_password" required
                               minlength="10" placeholder="Минимум 10 символов, буквы + цифры">
                    </div>

                    <div class="field">
                        <label for="new_password_confirm">Повторите новый пароль</label>
                        <input type="password" id="new_password_confirm" required>
                    </div>

                    <button type="submit" class="btn-submit btn-submit-max-300 btn-warning-flat">Изменить пароль</button>
                </form>
            </div>

            <!-- Account info (read-only) -->
            <div class="settings-card">
                <h3 class="card-title">📊 Информация об аккаунте</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">ID</div>
                        <div class="info-value"><?= $userId ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Роль</div>
                        <div class="info-value"><?= ($user['role'] ?? 'user') === 'admin' ? 'Администратор' : 'Пользователь' ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Дата регистрации</div>
                        <div class="info-value"><?= isset($user['created_at']) ? date('d.m.Y', strtotime($user['created_at'])) : '—' ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Последний вход</div>
                        <div class="info-value"><?= isset($user['last_seen']) ? date('d.m.Y H:i', strtotime($user['last_seen'])) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script src="assets/js/app.js"></script>
<script src="assets/js/profile.js"></script>
</body>
</html>
