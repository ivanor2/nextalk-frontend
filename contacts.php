<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $contactId = (int)($_POST['contact_id'] ?? 0);

    switch ($action) {
        case 'add':
            $resp = callAuthApi('POST', '/contacts', ['contact_id' => $contactId], true);
            echo json_encode($resp);
            exit;
        case 'accept':
            $resp = callAuthApi('PUT', '/contacts/accept', ['contact_id' => $contactId], true);
            echo json_encode($resp);
            exit;
        case 'block':
            $resp = callAuthApi('PUT', '/contacts/block', ['contact_id' => $contactId], true);
            echo json_encode($resp);
            exit;
        case 'remove':
            $resp = callAuthApi('DELETE', '/contacts', ['contact_id' => $contactId], true);
            echo json_encode($resp);
            exit;
    }
}

// Get contacts
$contactsResp = callAuthApi('GET', '/contacts', null, true);
$contacts = $contactsResp['contacts'] ?? [];
$incoming = $contactsResp['incoming_requests'] ?? [];

$accepted = array_filter($contacts, fn($c) => $c['status'] === 'accepted');
$pending = array_filter($contacts, fn($c) => $c['status'] === 'pending');
$blocked = array_filter($contacts, fn($c) => $c['status'] === 'blocked');
$pageTitle = 'NexTalk — Контакты';
$metaDescription = 'Управление контактами в NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">
    <?php
    $activeTab = 'contacts';
    require __DIR__ . '/templates/partials/sidebar.php';
    ?>

    <!-- MAIN AREA -->
    <main class="chat-area overflow-y-auto">
        <div class="page-content">
            <div class="page-header">
                <h1 class="page-title">👥 Контакты</h1>
                <p class="page-subtitle">Управляйте списком контактов</p>
            </div>

            <!-- Search users -->
            <div class="settings-card">
                <h3 class="card-title">🔍 Найти пользователя</h3>
                <div class="search-box mb-8" id="contactSearchBox">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="contactSearch" placeholder="Введите логин..." autocomplete="off">
                    <div class="search-dropdown" id="contactSearchDropdown"></div>
                </div>
            </div>

            <!-- Incoming requests -->
            <?php if (!empty($incoming)): ?>
            <div class="settings-card">
                <h3 class="card-title">📩 Входящие запросы <span class="badge-count"><?= count($incoming) ?></span></h3>
                <div class="contacts-list" id="incomingList">
                    <?php foreach ($incoming as $req): ?>
                    <div class="contact-item" data-id="<?= $req['contact_id'] ?>">
                        <?= Helpers::renderAvatar($req['avatar_path'] ?? null, $req['display_name'] ?: $req['username']) ?>
                        <div class="contact-info">
                            <div class="contact-name"><?= htmlspecialchars($req['display_name'] ?: $req['username']) ?></div>
                            <div class="contact-username">@<?= htmlspecialchars($req['username']) ?></div>
                        </div>
                        <div class="contact-actions">
                            <button class="btn btn-primary btn-sm-action" onclick="acceptContact(<?= $req['contact_id'] ?>, this)">Принять</button>
                            <button class="btn btn-ghost btn-sm-ghost" onclick="blockContact(<?= $req['contact_id'] ?>, this)">Отклонить</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Accepted contacts -->
            <div class="settings-card">
                <h3 class="card-title">✅ Мои контакты <span class="badge-count"><?= count($accepted) ?></span></h3>
                <?php if (empty($accepted)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">👥</div>
                        <p>Список контактов пуст. Найдите пользователя по логину выше!</p>
                    </div>
                <?php else: ?>
                <div class="contacts-list" id="contactsList">
                    <?php foreach ($accepted as $c): ?>
                    <div class="contact-item" data-id="<?= $c['contact_id'] ?>">
                        <?= Helpers::renderAvatar($c['avatar_path'] ?? null, $c['display_name'] ?: $c['username']) ?>
                        <div class="contact-info">
                            <div class="contact-name"><?= htmlspecialchars($c['display_name'] ?: $c['username']) ?></div>
                            <div class="contact-username">@<?= htmlspecialchars($c['username']) ?></div>
                            <?php if (!empty($c['status_text'])): ?>
                                <div class="contact-status">“<?= htmlspecialchars($c['status_text']) ?>”</div>
                            <?php endif; ?>
                        </div>
                        <div class="contact-actions">
                            <button class="btn btn-primary btn-sm-action" onclick="startChatWith(<?= $c['contact_id'] ?>)">💬 Чат</button>
                            <button class="btn btn-ghost btn-sm-danger" onclick="removeContact(<?= $c['contact_id'] ?>, this)">✕</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Blocked -->
            <?php if (!empty($blocked)): ?>
            <div class="settings-card">
                <h3 class="card-title">🚫 Чёрный список</h3>
                <div class="contacts-list">
                    <?php foreach ($blocked as $c): ?>
                    <div class="contact-item" data-id="<?= $c['contact_id'] ?>">
                        <div class="avatar opacity-50"><?= mb_strtoupper(mb_substr($c['username'], 0, 1)) ?></div>
                        <div class="contact-info">
                            <div class="contact-name opacity-50"><?= htmlspecialchars($c['display_name'] ?: $c['username']) ?></div>
                            <div class="contact-username opacity-50">@<?= htmlspecialchars($c['username']) ?></div>
                        </div>
                        <div class="contact-actions">
                            <button class="btn btn-ghost btn-sm-action" onclick="removeContact(<?= $c['contact_id'] ?>, this)">Разблокировать</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require __DIR__ . '/templates/partials/toast.php'; ?>

<script>
window.APP_USER = <?= json_encode(['id' => $userId, 'username' => $username]) ?>;
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/contacts.js"></script>
</body>
</html>
