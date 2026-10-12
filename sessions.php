<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$displayName = $_SESSION['display_name'] ?? $username;

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_session') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $resp = callAuthApi('POST', '/sessions/delete', ['session_id' => $sessionId], true);
        if (isset($resp['ok']) && $resp['ok']) {
            $success = 'Сеанс успешно завершён';
        } else {
            $error = $resp['error'] ?? 'Не удалось завершить сеанс';
        }
    }

    if ($action === 'delete_others') {
        $resp = callAuthApi('POST', '/sessions/delete-others', [], true);
        if (isset($resp['ok']) && $resp['ok']) {
            $success = 'Все остальные сеансы завершены';
        } else {
            $error = $resp['error'] ?? 'Ошибка при завершении сеансов';
        }
    }
}

// Fetch active sessions
$sessionsResp = callAuthApi('GET', '/sessions', null, true);
$sessions = [];
if (isset($sessionsResp['ok']) && $sessionsResp['ok']) {
    $sessions = $sessionsResp['sessions'] ?? [];
}

// If no sessions returned from DB yet, synthesize current session info
if (empty($sessions)) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $sessions = [
        [
            'id' => 1,
            'device' => $ua,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400 * 30),
            'current' => true
        ]
    ];
}

function parseDeviceName($ua) {
    if (empty($ua)) return ['icon' => '💻', 'name' => 'Веб-браузер', 'os' => 'Устройство'];
    $icon = '💻';
    $browser = 'Браузер';
    $os = 'Система';

    if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false) {
        $icon = '📱';
    }

    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) $browser = 'Google Chrome';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Mozilla Firefox';
    elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Apple Safari';
    elseif (stripos($ua, 'Edg') !== false) $browser = 'Microsoft Edge';
    elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) $browser = 'Opera';

    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';

    return ['icon' => $icon, 'name' => $browser, 'os' => $os];
}
$pageTitle = 'NexTalk — Активные сеансы';
$metaDescription = 'Управление активными сеансами и устройствами в NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">
    <?php
    $activeTab = 'sessions';
    require __DIR__ . '/templates/partials/sidebar.php';
    ?>

    <!-- MAIN AREA -->
    <main class="chat-area overflow-y-auto">
        <div class="page-content">
            <div class="page-header flex-between-wrap">
                <div>
                    <h1 class="page-title">📱 Активные сеансы</h1>
                    <p class="page-subtitle">Устройства, с которых выполнен вход в ваш аккаунт</p>
                </div>
                <?php if (count($sessions) > 1): ?>
                    <form method="POST" onsubmit="return confirm('Вы уверены, что хотите завершить все сеансы кроме текущего?');">
                        <input type="hidden" name="action" value="delete_others">
                        <button type="submit" class="btn btn-ghost btn-outline-danger">
                            🛑 Завершить все остальные
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Sessions list -->
            <div class="settings-card">
                <h3 class="card-title">Список устройств</h3>
                <div class="flex-col-gap-12">
                    <?php foreach ($sessions as $s): ?>
                        <?php
                            $devInfo = parseDeviceName($s['device'] ?? '');
                            $isCurrent = !empty($s['current']);
                            $createdTime = !empty($s['created_at']) ? date('d.m.Y H:i', strtotime($s['created_at'])) : '—';
                        ?>
                        <div class="user-card <?= $isCurrent ? 'user-card-current' : '' ?>">
                            <div class="user-card-left">
                                <div class="session-device-icon">
                                    <?= $devInfo['icon'] ?>
                                </div>
                                <div>
                                    <div class="user-card-name flex-row-gap-8">
                                        <?= htmlspecialchars($devInfo['name']) ?> (<?= htmlspecialchars($devInfo['os']) ?>)
                                        <?php if ($isCurrent): ?>
                                            <span class="badge-status badge-active">Это устройство</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-card-sub mt-4">
                                        IP: <strong><?= htmlspecialchars($s['ip'] ?? '127.0.0.1') ?></strong>
                                        · Вход: <?= $createdTime ?>
                                    </div>
                                </div>
                            </div>
                            <div class="user-card-actions">
                                <?php if (!$isCurrent): ?>
                                    <form method="POST" onsubmit="return confirm('Завершить этот сеанс?');" class="m-0">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm-danger">
                                            Завершить
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="session-online-text">● Онлайн</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Security info card -->
            <div class="settings-card">
                <h3 class="card-title">🛡️ Безопасность учётной записи</h3>
                <p class="card-desc mb-12">
                    Каждая сессия защищена уникальным токеном авторизации. Если вы заметили подозрительное устройство в списке, немедленно завершите сеанс и измените пароль в разделе <a href="profile.php" class="link-accent-underline">Профиль</a>.
                </p>
                <div class="text-muted-sm">
                    Срок действия сессии автоматически продлевается при активном использовании мессенджера.
                </div>
            </div>

        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script src="assets/js/app.js"></script>
</body>
</html>
