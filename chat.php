<?php
require_once __DIR__ . '/src/Bootstrap.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$username = $_SESSION['username'];
$chatId   = (int)($_GET['id'] ?? 0);

// Проверка доступа к чату
$chatResponse = callNodeApi('GET', "/chats/$chatId");
if (!isset($chatResponse['ok']) || !$chatResponse['ok']) {
    die('У вас нет доступа к этому чату или он не существует. <a href="index.php">Назад к чатам</a>');
}
$chat = $chatResponse['chat'];
$isGroup = ($chat['type'] ?? 'private') === 'group';

// POST: сохранить сообщение в БД (вызывается из JS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $content = trim($_POST['message']);
    if (mb_strlen($content) > 0 && mb_strlen($content) <= 8000) {
        $res = callNodeApi('POST', '/messages', [
            'chat_id' => $chatId,
            'content' => $content
        ]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            if (isset($res['ok']) && $res['ok']) {
                echo json_encode(['ok' => true, 'id' => $res['id'], 'timestamp' => $res['timestamp']]);
            } else {
                echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Error']);
            }
            exit;
        }
    }
    header("Location: chat.php?id=$chatId");
    exit;
}

// Имя и ID собеседника
$companionName = 'Чат';
$companionId   = null;
if (!$isGroup) {
    $companionName = $chat['companion_name'] ?: 'Чат';
    $companionId = $chat['companion_id'] ?? null;
} else {
    $companionName = $chat['name'] ?: 'Групповой чат';
}

$members = $chat['members'] ?? [];

