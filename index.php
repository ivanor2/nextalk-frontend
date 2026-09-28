<?php
require_once 'db.php';
requireLogin();

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Получаем чаты пользователя через Node API
$response = callNodeApi('GET', '/chats');
$chats = [];
if (isset($response['ok']) && $response['ok']) {
    $chats = $response['chats'] ?? [];
}

// Дефолтный WebSocket узел
$defaultNode = 'ws://localhost:3001';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NexTalk — Мессенджер</title>
  <meta name="description" content="NexTalk — ваши зашифрованные чаты">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app" id="app">

  <!-- ======= SIDEBAR ======= -->
  <aside class="sidebar" id="sidebar">

    <!-- User info + search -->
    <div class="sidebar-top">
      <div class="sidebar-user">
        <div class="avatar avatar-lg" id="myAvatar">
          <?= mb_strtoupper(mb_substr($username, 0, 1)) ?>
          <div class="online-dot"></div>
        </div>
        <div class="sidebar-user-info">
          <div class="sidebar-username"><?= htmlspecialchars($username) ?></div>
          <div class="sidebar-tag">ID: <?= $userId ?></div>
        </div>
        <button class="icon-btn" id="themeToggleApp" title="Тема">🌙</button>
        <button class="icon-btn" id="settingsBtn" title="Настройки">⚙️</button>
      </div>

      <!-- Search -->
      <div class="search-box" id="searchBox">
        <span class="search-icon">🔍</span>
        <input
          type="text"
          id="userSearch"
          placeholder="Найти пользователя..."
          autocomplete="off"
        >
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </div>

    <!-- Chat list -->
    <div class="chat-list" id="chatList">
      <div class="chat-list-label">Чаты</div>

      <?php if (empty($chats)): ?>
        <div style="padding:32px 16px;text-align:center;color:var(--text-muted);font-size:14px">
          <div style="font-size:40px;margin-bottom:12px">💬</div>
          Нет чатов. Найдите пользователя выше!
        </div>
      <?php else: ?>
        <?php foreach ($chats as $chat): ?>
          <?php
            $chatName = $chat['companion_name'] ?: ($chat['name'] ?: 'Чат');
            $initial  = mb_strtoupper(mb_substr($chatName, 0, 1));
            $lastMsg  = $chat['last_message'] ?? 'Нет сообщений';
            $myMsg    = $chat['last_sender_id'] == $userId;
            $time     = $chat['last_message_time']
              ? date('H:i', strtotime($chat['last_message_time']))
              : '';
          ?>
          <a class="chat-item" href="chat.php?id=<?= $chat['id'] ?>" data-chat-id="<?= $chat['id'] ?>">
            <div class="avatar"><?= $initial ?></div>
            <div class="chat-item-body">
              <div class="chat-item-top">
                <div class="chat-item-name"><?= htmlspecialchars($chatName) ?></div>
                <?php if ($time): ?>
                  <div class="chat-item-time"><?= $time ?></div>
                <?php endif; ?>
              </div>
              <div class="chat-item-preview">
                <?= $myMsg ? 'Вы: ' : '' ?><?= htmlspecialchars(mb_substr($lastMsg, 0, 45)) ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Node status -->
    <div class="node-status-bar" id="nodeStatusBar">
      <div class="node-dot connecting" id="nodeStatusDot"></div>
      <span class="node-status-text" id="nodeStatusText">Подключение к узлу...</span>
      <button class="icon-btn" id="changeNodeBtn" title="Сменить узел" style="width:28px;height:28px;font-size:14px">🔌</button>
    </div>

    <!-- Sidebar bottom -->
    <div class="sidebar-bottom">
      <button class="sidebar-action-btn" id="openNodesModal">
        🌐 Узлы сети
      </button>
      <a href="logout.php" class="sidebar-action-btn" style="text-decoration:none">
        🚪 Выйти
      </a>
    </div>

  </aside>

  <!-- ======= MAIN AREA ======= -->
  <main class="chat-area" id="chatArea">
    <div class="welcome-screen">
      <div class="welcome-icon">💬</div>
      <h3>Добро пожаловать, <?= htmlspecialchars($username) ?>!</h3>
      <p>Выберите чат из списка или найдите пользователя по логину, чтобы начать переписку.</p>
      <div style="margin-top:8px;font-size:13px;color:var(--text-muted)">
        Ваш ID: <strong style="color:var(--accent-light)"><?= $userId ?></strong>
      </div>
    </div>
  </main>

</div>

