<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Load nodes for home node selection
$nodesResp = callAuthApi('GET', '/nodes?status=active');
$nodes = (isset($nodesResp['ok']) && $nodesResp['ok']) ? ($nodesResp['nodes'] ?? []) : [];

// Load profile
$profileResp = callAuthApi('GET', '/profile', null, true);
$user = $profileResp['user'] ?? [];
$defaultNode = defined('WS_NODE_URL') ? WS_NODE_URL : 'ws://127.0.0.1:3001';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_node') {
        $nodeId = (int)($_POST['home_node_id'] ?? 0);
        $resp = callAuthApi('PUT', '/profile', ['home_node_id' => $nodeId ?: null], true);
        if (isset($resp['ok']) && $resp['ok']) {
            $success = 'Домашний узел обновлён';
        } else {
            $error = $resp['error'] ?? 'Ошибка';
        }
    }
}
$pageTitle = 'NexTalk — Настройки';
$metaDescription = 'Настройки NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">
    <?php
    $activeTab = 'settings';
    require __DIR__ . '/templates/partials/sidebar.php';
    ?>

    <main class="chat-area overflow-y-auto">
        <div class="page-content">
            <div class="page-header">
                <h1 class="page-title">⚙️ Настройки</h1>
                <p class="page-subtitle">Настройте мессенджер под себя</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Theme -->
            <div class="settings-card">
                <h3 class="card-title">🎨 Тема оформления</h3>
                <div class="theme-switcher">
                    <button class="theme-option" data-theme="dark" id="themeDark">
                        <div class="theme-preview theme-preview-dark"></div>
                        <span>Тёмная</span>
                    </button>
                    <button class="theme-option" data-theme="light" id="themeLight">
                        <div class="theme-preview theme-preview-light"></div>
                        <span>Светлая</span>
                    </button>
                    <button class="theme-option" data-theme="system" id="themeSystem">
                        <div class="theme-preview theme-preview-system"></div>
                        <span>Системная</span>
                    </button>
                </div>
            </div>

            <!-- Home node -->
            <div class="settings-card">
                <h3 class="card-title">🌐 Домашний узел</h3>
                <p class="card-desc">
                    Узел, через который передаются ваши сообщения. Выберите ближайший или создайте свой.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="change_node">
                    <div class="field">
                        <select name="home_node_id" class="select-field">
                            <?php foreach ($nodes as $node): ?>
                                <option value="<?= $node['id'] ?>" <?= ($user['home_node_id'] ?? 0) == $node['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($node['name']) ?>
                                    — <?= htmlspecialchars($node['url'] ?: $node['ws_url']) ?>
                                    <?= $node['online'] ? '● онлайн' : '○ офлайн' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-submit btn-submit-max-300">Сохранить узел</button>
                </form>
            </div>

            <!-- Notifications -->
            <div class="settings-card">
                <h3 class="card-title">🔔 Уведомления</h3>
                <div class="settings-toggle-list">
                    <div class="settings-toggle-item">
                        <div>
                            <div class="toggle-label">Уведомления браузера</div>
                            <div class="toggle-desc">Получайте push-уведомления о новых сообщениях</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="notifToggle">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle-item">
                        <div>
                            <div class="toggle-label">Звук уведомлений</div>
                            <div class="toggle-desc">Звуковой сигнал при новом сообщении</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="soundToggle" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="settings-toggle-item">
                        <div>
                            <div class="toggle-label">Показывать превью</div>
                            <div class="toggle-desc">Текст сообщения в уведомлении</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="previewToggle" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Encryption keys -->
            <div class="settings-card">
                <h3 class="card-title">🔐 Ключи шифрования</h3>
                <p class="card-desc">
                    Ваши ключи E2E-шифрования хранятся только на вашем устройстве. Экспортируйте резервную копию для использования на другом устройстве.
                </p>
                <div class="flex-wrap-gap">
                    <button class="btn btn-primary btn-pad-md" id="exportKeysBtn">📤 Экспорт ключей</button>
                    <button class="btn btn-ghost btn-pad-md" id="importKeysBtn">📥 Импорт ключей</button>
                    <button class="btn btn-ghost btn-pad-md text-warning" id="generateKeysBtn">🔄 Сгенерировать новые</button>
                </div>
                <input type="file" id="importKeysFile" accept=".json" class="d-none">
                <div id="keysStatus" class="keys-status"></div>
            </div>

            <!-- Data -->
            <div class="settings-card">
                <h3 class="card-title">💾 Данные</h3>
                <div class="settings-toggle-list">
                    <div class="settings-toggle-item">
                        <div>
                            <div class="toggle-label">Очистить кэш</div>
                            <div class="toggle-desc">Удалить сохранённые данные в браузере</div>
                        </div>
                        <button class="btn btn-ghost btn-sm-danger" id="clearCacheBtn">Очистить</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script src="assets/js/app.js"></script>
<script src="assets/js/settings.js"></script>
</body>
</html>