// Начальные сообщения
$msgResponse = callNodeApi('GET', "/messages?chat_id=$chatId&limit=100");
$messages = [];
if (isset($msgResponse['ok']) && $msgResponse['ok']) {
    $messages = $msgResponse['messages'] ?? [];
}
$lastMessageId = !empty($messages) ? (int)end($messages)['id'] : 0;
$companionInit = mb_strtoupper(mb_substr($companionName, 0, 1));
$defaultNode = defined('WS_NODE_URL') ? WS_NODE_URL : 'ws://127.0.0.1:3001';
$pageTitle = 'NexTalk — ' . $companionName;
$metaDescription = 'Безопасный E2E-чат в NexTalk';
require __DIR__ . '/templates/partials/head.php';
?>
<div class="app" id="app">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <div class="sidebar-user">
        <a href="index.php" class="icon-btn fs-20 text-decoration-none" title="К чатам (Esc)">←</a>
        <div class="sidebar-user-info">
          <div class="sidebar-username"><?= htmlspecialchars($_SESSION['display_name'] ?? $username) ?></div>
          <div class="sidebar-tag">@<?= htmlspecialchars($username) ?></div>
        </div>
        <button class="icon-btn" id="themeToggleApp" title="Тема">🌙</button>
      </div>

      <div class="search-box mt-12" id="searchBox">
        <span class="search-icon">🔍</span>
        <input type="text" id="userSearch" placeholder="Поиск (Ctrl+K)..." autocomplete="off">
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </div>

    <div class="chat-list" id="chatList">
      <div class="chat-list-label">Текущий чат</div>
      <div class="chat-item active">
        <div class="avatar"><?= $companionInit ?></div>
        <div class="chat-item-body">
          <div class="chat-item-name"><?= htmlspecialchars($companionName) ?></div>
          <div class="chat-item-preview" id="sidebarStatus">
            <?= $isGroup ? (count($members) . ' участников') : 'Загрузка...' ?>
          </div>
        </div>
      </div>

      <div class="chat-list-label mt-16">Разделы</div>
      <a href="index.php" class="chat-item">
        <span class="chat-section-icon">💬</span>
        <div class="chat-item-body"><div class="chat-item-name">Все диалоги</div></div>
      </a>
      <a href="contacts.php" class="chat-item">
        <span class="chat-section-icon">👥</span>
        <div class="chat-item-body"><div class="chat-item-name">Контакты</div></div>
      </a>
      <a href="settings.php" class="chat-item">
        <span class="chat-section-icon">⚙️</span>
        <div class="chat-item-body"><div class="chat-item-name">Настройки</div></div>
      </a>
    </div>

    <!-- Node status bar -->
    <div class="node-status-bar" id="nodeStatusBar">
      <div class="node-dot connecting" id="nodeStatusDot"></div>
      <span class="node-status-text" id="nodeStatusText">Подключение к узлу...</span>
      <button class="icon-btn icon-btn-sm" id="changeNodeBtn" title="Сменить узел">🔌</button>
    </div>

    <div class="sidebar-bottom">
      <button class="sidebar-action-btn" id="openNodesModal">🌐 Узлы сети</button>
      <a href="logout.php" class="sidebar-action-btn text-decoration-none">🚪 Выйти</a>
    </div>
  </aside>

  <!-- CHAT AREA -->
  <main class="chat-area" id="chatArea">
    <!-- Chat Header -->
    <div class="chat-header">
      <div class="avatar cursor-pointer" id="companionAvatar"><?= $companionInit ?></div>
      <div class="chat-header-info cursor-pointer" id="chatHeaderTitle">
        <div class="chat-header-name">
          <?= htmlspecialchars($companionName) ?>
          <?php if ($isGroup): ?>
            <span class="badge-status badge-active badge-group-tag">Группа</span>
          <?php endif; ?>
        </div>
        <div class="chat-header-status" id="chatStatus">
          <?= $isGroup ? (count($members) . ' участников') : 'Загрузка...' ?>
        </div>
      </div>

      <div class="chat-header-actions">
        <div class="node-indicator" id="nodeIndicator" title="Сменить узел">
          <div class="node-dot connecting" id="nodeIndicatorDot"></div>
          <span id="nodeIndicatorText">Узел</span>
        </div>
        <button class="icon-btn" id="refreshBtn" title="Обновить сообщения">↻</button>
        <button class="icon-btn" id="toggleInfoBtn" title="Информация о чате">ℹ️</button>
      </div>
    </div>

    <!-- Messages container -->
    <div class="messages-wrap" id="messagesWrap">
      <?php if (empty($messages)): ?>
        <div class="empty-chat" id="emptyChat">
          <div class="chat-empty-icon">💬</div>
          <span>Нет сообщений. Напишите первым!</span>
        </div>
      <?php else: ?>
        <?php $prevDate = null; foreach ($messages as $msg):
          $date = date('Y-m-d', strtotime($msg['created_at']));
          $isMe = ($msg['sender_id'] == $userId); ?>

          <?php if ($date !== $prevDate): $prevDate = $date; ?>
            <div class="msg-day-divider"><span><?= date('d F Y', strtotime($msg['created_at'])) ?></span></div>
          <?php endif; ?>

          <div class="message <?= $isMe ? 'out' : 'in' ?>" data-id="<?= $msg['id'] ?>">
            <?php if (!$isMe): ?>
              <div class="msg-author"><?= htmlspecialchars($msg['username']) ?></div>
            <?php endif; ?>
            <div class="msg-bubble"><?= nl2br(htmlspecialchars($msg['content'])) ?></div>
            <div class="msg-footer">
              <span class="msg-time"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
              <?php if ($isMe): ?><span class="msg-status">✓✓</span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="typing-indicator" id="typingIndicator">
        <div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>
      </div>
    </div>

    <!-- Input Box -->
    <div class="message-input-wrap">
      <div class="message-input-box">
        <!-- Paperclip attachment button -->
        <button class="attachment-btn" id="attachBtn" title="Прикрепить файл (до 10 МБ)">📎</button>
        <input type="file" id="attachmentInput" class="d-none">

        <textarea id="msgInput" placeholder="Сообщение... (Enter — отправить, Shift+Enter — перенос)" rows="1" maxlength="4000"></textarea>
        <button class="send-btn" id="sendBtn" title="Отправить (Enter)">➤</button>
      </div>
    </div>
  </main>

  <!-- RIGHT SIDEBAR: Chat Information Panel (раздел 6.3, 8.3) -->
  <aside class="chat-info-panel hidden" id="chatInfoPanel">
    <div class="info-panel-header">
      <span class="info-panel-title">Информация</span>
      <button class="icon-btn" id="closeInfoBtn" title="Закрыть">✕</button>
    </div>

    <div class="info-panel-profile">
      <div class="profile-avatar-large avatar-xl">
        <?= $companionInit ?>
      </div>
      <div class="info-panel-name"><?= htmlspecialchars($companionName) ?></div>
      <?php if (!$isGroup && $companionName): ?>
        <div class="info-panel-username">@<?= htmlspecialchars($companionName) ?></div>
      <?php endif; ?>
      <div class="info-panel-status" id="infoPanelStatus">
        <?= $isGroup ? 'Групповая беседа' : 'Собеседник' ?>
      </div>
    </div>

    <div class="info-panel-section">
      <div class="info-section-title">Безопасность и шифрование</div>
      <div class="info-row">
        <span class="info-row-label">Шифрование:</span>
        <span class="info-row-val text-success">🔐 E2EE (Active)</span>
      </div>
      <div class="info-row">
        <span class="info-row-label">Узел доставки:</span>
        <span class="info-row-val" id="infoPanelNode">node-local</span>
      </div>
    </div>

    <?php if ($isGroup): ?>
      <div class="info-panel-section">
        <div class="info-section-title flex-between">
          <span>Участники (<?= count($members) ?>)</span>
          <button class="btn btn-ghost btn-pill-xs" id="openAddMemberBtn" title="Добавить участника">➕ Добавить</button>
        </div>
        <div class="flex-col-gap-8" id="chatMembersList">
          <?php foreach ($members as $mem): ?>
            <div class="member-item-row">
              <div class="avatar avatar-sm">
                <?= mb_strtoupper(mb_substr($mem['username'] ?: 'U', 0, 1)) ?>
              </div>
              <span class="member-item-name"><?= htmlspecialchars($mem['username'] ?: 'Участник #' . $mem['user_id']) ?></span>
              <?php if ($mem['user_id'] == $userId): ?>
                <span class="badge-status badge-active badge-you">Вы</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Add member to group modal -->
      <div class="modal-overlay" id="addMemberModal">
        <div class="modal modal-max-sm">
          <h3>👥 Добавить участника в группу</h3>
          <button class="icon-btn modal-close" id="closeAddMemberModal">✕</button>
          <p class="card-desc-lg">
            Введите @username пользователя для приглашения в группу:
          </p>
          <form action="api/add_chat_member.php" method="POST" id="addMemberForm">
            <input type="hidden" name="chat_id" value="<?= (int)$chatId ?>">
            <div class="field">
              <label for="newMemberUsername">Имя пользователя (@username)</label>
              <input type="text" id="newMemberUsername" name="username" placeholder="например: chel" required autocomplete="off">
            </div>
            <button type="submit" class="btn-submit mt-12">Добавить в группу</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="info-panel-section">
      <div class="info-section-title">Быстрые действия</div>
      <div class="flex-col-gap-8">
        <a href="contacts.php" class="btn btn-ghost btn-info-action">
          👥 Открыть контакты
        </a>
        <button class="btn btn-ghost btn-info-action text-danger" id="clearViewBtn">
          🗑️ Очистить экран
        </button>
      </div>
    </div>
  </aside>

