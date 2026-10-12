<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$displayName = $_SESSION['display_name'] ?? $username;

// Получаем чаты пользователя через Node API
$response = callNodeApi('GET', '/chats');
$chats = [];
if (isset($response['ok']) && $response['ok']) {
    $chats = $response['chats'] ?? [];
}

// Загружаем контакты для модального окна создания группы
$contactsResp = callAuthApi('GET', '/contacts', null, true);
$contacts = [];
if (isset($contactsResp['ok']) && $contactsResp['ok']) {
    $contacts = $contactsResp['contacts'] ?? [];
}

// Дефолтный WebSocket узел
$defaultNode = defined('WS_NODE_URL') ? WS_NODE_URL : 'ws://127.0.0.1:3001';
$userInit = mb_strtoupper(mb_substr($displayName, 0, 1));
$pageTitle = 'NexTalk — Мессенджер';
$metaDescription = 'NexTalk — децентрализованный мессенджер с E2E-шифрованием';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">

  <!-- ======= SIDEBAR ======= -->
  <aside class="sidebar" id="sidebar">

    <!-- User info + search -->
    <div class="sidebar-top">
      <div class="sidebar-user">
        <a href="profile.php" class="avatar avatar-lg text-decoration-none" id="myAvatar" title="Мой профиль">
          <?= $userInit ?>
          <div class="online-dot"></div>
        </a>
        <div class="sidebar-user-info sidebar-user-pointer" onclick="window.location.href='profile.php'">
          <div class="sidebar-username"><?= htmlspecialchars($displayName) ?></div>
          <div class="sidebar-tag">@<?= htmlspecialchars($username) ?></div>
        </div>
        <button class="icon-btn" id="themeToggleApp" title="Сменить тему">🌙</button>
        <a href="settings.php" class="icon-btn text-decoration-none" title="Настройки">⚙️</a>
      </div>

      <!-- Quick Search Bar -->
      <div class="search-box mt-14" id="searchBox">
        <span class="search-icon">🔍</span>
        <input
          type="text"
          id="userSearch"
          placeholder="Поиск собеседника (Ctrl+K)..."
          autocomplete="off"
        >
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </div>

    <!-- Cabinet Quick Navigation -->
    <div class="sidebar-quick-nav">
      <a href="contacts.php" class="btn btn-ghost btn-pill-sm">
        👥 Контакты
      </a>
      <a href="profile.php" class="btn btn-ghost btn-pill-sm">
        👤 Профиль
      </a>
      <a href="sessions.php" class="btn btn-ghost btn-pill-sm">
        📱 Сеансы
      </a>
      <a href="nodes.php" class="btn btn-ghost btn-pill-sm">
        🌐 Узлы
      </a>
    </div>

    <!-- Chat list -->
    <div class="chat-list" id="chatList">
      <div class="chat-list-top chat-list-top-bar">
        <span class="chat-list-label p-0 m-0">Диалоги и группы</span>
        <button class="btn btn-ghost btn-pill-xs" id="openCreateGroupBtn">
          ➕ Группа
        </button>
      </div>

      <?php if (empty($chats)): ?>
        <div class="chat-list-empty">
          <div class="chat-list-empty-icon">💬</div>
          Пока нет диалогов.<br>Найдите пользователя в поиске выше!
        </div>
      <?php else: ?>
        <?php foreach ($chats as $chat): ?>
          <?php
            $isGroup = ($chat['type'] ?? 'private') === 'group';
            $chatName = $chat['companion_name'] ?: ($chat['name'] ?: ($isGroup ? 'Группа' : 'Чат'));
            $initial  = mb_strtoupper(mb_substr($chatName, 0, 1));
            $lastMsg  = $chat['last_message'] ?? 'Нет сообщений';
            $myMsg    = ($chat['last_sender_id'] ?? null) == $userId;
            $time     = !empty($chat['last_message_time'])
              ? date('H:i', strtotime($chat['last_message_time']))
              : '';

            // Clean attachment markers in preview
            if (str_starts_with($lastMsg, '[attachment:')) {
              $lastMsg = '📎 Вложение';
            }
          ?>
          <a class="chat-item" href="chat.php?id=<?= $chat['id'] ?>" data-chat-id="<?= $chat['id'] ?>">
            <div class="avatar <?= $isGroup ? 'avatar-group-grad' : '' ?>">
              <?= $initial ?>
            </div>
            <div class="chat-item-body">
              <div class="chat-item-top">
                <div class="chat-item-name flex-row-gap-6">
                  <?= htmlspecialchars($chatName) ?>
                  <?php if ($isGroup): ?>
                    <span class="badge-status badge-active badge-micro">группа</span>
                  <?php endif; ?>
                </div>
                <?php if ($time): ?>
                  <div class="chat-item-time"><?= $time ?></div>
                <?php endif; ?>
              </div>
              <div class="chat-item-bottom">
                <div class="chat-item-preview">
                  <?= $myMsg ? 'Вы: ' : '' ?><?= htmlspecialchars(mb_substr($lastMsg, 0, 42)) ?>
                </div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Node status bar -->
    <div class="node-status-bar" id="nodeStatusBar">
      <div class="node-dot connecting" id="nodeStatusDot"></div>
      <span class="node-status-text" id="nodeStatusText">Подключение к узлу...</span>
      <button class="icon-btn icon-btn-sm" id="changeNodeBtn" title="Сменить узел">🔌</button>
    </div>

    <!-- Sidebar bottom -->
    <div class="sidebar-bottom">
      <button class="sidebar-action-btn" id="openNodesModal">
        🌐 Узлы сети
      </button>
      <a href="logout.php" class="sidebar-action-btn text-decoration-none">
        🚪 Выйти
      </a>
    </div>

  </aside>

  <!-- ======= MAIN AREA ======= -->
  <main class="chat-area" id="chatArea">
    <div class="welcome-screen">
      <div class="welcome-icon">💬</div>
      <h2 class="welcome-title">NexTalk Децентрализованный Мессенджер</h2>
      <p class="welcome-desc">
        Общайтесь без единого владельца сети. Все сообщения шифруются сквозным шифрованием на вашем устройстве.
      </p>

      <div class="welcome-actions">
        <button class="btn btn-primary" onclick="document.getElementById('userSearch').focus()">
          🔍 Найти собеседника
        </button>
        <button class="btn btn-ghost" id="welcomeCreateGroupBtn">
          ➕ Создать группу
        </button>
        <a href="contacts.php" class="btn btn-ghost">
          👥 Мои контакты
        </a>
      </div>

      <div class="welcome-user-box">
        Вы вошли как: <strong class="text-accent-light"><?= htmlspecialchars($displayName) ?></strong> (@<?= htmlspecialchars($username) ?>)
        · ID: <?= $userId ?>
      </div>
    </div>
  </main>