<!-- === NODE SELECTION MODAL === -->
<div class="modal-overlay" id="nodesModal">
  <div class="modal" style="position:relative">
    <h3>🌐 Выбор узла передачи</h3>
    <button class="icon-btn modal-close" id="closeNodesModal">✕</button>

    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:20px">
      Сообщения передаются через выбранный WebSocket-узел. Подключитесь к публичному узлу или введите адрес своего.
    </p>

    <div class="node-list" id="nodeList">
      <div class="node-list-item" data-ws="ws://localhost:3001" id="localNode">
        <div class="node-dot connected"></div>
        <div class="node-info">
          <div class="node-name">Локальный узел</div>
          <div class="node-addr">ws://localhost:3001</div>
        </div>
        <span class="node-ping" id="localNodePing">...</span>
      </div>
    </div>

    <div class="field" style="margin-bottom:12px">
      <label>Свой узел (адрес WebSocket)</label>
      <input
        type="text"
        id="customNodeInput"
        placeholder="ws://192.168.1.100:3001"
        style="width:100%"
      >
    </div>

    <button class="btn-submit" id="connectCustomNode" style="margin-bottom:0">
      Подключиться к этому узлу
    </button>
  </div>
</div>

<!-- === TOASTS === -->
<div class="toasts" id="toasts"></div>

<script>
// ============================================================
//  NexTalk App — Client JS
// ============================================================

const APP_USER = <?= json_encode(['id' => $userId, 'username' => $username]) ?>;
const DEFAULT_NODE = '<?= $defaultNode ?>';

// WS state
let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 1500;
let wsCurrentUrl = localStorage.getItem('nt-node') || DEFAULT_NODE;

function updateNodeStatus(state, text) {
  const dot = document.getElementById('nodeStatusDot');
  const label = document.getElementById('nodeStatusText');
  if (dot) dot.className = 'node-dot ' + state;
  if (label) label.textContent = text;
}

// ---- Theme ----
(function applyTheme() {
  const saved = localStorage.getItem('nt-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
  document.getElementById('themeToggleApp').textContent = saved === 'dark' ? '🌙' : '☀️';
})();

document.getElementById('themeToggleApp').addEventListener('click', () => {
  const cur = document.documentElement.getAttribute('data-theme');
  const next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('nt-theme', next);
  document.getElementById('themeToggleApp').textContent = next === 'dark' ? '🌙' : '☀️';
});

// ---- Toast notifications ----
function showToast(message, type = 'info', duration = 4000) {
  const container = document.getElementById('toasts');
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  const icons = { success: '✅', error: '❌', info: '💬' };
  toast.innerHTML = `${icons[type] || '💬'} ${message}`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), duration + 300);
}

// ---- User Search ----
const searchInput = document.getElementById('userSearch');
const searchDropdown = document.getElementById('searchDropdown');
let searchTimer;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  if (q.length < 1) {
    searchDropdown.innerHTML = '';
    searchDropdown.classList.remove('visible');
    return;
  }
  searchTimer = setTimeout(async () => {
    try {
      const resp = await fetch(`search_users.php?q=${encodeURIComponent(q)}`);
      const users = await resp.json();
      renderSearchResults(users);
    } catch(e) {}
  }, 300);
});

function renderSearchResults(users) {
  if (!users.length) {
    searchDropdown.innerHTML = '<div class="search-no-result">Никого не найдено</div>';
    searchDropdown.classList.add('visible');
    return;
  }
  searchDropdown.innerHTML = users.map(u => `
    <div class="search-result-item" data-id="${u.id}">
      <div class="avatar" style="width:32px;height:32px;font-size:13px">
        ${escHtml(u.username[0].toUpperCase())}
      </div>
      <div>
        <div class="result-name">${escHtml(u.username)}</div>
        <div class="result-id">ID: ${u.id}</div>
      </div>
    </div>
  `).join('');
  searchDropdown.classList.add('visible');

  searchDropdown.querySelectorAll('.search-result-item').forEach(item => {
    item.addEventListener('click', () => {
      startChat(item.dataset.id);
    });
  });
}

function startChat(targetUserId) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'create_chat.php';
  form.innerHTML = `<input type="hidden" name="user_id" value="${targetUserId}">`;
  document.body.appendChild(form);
  form.submit();
}

document.addEventListener('click', e => {
  if (!e.target.closest('#searchBox')) {
    searchDropdown.classList.remove('visible');
  }
});

// ---- Node Modal ----
const nodesModal = document.getElementById('nodesModal');
document.getElementById('openNodesModal').addEventListener('click', () => {
  nodesModal.classList.add('open');
  pingLocalNode();
});
document.getElementById('closeNodesModal').addEventListener('click', () => {
  nodesModal.classList.remove('open');
});
nodesModal.addEventListener('click', e => {
  if (e.target === nodesModal) nodesModal.classList.remove('open');
});

document.getElementById('connectCustomNode').addEventListener('click', () => {
  const addr = document.getElementById('customNodeInput').value.trim();
  if (!addr.startsWith('ws://') && !addr.startsWith('wss://')) {
    showToast('Адрес должен начинаться с ws:// или wss://', 'error');
    return;
  }
  localStorage.setItem('nt-node', addr);
  nodesModal.classList.remove('open');
  wsConnect(addr);
});