</div>

<!-- Node Connection Modal -->
<div class="modal-overlay" id="nodesModal">
  <div class="modal rel">
    <h3>🌐 Выбор узла передачи</h3>
    <button class="icon-btn modal-close" id="closeNodesModal">✕</button>
    <p class="modal-subtext mb-16">
      Сообщения передаются через выбранный WebSocket-узел. Выберите из каталога или укажите свой.
    </p>
    <div class="node-list" id="nodeListModal">
      <div class="text-center text-muted p-16 fs-13">⏳ Загрузка узлов...</div>
    </div>
    <div class="field mb-12">
      <label>Свой адрес WebSocket</label>
      <input type="text" id="customNodeInput" placeholder="ws://192.168.1.100:3001" class="w-100">
    </div>
    <button class="btn-submit mb-0" id="connectCustomNode">Подключиться</button>
  </div>
</div>

<!-- Command Palette Modal (Ctrl+K) -->
<div class="cmd-palette-overlay" id="cmdPaletteOverlay">
  <div class="cmd-palette">
    <div class="cmd-input-wrap">
      <span class="fs-18">🔍</span>
      <input type="text" id="cmdPaletteInput" placeholder="Поиск чатов, пользователей и команд...">
      <span class="cmd-badge-esc">ESC</span>
    </div>
    <div class="cmd-results" id="cmdPaletteResults">
      <div class="cmd-item" onclick="window.location.href='index.php'">
        <span>💬</span>
        <span>Все диалоги</span>
        <span class="cmd-badge">Перейти</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='contacts.php'">
        <span>👥</span>
        <span>Контакты и поиск людей</span>
        <span class="cmd-badge">Перейти</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='nodes.php'">
        <span>🌐</span>
        <span>Каталог узлов</span>
        <span class="cmd-badge">Сеть</span>
      </div>
      <div class="cmd-item" onclick="window.location.href='settings.php'">
        <span>⚙️</span>
        <span>Настройки и ключи</span>
        <span class="cmd-badge">Перейти</span>
      </div>
    </div>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script>
window.CHAT_CONFIG = {
  userId: <?= (int)$userId ?>,
  username: <?= json_encode($username) ?>,
  chatId: <?= (int)$chatId ?>,
  isGroup: <?= $isGroup ? 'true' : 'false' ?>,
  companionName: <?= json_encode($companionName) ?>,
  companionId: <?= json_encode($companionId) ?>,
  lastMessageId: <?= (int)$lastMessageId ?>,
  defaultNode: <?= json_encode($defaultNode) ?>
};
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/chat.js"></script>
</body>
</html>