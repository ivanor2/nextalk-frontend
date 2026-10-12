<?php
require_once __DIR__ . '/src/Bootstrap.php';

$isAuth = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? null;
$displayName = $_SESSION['display_name'] ?? $username;

$success = '';
$error = '';

// Handle application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $publicKey = trim($_POST['public_key'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($url) || empty($name) || empty($email) || empty($publicKey)) {
        $error = 'Пожалуйста, заполните все обязательные поля заявки.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Укажите корректный контактный e-mail.';
    } else {
        $resp = callAuthApi('POST', '/nodes/register', [
            'url' => $url,
            'name' => $name,
            'admin_email' => $email,
            'public_key' => $publicKey,
            'description' => $description
        ], $isAuth);

        if (isset($resp['ok']) && $resp['ok']) {
            $success = '🎉 Заявка на регистрацию узла успешно принята! Узел добавлен на проверку администратором сети.';
        } else {
            $error = $resp['error'] ?? 'Не удалось отправить заявку. Попробуйте позже.';
        }
    }
}
$pageTitle = 'NexTalk — Установка своего узла';
$metaDescription = 'Пошаговая инструкция по развёртыванию собственного узла NexTalk и форма заявки на добавление в реестр';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="<?= $isAuth ? 'app' : '' ?>" id="app">

    <?php if ($isAuth): ?>
        <?php
        $activeTab = 'install';
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
                <a href="nodes.php" class="nav-link">Каталог узлов</a>
                <a href="install.php" class="nav-link nav-link-active">Установка узла</a>
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
        <div class="page-content <?= !$isAuth ? 'page-content-install' : '' ?>">

            <div class="page-header">
                <h1 class="page-title">🚀 Руководство по установке узла NexTalk</h1>
                <p class="page-subtitle">Поднимите собственный независимый сервер передачи сообщений менее чем за 15 минут</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Requirements Overview -->
            <div class="settings-card">
                <h3 class="card-title">📋 Системные требования</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Среда выполнения</div>
                        <div class="info-value">Node.js 22+ (LTS)</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">База данных</div>
                        <div class="info-value">Встроенная SQLite</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Память и диск</div>
                        <div class="info-value">512 МБ ОЗУ · 5 ГБ SSD</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Сеть</div>
                        <div class="info-value">HTTPS / WSS (домен или туннель)</div>
                    </div>
                </div>
            </div>

            <!-- Installation Steps -->
            <div class="settings-card">
                <h3 class="card-title">🛠️ Пошаговая инструкция по запуску</h3>

                <div class="guide-step">
                    <div class="guide-step-num">1</div>
                    <div class="guide-step-body flex-1">
                        <h4>Скачайте исходный код узла</h4>
                        <p>Клонируйте репозиторий или скачайте готовый архив с исходниками узла передачи:</p>
                        <div class="code-snippet">
                            <code>git clone https://github.com/ivanor2/nextalk-node.git node-server && cd node-server</code>
                            <button class="copy-btn" onclick="copyCode(this)">Копировать</button>
                        </div>
                    </div>
                </div>

                <div class="guide-step">
                    <div class="guide-step-num">2</div>
                    <div class="guide-step-body flex-1">
                        <h4>Установите зависимости</h4>
                        <p>Узел использует WebSocket (ws) и dotenv. Отдельный сервер MySQL не требуется:</p>
                        <div class="code-snippet">
                            <code>npm install</code>
                            <button class="copy-btn" onclick="copyCode(this)">Копировать</button>
                        </div>
                    </div>
                </div>

                <div class="guide-step">
                    <div class="guide-step-num">3</div>
                    <div class="guide-step-body flex-1">
                        <h4>Настройте файл конфигурации (.env)</h4>
                        <p>Скопируйте пример окружения и укажите параметры вашего сервера:</p>
                        <div class="code-snippet">
                            <code>cp .env.example .env</code>
                            <button class="copy-btn" onclick="copyCode(this)">Копировать</button>
                        </div>
                        <div class="env-example-box">
                            PORT=3001<br>
                            NODE_NAME="Мой личный узел"<br>
                            AUTH_SERVER_URL=http://nextalk-auth<br>
                            PUBLIC_WS_URL=wss://my-node.example.org
                        </div>
                    </div>
                </div>

                <div class="guide-step">
                    <div class="guide-step-num">4</div>
                    <div class="guide-step-body flex-1">
                        <h4>Запустите узел</h4>
                        <p>Запустите сервер в фоновом режиме через PM2 или системный демон:</p>
                        <div class="code-snippet">
                            <code>node server.js &nbsp;# или: pm2 start server.js --name nextalk-node</code>
                            <button class="copy-btn" onclick="copyCode(this)">Копировать</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Application Form -->
            <div class="settings-card" id="apply">
                <h3 class="card-title">📝 Заявка на регистрацию узла в реестре</h3>
                <p class="card-desc mb-20">
                    После успешного запуска узла заполните форму ниже, чтобы администратор сети добавил ваш узел в глобальный каталог пользователей.
                </p>

                <form method="POST">
                    <div class="field">
                        <label for="node_url">Публичный адрес узла (HTTPS / WSS) *</label>
                        <input
                            type="text"
                            id="node_url"
                            name="url"
                            required
                            placeholder="https://node.example.org или wss://node.example.org"
                            value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
                        >
                        <div class="field-hint">Должен быть доступен снаружи по безопасному протоколу</div>
                    </div>

                    <div class="field">
                        <label for="node_name">Название узла *</label>
                        <input
                            type="text"
                            id="node_name"
                            name="name"
                            required
                            maxlength="64"
                            placeholder="Например: Campus Node #1"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="admin_email">Контактный E-mail администратора *</label>
                        <input
                            type="email"
                            id="admin_email"
                            name="email"
                            required
                            placeholder="admin@example.org"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="public_key">Публичный ключ узла (RSA / Ed25519) *</label>
                        <textarea
                            id="public_key"
                            name="public_key"
                            required
                            rows="3"
                            placeholder="-----BEGIN PUBLIC KEY----- ... -----END PUBLIC KEY-----"
                            class="textarea-key-mono"
                        ><?= htmlspecialchars($_POST['public_key'] ?? '') ?></textarea>
                        <div class="field-hint">Ключ генерируется автоматически при первом старте узла в <code>keys/public.pem</code></div>
                    </div>

                    <div class="field">
                        <label for="node_desc">Описание узла (необязательно)</label>
                        <input
                            type="text"
                            id="node_desc"
                            name="description"
                            maxlength="200"
                            placeholder="Расположение сервера, открытая регистрация или корпоративный узел"
                            value="<?= htmlspecialchars($_POST['description'] ?? '') ?>"
                        >
                    </div>

                    <button type="submit" class="btn-submit btn-submit-max-320">
                        📨 Отправить заявку в реестр
                    </button>
                </form>
            </div>

        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script src="assets/js/app.js"></script>
<script src="assets/js/install.js"></script>
</body>
</html>