// Node list item click
document.querySelectorAll('.node-list-item').forEach(item => {
  item.addEventListener('click', () => {
    const ws = item.dataset.ws;
    localStorage.setItem('nt-node', ws);
    document.querySelectorAll('.node-list-item').forEach(i => i.classList.remove('selected'));
    item.classList.add('selected');
    nodesModal.classList.remove('open');
    wsConnect(ws);
  });
});

const AUTH_API = '/nextalk-auth';

let jwtToken  = null;
let jwtExpires = 0;

// ---- JWT: get token from auth server ----
async function getJWT() {
  if (jwtToken && jwtExpires > Date.now() / 1000 + 60) return jwtToken;

  const cached    = localStorage.getItem('nt-jwt');
  const cachedExp = parseInt(localStorage.getItem('nt-jwt-exp') || '0');
  if (cached && cachedExp > Date.now() / 1000 + 60) {
    jwtToken  = cached;
    jwtExpires = cachedExp;
    return jwtToken;
  }

  try {
    const resp = await fetch('api/token.php');
    const data = await resp.json();
    if (data.ok && data.token) {
      jwtToken  = data.token;
      jwtExpires = Math.floor(Date.now() / 1000) + (data.expires_in || 86400 * 7);
      localStorage.setItem('nt-jwt', jwtToken);
      localStorage.setItem('nt-jwt-exp', String(jwtExpires));
      return jwtToken;
    }
  } catch(e) { console.error('[JWT] Error:', e); }
  return null;
}

// ---- WebSocket connection ----
async function wsConnect(url) {
  wsCurrentUrl = url;
  if (ws) { try { ws.close(); } catch(e) {} ws = null; }

  updateNodeStatus('connecting', 'Получаем токен...');

  const token = await getJWT();
  if (!token) {
    updateNodeStatus('error', 'Нет JWT-токена');
    showToast('Не удалось получить токен', 'error');
    scheduleReconnect();
    return;
  }

  updateNodeStatus('connecting', `Подключение к ${url}...`);

  try { ws = new WebSocket(url); }
  catch(e) { updateNodeStatus('error', 'Ошибка URL'); scheduleReconnect(); return; }

  ws.addEventListener('open', () => {
    wsReconnectDelay = 1500;
    wsSend({ type: 'auth', token }); // ← передаём JWT
  });
  ws.addEventListener('message', e => {
    let msg; try { msg = JSON.parse(e.data); } catch(x) { return; }
    handleWsMessage(msg);
  });
  ws.addEventListener('close', () => {
    updateNodeStatus('error', 'Не подключён — переподключение...');
    scheduleReconnect();
  });
  ws.addEventListener('error', () => updateNodeStatus('error', 'Ошибка WebSocket'));
}

function scheduleReconnect() {
  clearTimeout(wsReconnectTimer);
  wsReconnectTimer = setTimeout(() => {
    wsReconnectDelay = Math.min(wsReconnectDelay * 1.5, 15000);
    wsConnect(wsCurrentUrl);
  }, wsReconnectDelay);
}

function wsSend(data) {
  if (ws && ws.readyState === WebSocket.OPEN) { ws.send(JSON.stringify(data)); return true; }
  return false;
}

function handleWsMessage(msg) {
  switch(msg.type) {
    case 'auth_ok':
      updateNodeStatus('connected', wsCurrentUrl.replace(/^wss?:\/\//, ''));
      showToast('Подключено к узлу: ' + (msg.node_name || 'Node'), 'success', 2500);
      break;
    case 'auth_error':
      updateNodeStatus('error', 'Ошибка JWT: ' + msg.message);
      showToast('Ошибка авторизации: ' + msg.message, 'error');
      localStorage.removeItem('nt-jwt');
      jwtToken = null;
      scheduleReconnect();
      break;
    case 'user_status':
      console.log(`[WS] ${msg.username} is ${msg.online ? 'online' : 'offline'}`);
      break;
    case 'error': showToast('Узел: ' + msg.message, 'error'); break;
    case 'server_shutdown': updateNodeStatus('error', 'Узел выключается...'); scheduleReconnect(); break;
    case 'pong': break;
  }
}


// ---- Ping local node ----
async function pingLocalNode() {
  const pingEl = document.getElementById('localNodePing');
  try {
    const t = Date.now();
    const resp = await fetch('http://localhost:3001/health');
    const ms = Date.now() - t;
    if (resp.ok) {
      pingEl.textContent = ms + ' ms';
      pingEl.style.color = ms < 100 ? 'var(--success)' : ms < 300 ? 'var(--warning)' : 'var(--danger)';
    } else {
      pingEl.textContent = 'недоступен';
      pingEl.style.color = 'var(--danger)';
    }
  } catch(e) {
    pingEl.textContent = 'офлайн';
    pingEl.style.color = 'var(--danger)';
  }
}

// ---- Helpers ----
function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// ---- Init ----
wsConnect(wsCurrentUrl);

// Ping heartbeat every 30s
setInterval(() => wsSend({ type: 'ping' }), 30000);
</script>
</body>
</html>