</div>

<?php require __DIR__ . '/templates/modals/create_group.php'; ?>

<!-- === NODE SELECTION MODAL === -->
<div class="modal-overlay" id="nodesModal">
  <div class="modal rel">
    <h3>🌐 Выбор узла передачи</h3>
    <button class="icon-btn modal-close" id="closeNodesModal">✕</button>

    <p class="modal-subtext">
      Сообщения передаются через выбранный WebSocket-узел. Подключитесь к публичному узлу или введите адрес своего.
    </p>

    <div class="node-list" id="nodeList">
      <div class="node-list-item" data-ws="<?= $defaultNode ?>" id="localNode">
        <div class="node-dot connected"></div>
        <div class="node-info">
          <div class="node-name">Основной узел</div>
          <div class="node-addr"><?= $defaultNode ?></div>
        </div>
        <span class="node-ping" id="localNodePing">...</span>
      </div>
    </div>

    <div class="field mb-12">
      <label>Свой узел (адрес WebSocket)</label>
      <input
        type="text"
        id="customNodeInput"
        placeholder="ws://192.168.1.100:3001"
        class="w-100"
      >
    </div>

    <button class="btn-submit mb-0" id="connectCustomNode">
      Подключиться к этому узлу
    </button>
  </div>
</div>

<!-- === COMMAND PALETTE (Ctrl+K) === -->
<div class="cmd-palette-overlay" id="cmdPaletteOverlay">
  <div class="cmd-palette">
    <div class="cmd-input-wrap">
      <span class="fs-18">🔍</span>
      <input type="text" id="cmdPaletteInput" placeholder="Поиск пользователей, диалогов и разделов...">
      <span class="cmd-badge-esc">ESC</span>
    </div>
    <div class="cmd-results" id="cmdPaletteResults">
      <div class="cmd-item" onclick="window.location.href='contacts.php'">
        <span>👥</span>
        <span>Контакты и поиск</span>
        <span class="cmd-badge">Перейти</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='nodes.php'">
        <span>🌐</span>
        <span>Каталог узлов сети</span>
        <span class="cmd-badge">Сеть</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='settings.php'">
        <span>⚙️</span>
        <span>Настройки оформления и ключей</span>
        <span class="cmd-badge">Перейти</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='sessions.php'">
        <span>📱</span>
        <span>Активные сеансы</span>
        <span class="cmd-badge">Безопасность</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='install.php'">
        <span>🚀</span>
        <span>Установить свой узел</span>
        <span class="cmd-badge">Инструкция</span>
      </div>
    </div>
  </div>
</div>

<!-- === TOASTS === -->
<div class="toasts" id="toasts"></div>

<script>
window.APP_CONFIG = {
  userId: <?= (int)$userId ?>,
  username: <?= json_encode($username) ?>,
  displayName: <?= json_encode($displayName) ?>,
  defaultNode: <?= json_encode($defaultNode) ?>
};
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/index.js"></script>
</body>
</html>