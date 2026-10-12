<?php
require_once __DIR__ . '/src/Bootstrap.php';

$isAuth = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? null;
$pageTitle = 'NexTalk — Правила и политика конфиденциальности';
$metaDescription = 'Условия использования и политика конфиденциальности децентрализованного мессенджера NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>

    <!-- NAV -->
    <header class="nav">
        <a href="landing.html" class="nav-logo text-decoration-none">
            <div class="logo-icon">💬</div>
            <span>Nex<em>Talk</em></span>
        </a>
        <div class="nav-links">
            <a href="landing.html" class="nav-link">Главная</a>
            <a href="nodes.php" class="nav-link">Каталог узлов</a>
            <a href="install.php" class="nav-link">Установка узла</a>
            <a href="rules.php" class="nav-link nav-link-active">Правила</a>
            <?php if ($isAuth): ?>
                <a href="index.php" class="btn btn-primary">В мессенджер →</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-ghost ml-8">Войти</a>
                <a href="register.php" class="btn btn-primary">Регистрация</a>
            <?php endif; ?>
            <button class="theme-toggle ml-8" id="themeTogglePub" title="Сменить тему">🌙</button>
        </div>
    </header>

    <div class="pt-100"></div>

    <main class="page-content page-content-rules">
        <div class="page-header text-center mb-48">
            <h1 class="page-title fs-32">📜 Правила и политика безопасности</h1>
            <p class="page-subtitle fs-16">Принципы работы децентрализованной сети NexTalk, защита данных и правила взаимодействия</p>
        </div>

        <!-- Quick Navigation Pills -->
        <div class="nav-pills-wrap">
            <a href="#terms" class="btn btn-ghost btn-pill">1. Условия использования</a>
            <a href="#privacy" class="btn btn-ghost btn-pill">2. Политика конфиденциальности</a>
            <a href="#e2ee" class="btn btn-ghost btn-pill">3. Сквозное шифрование</a>
            <a href="#nodes" class="btn btn-ghost btn-pill">4. Ответственность узлов</a>
        </div>

        <!-- Section 1: Terms -->
        <div class="settings-card p-32" id="terms">
            <h2 class="card-title fs-22 text-accent-light">
                1. Условия использования (Terms of Service)
            </h2>
            <div class="rules-text-block">
                <p>
                    <strong>1.1. Общие положения.</strong> Сеть NexTalk является децентрализованной federated-платформой для защищённого обмена сообщениями. Регистрация и использование платформы подтверждают ваше безоговорочное согласие с настоящими правилами.
                </p>
                <p>
                    <strong>1.2. Учётная запись и ключи.</strong> Пользователь самостоятельно несёт ответственность за безопасность своего устройства, сохранность пароля и ключевой пары шифрования. Сервер авторизации не имеет доступа к вашему закрытому ключу и не может расшифровать переписку.
                </p>
                <p>
                    <strong>1.3. Запрещённые действия.</strong> В сети NexTalk запрещено:
                </p>
                <ul class="rules-list">
                    <li>Распространение вредоносного программного обеспечения и эксплойтов;</li>
                    <li>Организация DoS/DDoS-атак на узлы сети или сервер авторизации;</li>
                    <li>Массовая нежелательная рассылка сообщений (спам);</li>
                    <li>Использование уязвимостей для нарушения целостности чужих сессий или переписки.</li>
                </ul>
                <p>
                    <strong>1.4. Блокировка и модерация.</strong> Администрация сервера авторизации оставляет за собой право приостановить действие аккаунта в случае доказанных злоупотреблений, направленных на вывод из строя сетевой инфраструктуры.
                </p>
            </div>
        </div>

        <!-- Section 2: Privacy -->
        <div class="settings-card p-32" id="privacy">
            <h2 class="card-title fs-22 text-success">
                2. Политика конфиденциальности (Privacy Policy)
            </h2>
            <div class="rules-text-block">
                <p>
                    <strong>2.1. Минимальный сбор данных.</strong> Мы собираем только те данные, которые технически необходимы для функционирования федерации:
                </p>
                <ul class="rules-list">
                    <li>Логин (уникальный идентификатор в сети);</li>
                    <li>E-mail (для восстановления доступа и критических уведомлений);</li>
                    <li>Публичный криптографический ключ (для адресации зашифрованных сообщений);</li>
                    <li>IP-адреса активных сессий (для защиты от несанкционированного входа).</li>
                </ul>
                <p>
                    <strong>2.2. Отсутствие трекеров.</strong> В веб-клиенте NexTalk отсутствуют сторонние аналитические трекеры, рекламные пиксели и коммерческие скрипты слежения.
                </p>
                <p>
                    <strong>2.3. Хранение переписки.</strong> Узлы передачи сохраняют шифротекст сообщений только до момента доставки получателю либо в течение установленного срока хранения очереди (до 72 часов). Ни узел, ни сервер авторизации не имеют технических средств для расшифровки содержимого.
                </p>
            </div>
        </div>

        <!-- Section 3: E2EE -->
        <div class="settings-card p-32" id="e2ee">
            <h2 class="card-title fs-22 text-info">
                3. Принципы сквозного шифрования (E2EE)
            </h2>
            <div class="rules-text-block">
                <p>
                    Шифрование сообщений происходит на устройстве отправителя с использованием современного криптографического стандарта <strong>Web Crypto API</strong>.
                </p>
                <div class="e2e-info-box">
                    <div class="e2e-info-title">🔐 Как защищена ваша переписка:</div>
                    <ol class="e2e-info-list">
                        <li>Каждое сообщение шифруется публичным ключом адресата.</li>
                        <li>Узел передачи получает только непрозрачный ciphertext и криптографический nonce.</li>
                        <li>Расшифровать текст может исключительно адресат своим закрытым ключом.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Section 4: Nodes Responsibility -->
        <div class="settings-card p-32" id="nodes">
            <h2 class="card-title fs-22 text-warning">
                4. Статус и ответственность независимых узлов
            </h2>
            <div class="rules-text-block">
                <p>
                    Каждый узел сети управляется независимым оператором. Операторы узлов обязуются соблюдать сетевые протоколы NexTalk и не модифицировать передаваемые криптографические конверты. Пользователь в любой момент может сменить свой домашний узел в <a href="settings.php" class="link-accent-underline">Настройках</a>.
                </p>
            </div>
        </div>

        <div class="rules-footer-note">
            Последнее обновление редакции: Октябрь 2026 г. · Сеть NexTalk
        </div>
    </main>

    <footer>
        <p>© 2026 NexTalk. Децентрализованный мессенджер с открытым исходным кодом.</p>
        <div class="footer-links">
            <a href="nodes.php">Каталог узлов</a>
            <a href="install.php">Установка узла</a>
            <a href="rules.php">Правила и политика</a>
        </div>
    </footer>

    <?php require __DIR__ . '/templates/partials/toast.php'; ?>

    <script src="assets/js/app.js"></script>
</body>
</html>
