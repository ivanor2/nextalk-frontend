<?php
require_once __DIR__ . '/src/Bootstrap.php';

$isAuth = isset($_SESSION['user_id']);
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$displayName = $_SESSION['display_name'] ?? $username;

$success = '';
$error = '';

// Handle node selection for authorized users
if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_node_id'])) {
    $nodeId = (int)$_POST['select_node_id'];
    $resp = callAuthApi('PUT', '/profile', ['home_node_id' => $nodeId], true);
    if (isset($resp['ok']) && $resp['ok']) {
        $success = 'Домашний узел успешно изменён!';
    } else {
        $error = $resp['error'] ?? 'Не удалось сменить домашний узел';
    }
}

// Fetch nodes from auth server
$nodesResp = callAuthApi('GET', '/nodes');
$nodes = [];
if (isset($nodesResp['ok']) && $nodesResp['ok']) {
    $nodes = $nodesResp['nodes'] ?? [];
}

// Fallback nodes if registry is empty
if (empty($nodes)) {
    $nodes = [
        [
            'id' => 1,
            'name' => 'Основной узел сети',
            'url' => 'https://nextalk-dev.loca.lt',
            'ws_url' => 'wss://nextalk-dev.loca.lt',
            'status' => 'active',
            'online' => true,
            'users_count' => 142,
            'admin_email' => 'admin@nextalk.net',
            'public_key' => 'RSA_PUB_KEY_DEMO_NODE_01'
        ],
        [
            'id' => 2,
            'name' => 'Локальный узел (Localhost)',
            'url' => 'http://127.0.0.1:3001',
            'ws_url' => 'ws://127.0.0.1:3001',
            'status' => 'active',
            'online' => true,
            'users_count' => 18,
            'admin_email' => 'local@nextalk.net',
            'public_key' => 'RSA_PUB_KEY_DEMO_NODE_02'
        ],
        [
            'id' => 3,
            'name' => 'Университетский кампус',
            'url' => 'https://campus.nextalk.net',
            'ws_url' => 'wss://campus.nextalk.net',
            'status' => 'pending',
            'online' => false,
            'users_count' => 35,
            'admin_email' => 'campus@edu.ru',
            'public_key' => 'RSA_PUB_KEY_DEMO_NODE_03'
        ]
    ];
}
$pageTitle = 'NexTalk — Каталог узлов сети';
$metaDescription = 'Реестр публичных и независимых узлов передачи сообщений сети NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="<?= $isAuth ? 'app' : '' ?>" id="app">

    <?php if ($isAuth): ?>
        <?php
        $activeTab = 'nodes';
        require __DIR__ . '/templates/partials/sidebar.php';
        ?>
    <?php else: ?>
        <!-- PUBLIC NAV -->
        <header class="nav">
            <a href="landing.html" class="nav-logo text-decoration-none">
                <div class="logo-icon">💬</div>
                <span>Nex<em>Talk</em></span>
            </a>
            <div class="nav-links">
                <a href="landing.html" class="nav-link">Главная</a>
                <a href="nodes.php" class="nav-link nav-link-active">Каталог узлов</a>
                <a href="install.php" class="nav-link">Установка узла</a>
                <a href="rules.php" class="nav-link">Правила</a>
                <a href="login.php" class="btn btn-ghost ml-8">Войти</a>
                <a href="register.php" class="btn btn-primary">Регистрация</a>
                <button class="theme-toggle ml-8" id="themeTogglePub" title="Сменить тему">🌙</button>
            </div>
        </header>

        <div class="pt-100"></div>
    <?php endif; ?>

    <!-- MAIN AREA -->
    <main class="<?= $isAuth ? 'chat-area' : '' ?> overflow-y-auto flex-1">
        <div class="page-content <?= !$isAuth ? 'page-content-public' : '' ?>">
            
            <div class="page-header flex-between-wrap">
                <div>
                    <h1 class="page-title">🌐 Каталог узлов сети NexTalk</h1>
                    <p class="page-subtitle">Независимые серверы передачи сообщений, подключённые к единой федеративной сети</p>
                </div>
                <div>
                    <a href="install.php" class="btn btn-primary">
                        🚀 Установить свой узел
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Search and Filter Bar -->
            <div class="settings-card p-16-20">
                <div class="flex-between-wrap align-center">
                    <div class="flex-1-min-260 rel">
                        <input
                            type="text"
                            id="nodeSearchInput"
                            placeholder="🔍 Поиск по названию или адресу узла..."
                            class="input-search-node"
                        >
                    </div>
                    <div class="flex-row-gap-8">
                        <span class="text-muted-sm">Статус:</span>
                        <select id="nodeStatusFilter" class="select-field select-auto-sm">
                            <option value="all">Все узлы</option>
                            <option value="active" selected>Активные (онлайн)</option>
                            <option value="pending">На проверке</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Nodes Table -->
            <div class="table-wrap">
                <table class="data-table" id="nodesTable">
                    <thead>
                        <tr>
                            <th>Узел</th>
                            <th>Адрес</th>
                            <th>Статус</th>
                            <th>Пользователи</th>
                            <th>Пинг</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody id="nodesTbody">
                        <?php foreach ($nodes as $n): ?>
                            <?php
                                $statusClass = ($n['status'] === 'active' && ($n['online'] ?? true)) ? 'badge-active' : (($n['status'] === 'pending') ? 'badge-pending' : 'badge-blocked');
                                $statusText = ($n['status'] === 'active' && ($n['online'] ?? true)) ? '● Активен' : (($n['status'] === 'pending') ? '○ На проверке' : '● Офлайн');
                                $addr = $n['ws_url'] ?: ($n['url'] ?? '');
                            ?>
                            <tr class="node-row" data-status="<?= htmlspecialchars($n['status']) ?>" data-search="<?= htmlspecialchars(mb_strtolower($n['name'] . ' ' . $addr)) ?>">
                                <td>
                                    <div class="node-name-title">
                                        <?= htmlspecialchars($n['name']) ?>
                                    </div>
                                    <div class="node-key-mono">
                                        <?= htmlspecialchars(mb_substr($n['public_key'] ?? '', 0, 24)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <code class="code-tag">
                                        <?= htmlspecialchars($addr) ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge-status <?= $statusClass ?>">
                                        <?= $statusText ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-weight-600"><?= (int)($n['users_count'] ?? 0) ?></span>
                                    <span class="text-muted-12"> польз.</span>
                                </td>
                                <td>
                                    <span class="ping-badge text-muted-12" data-url="<?= htmlspecialchars($n['url'] ?? $addr) ?>">
                                        ...
                                    </span>
                                </td>
                                <td>
                                    <div class="flex-row-gap-6">
                                        <button
                                            class="btn btn-ghost connect-node-btn btn-sm-12"
                                            data-ws="<?= htmlspecialchars($addr) ?>"
                                            title="Подключиться к этому узлу в мессенджере"
                                        >
                                            Подключить
                                        </button>
                                        <?php if ($isAuth): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="select_node_id" value="<?= (int)$n['id'] ?>">
                                                <button type="submit" class="btn btn-primary btn-sm-12" title="Сделать домашним узлом">
                                                    ★ Домашний
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Bottom Information Callout -->
            <div class="settings-card card-accent-info">
                <h3 class="card-title text-accent-light">💡 Как работают узлы в NexTalk</h3>
                <p class="card-body-primary">
                    В отличие от обычных мессенджеров, в NexTalk серверы децентрализованы. Вы можете общаться с собеседниками, даже если они зарегистрированы на других узлах. Все сообщения между клиентами шифруются сквозным шифрованием (E2EE), поэтому владельцы узлов видят только зашифрованные данные.
                </p>
                <div class="mt-14">
                    <a href="install.php" class="link-accent-underline font-weight-600 text-accent-light">
                        Узнать подробнее и развернуть свой собственный узел →
                    </a>
                </div>
            </div>

        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script src="assets/js/app.js"></script>
<script src="assets/js/nodes.js"></script>
</body>
</html>
