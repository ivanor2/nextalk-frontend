<?php
require_once 'db.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$username = $_SESSION['username'];
$chatId   = (int)($_GET['id'] ?? 0);

// Проверка доступа к чату
$chatResponse = callNodeApi('GET', "/chats/$chatId");
if (!isset($chatResponse['ok']) || !$chatResponse['ok']) {
    die('У вас нет доступа к этому чату или он не существует.');
}
$chat = $chatResponse['chat'];

// POST: сохранить сообщение в БД (вызывается из JS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $content = trim($_POST['message']);
    if (mb_strlen($content) > 0 && mb_strlen($content) <= 4000) {
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
if ($chat['type'] === 'private') {
    $companionName = $chat['companion_name'] ?: 'Чат';
    $companionId = $chat['companion_id'];
} else {
    $companionName = $chat['name'];
}

// Начальные сообщения
$msgResponse = callNodeApi('GET', "/messages?chat_id=$chatId&limit=100");
$messages = [];
if (isset($msgResponse['ok']) && $msgResponse['ok']) {
    $messages = $msgResponse['messages'] ?? [];
}
$lastMessageId = !empty($messages) ? (int)end($messages)['id'] : 0;
$companionInit = mb_strtoupper(mb_substr($companionName, 0, 1));
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NexTalk — <?= htmlspecialchars($companionName) ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app" id="app">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <div class="sidebar-user">
        <a href="index.php" class="icon-btn" title="К чатам" style="font-size:20px;text-decoration:none">←</a>
        <div class="sidebar-user-info">
          <div class="sidebar-username"><?= htmlspecialchars($username) ?></div>
          <div class="sidebar-tag">ID: <?= $userId ?></div>
        </div>
        <button class="icon-btn" id="themeToggleApp" title="Тема">🌙</button>
      </div>
    </div>

    <div class="chat-list">
      <div class="chat-list-label">Текущий чат</div>
      <div class="chat-item active">
        <div class="avatar"><?= $companionInit ?></div>
        <div class="chat-item-body">
          <div class="chat-item-name"><?= htmlspecialchars($companionName) ?></div>
          <div class="chat-item-preview" id="sidebarStatus">Загружаем...</div>
        </div>
      </div>
      <div class="chat-list-label" style="margin-top:16px">Навигация</div>
      <a href="index.php" class="chat-item" style="color:var(--accent)">
        <span style="font-size:18px">←</span>
        <div class="chat-item-body"><div class="chat-item-name">Все чаты</div></div>
      </a>
    </div>

    <!-- Node status bar -->
    <div class="node-status-bar" id="nodeStatusBar">
      <div class="node-dot connecting" id="nodeStatusDot"></div>
      <span class="node-status-text" id="nodeStatusText">Подключение к узлу...</span>
      <button class="icon-btn" id="changeNodeBtn" title="Сменить узел" style="width:28px;height:28px;font-size:14px">🔌</button>
    </div>

    <div class="sidebar-bottom">
      <button class="sidebar-action-btn" id="openNodesModal">🌐 Узлы</button>
      <a href="logout.php" class="sidebar-action-btn" style="text-decoration:none">🚪 Выйти</a>
    </div>
  </aside>

  <!-- CHAT AREA -->
  <main class="chat-area">
    <div class="chat-header">
      <div class="avatar" id="companionAvatar"><?= $companionInit ?></div>
      <div class="chat-header-info">
        <div class="chat-header-name"><?= htmlspecialchars($companionName) ?></div>
        <div class="chat-header-status" id="chatStatus">Загружаем...</div>
      </div>
      <div class="chat-header-actions">
        <div class="node-indicator" id="nodeIndicator" title="Сменить узел">
          <div class="node-dot connecting" id="nodeIndicatorDot"></div>
          <span id="nodeIndicatorText">Узел</span>
        </div>
        <button class="icon-btn" id="refreshBtn" title="Обновить">↻</button>
      </div>
    </div>

    <div class="messages-wrap" id="messagesWrap">
      <?php if (empty($messages)): ?>
        <div class="empty-chat" id="emptyChat">
          <div style="font-size:48px;margin-bottom:12px">💬</div>
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

    <div class="message-input-wrap">
      <div class="message-input-box">
        <textarea id="msgInput" placeholder="Сообщение..." rows="1" maxlength="4000"></textarea>
        <button class="send-btn" id="sendBtn" title="Отправить (Enter)">➤</button>
      </div>
    </div>
  </main>
</div>

<!-- Node Modal -->
<div class="modal-overlay" id="nodesModal">
  <div class="modal" style="position:relative">
    <h3>🌐 Выбор узла передачи</h3>
    <button class="icon-btn modal-close" id="closeNodesModal">✕</button>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px">
      Узлы из реестра auth-сервера. Выберите или введите свой адрес.
    </p>
    <div class="node-list" id="nodeListModal">
      <div style="text-align:center;color:var(--text-muted);padding:16px;font-size:13px">Загрузка узлов...</div>
    </div>
    <div class="field" style="margin-bottom:12px">
      <label>Свой адрес WebSocket</label>
      <input type="text" id="customNodeInput" placeholder="ws://192.168.1.100:3001" style="width:100%">
    </div>
    <button class="btn-submit" id="connectCustomNode" style="margin-bottom:0">Подключиться</button>
  </div>
</div>

<div class="toasts" id="toasts"></div>

<script>
// ═══════════════════════════════════════════════════════════
//  NexTalk Chat — с JWT-аутентификацией на WebSocket-узле
// ═══════════════════════════════════════════════════════════

const APP_USER     = <?= json_encode(['id' => $userId, 'username' => $username]) ?>;
const CHAT_ID      = <?= $chatId ?>;
const COMPANION    = <?= json_encode(['name' => $companionName, 'id' => $companionId]) ?>;
const DEFAULT_NODE = 'ws://localhost:3001';
const AUTH_API     = '/nextalk-auth'; // базовый URL auth-сервера

let lastMessageId  = <?= $lastMessageId ?>;
let ws = null;
let wsCurrentUrl   = localStorage.getItem('nt-node') || DEFAULT_NODE;
let wsReconnectTimer = null;
let wsReconnectDelay = 1500;
let jwtToken       = null;    // ← JWT от auth-сервера
let jwtExpires     = 0;
let isTyping       = false;
let typingTimer    = null;
let typingHideTimer = null;
let pollTimer      = null;

// ── Тема ────────────────────────────────────────────────────
(function() {
  const t = localStorage.getItem('nt-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
  document.getElementById('themeToggleApp').textContent = t === 'dark' ? '🌙' : '☀️';
})();
document.getElementById('themeToggleApp').addEventListener('click', () => {
  const c = document.documentElement.getAttribute('data-theme');
  const n = c === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', n);
  localStorage.setItem('nt-theme', n);
  document.getElementById('themeToggleApp').textContent = n === 'dark' ? '🌙' : '☀️';
});

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'info', dur = 3500) {
  const c = document.getElementById('toasts');
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  const icons = { success: '✅', error: '❌', info: '💬' };
  t.innerHTML = `${icons[type] || '💬'} ${escHtml(msg)}`;
  c.appendChild(t);
  setTimeout(() => t.remove(), dur + 300);
}

// ── Helpers ────────────────────────────────────────────────
function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function scrollToBottom(smooth = false) {
  const w = document.getElementById('messagesWrap');
  w.scrollTo({ top: w.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}
function formatTime(ts) {
  const d = new Date(ts);
  return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
}

// ── JWT: получить токен от auth-сервера ───────────────────
async function getJWT() {
  // Если токен ещё действует — используем кешированный
  if (jwtToken && jwtExpires > Date.now() / 1000 + 60) {
    return jwtToken;
  }

  // Также смотрим localStorage (сохраняем между перезагрузками)
  const cached = localStorage.getItem('nt-jwt');
  const cachedExp = parseInt(localStorage.getItem('nt-jwt-exp') || '0');
  if (cached && cachedExp > Date.now() / 1000 + 60) {
    jwtToken  = cached;
    jwtExpires = cachedExp;
    return jwtToken;
  }

  // Запрашиваем у PHP-bridge (он обращается к auth-серверу)
  try {
    const resp = await fetch('api/token.php');
    const data = await resp.json();
    if (data.ok && data.token) {
      jwtToken  = data.token;
      jwtExpires = Math.floor(Date.now() / 1000) + (data.expires_in || 86400 * 7);
      localStorage.setItem('nt-jwt', jwtToken);
      localStorage.setItem('nt-jwt-exp', String(jwtExpires));
      console.log('[JWT] Token obtained from', data.source || 'api', '— expires in', data.expires_in, 's');
      return jwtToken;
    }
  } catch(e) {
    console.error('[JWT] Failed to fetch token:', e);
  }

  return null;
}

// ── Render message ────────────────────────────────────────
function renderMessage(msg) {
  const isMe = (msg.sender === APP_USER.username || msg.sender_id == APP_USER.id);
  const wrap = document.getElementById('messagesWrap');
  const empty = wrap.querySelector('#emptyChat');
  if (empty) empty.remove();

  const div = document.createElement('div');
  div.className = `message ${isMe ? 'out' : 'in'}`;
  div.dataset.id = msg.id || ('ws-' + Date.now());

  const timeStr = msg.timestamp
    ? formatTime(msg.timestamp)
    : (msg.created_at ? formatTime(msg.created_at) : '');

  const author = !isMe
    ? `<div class="msg-author">${escHtml(msg.display || msg.sender || msg.username || COMPANION.name)}</div>`
    : '';

  const statusHtml = isMe ? `<span class="msg-status">✓✓</span>` : '';
  const content = msg.content || '';

  div.innerHTML = `
    ${author}
    <div class="msg-bubble">${escHtml(content).replace(/\n/g, '<br>')}</div>
    <div class="msg-footer">
      <span class="msg-time">${timeStr}</span>
      ${statusHtml}
    </div>
  `;

  const typing = document.getElementById('typingIndicator');
  wrap.insertBefore(div, typing);

  const atBottom = wrap.scrollHeight - wrap.scrollTop - wrap.clientHeight < 120;
  if (atBottom || isMe) scrollToBottom(true);
}

// ── Node status UI ────────────────────────────────────────
function setNodeStatus(state, text) {
  ['nodeStatusDot', 'nodeIndicatorDot'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.className = 'node-dot ' + state;
  });
  const s = document.getElementById('nodeStatusText');
  const n = document.getElementById('nodeIndicatorText');
  if (s) s.textContent = text;
  if (n) n.textContent = text.length > 28 ? text.substring(0, 25) + '...' : text;
}

// ── WebSocket connect (с JWT) ─────────────────────────────
async function wsConnect(url) {
  wsCurrentUrl = url;

  // Закрываем старое соединение
  if (ws) { try { ws.close(); } catch(e) {} ws = null; }

  setNodeStatus('connecting', 'Подключение...');

  // 1. Получаем JWT
  const token = await getJWT();
  if (!token) {
    setNodeStatus('error', 'Нет JWT-токена');
    showToast('Не удалось получить токен авторизации', 'error');
    scheduleReconnect();
    return;
  }

  // 2. Открываем WebSocket
  try {
    ws = new WebSocket(url);
  } catch(e) {
    setNodeStatus('error', 'Неверный URL');
    scheduleReconnect();
    return;
  }

  ws.addEventListener('open', () => {
    wsReconnectDelay = 1500;
    // 3. Авторизуемся на узле — передаём JWT
    wsSend({ type: 'auth', token });
    console.log('[WS] Connected, sending JWT auth...');
  });

  ws.addEventListener('message', e => {
    let msg;
    try { msg = JSON.parse(e.data); } catch(x) { return; }
    handleWsMsg(msg);
  });

  ws.addEventListener('close', (ev) => {
    console.log('[WS] Closed:', ev.code, ev.reason);
    setNodeStatus('error', 'Не подключён');
    scheduleReconnect();
  });

  ws.addEventListener('error', () => {
    setNodeStatus('error', 'Ошибка WebSocket');
  });
}

function scheduleReconnect() {
  clearTimeout(wsReconnectTimer);
  wsReconnectTimer = setTimeout(() => {
    wsReconnectDelay = Math.min(wsReconnectDelay * 1.5, 15000);
    wsConnect(wsCurrentUrl);
  }, wsReconnectDelay);
}

function wsSend(data) {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify(data)); return true;
  }
  return false;
}

// ── Handle WebSocket messages ─────────────────────────────
function handleWsMsg(msg) {
  switch (msg.type) {

    case 'auth_ok':
      // JWT принят узлом!
      setNodeStatus('connected', wsCurrentUrl.replace(/^wss?:\/\//, ''));
      showToast(`Подключено · ${msg.node_name || 'узел'}`, 'success', 2500);
      console.log('[WS] auth_ok — node:', msg.node_id, 'token_exp:', msg.token_exp);

      // Входим в комнату чата
      wsSend({ type: 'join', chat_id: CHAT_ID });

      // Статус
      document.getElementById('chatStatus').textContent = 'онлайн';
      document.getElementById('chatStatus').className = 'chat-header-status online';
      document.getElementById('sidebarStatus').textContent = 'онлайн';
      break;

    case 'auth_error':
      // JWT отклонён
      console.error('[WS] auth_error:', msg.message);
      setNodeStatus('error', 'Ошибка JWT: ' + msg.message);
      showToast('Ошибка авторизации на узле: ' + msg.message, 'error');

      // Чистим кеш токена — возможно истёк
      localStorage.removeItem('nt-jwt');
      localStorage.removeItem('nt-jwt-exp');
      jwtToken = null;

      // Переподключимся с новым токеном
      scheduleReconnect();
      break;

    case 'joined':
      startPolling(); // Polling как fallback + синхронизация с БД
      break;

    case 'message':
      if (String(msg.chat_id) === String(CHAT_ID)) {
        // Проверяем — не отрисовано ли уже
        if (!document.querySelector(`[data-id="${msg.id}"]`)) {
          renderMessage(msg);
        }
        showTypingIndicator(false);
      }
      break;

    case 'sent':
      // Подтверждение что сообщение принято узлом
      console.log('[WS] sent:', msg.id);
      break;

    case 'typing':
      if (String(msg.chat_id) === String(CHAT_ID)) {
        showTypingIndicator(msg.is_typing);
        if (msg.is_typing) {
          clearTimeout(typingHideTimer);
          typingHideTimer = setTimeout(() => showTypingIndicator(false), 4000);
        }
        const status = document.getElementById('chatStatus');
        const sidebar = document.getElementById('sidebarStatus');
        if (msg.is_typing) {
          status.textContent = 'печатает...';
          status.className = 'chat-header-status typing';
          sidebar.textContent = 'печатает...';
        } else if (status.className.includes('typing')) {
          status.textContent = 'онлайн';
          status.className = 'chat-header-status online';
          sidebar.textContent = 'онлайн';
        }
      }
      break;

    case 'user_status':
      if (msg.username === COMPANION.name) {
        const status  = document.getElementById('chatStatus');
        const sidebar = document.getElementById('sidebarStatus');
        const avatar  = document.getElementById('companionAvatar');
        if (msg.online) {
          status.textContent  = 'онлайн';
          status.className    = 'chat-header-status online';
          sidebar.textContent = 'онлайн';
          if (!avatar.querySelector('.online-dot')) {
            const dot = document.createElement('div');
            dot.className = 'online-dot';
            avatar.appendChild(dot);
          }
        } else {
          status.textContent  = 'не в сети';
          status.className    = 'chat-header-status';
          sidebar.textContent = 'не в сети';
          avatar.querySelector('.online-dot')?.remove();
        }
      }
      break;

    case 'kicked':
      showToast('Вы вошли с другого устройства', 'info');
      break;

    case 'server_shutdown':
      setNodeStatus('error', 'Узел выключается...');
      scheduleReconnect();
      break;

    case 'error':
      console.warn('[WS] error:', msg.message);
      showToast('Узел: ' + msg.message, 'error');
      break;

    case 'pong':
      break;
  }
}

function showTypingIndicator(show) {
  const el = document.getElementById('typingIndicator');
  el.classList.toggle('visible', show);
  if (show) scrollToBottom(true);
}

// ── HTTP Polling (fallback + синхронизация с БД) ──────────
function startPolling() {
  stopPolling();
  pollTimer = setInterval(fetchNewMessages, 3000);
}
function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

async function fetchNewMessages() {
  try {
    const resp = await fetch(`get_messages.php?chat_id=${CHAT_ID}&last_id=${lastMessageId}&t=${Date.now()}`);
    const data = await resp.json();
    if (!data.ok || !data.messages?.length) return;
    data.messages.forEach(msg => {
      if (msg.sender_id == APP_USER.id) { lastMessageId = Math.max(lastMessageId, msg.id); return; }
      if (document.querySelector(`[data-id="${msg.id}"]`)) { lastMessageId = Math.max(lastMessageId, msg.id); return; }
      renderMessage({ id: msg.id, sender: msg.username, sender_id: msg.sender_id, content: msg.content, timestamp: msg.created_at });
      lastMessageId = Math.max(lastMessageId, msg.id);
    });
  } catch(e) {}
}

// ── Send Message ──────────────────────────────────────────
const msgInput = document.getElementById('msgInput');
const sendBtn  = document.getElementById('sendBtn');

async function sendMessage() {
  const content = msgInput.value.trim();
  if (!content) return;

  msgInput.value = '';
  msgInput.style.height = '';
  sendBtn.disabled = true;

  // 1. Отправляем через WebSocket (мгновенно другому пользователю)
  const wsSent = wsSend({ type: 'send', chat_id: CHAT_ID, content, recipient: COMPANION.name });
  if (!wsSent) console.warn('[WS] Not connected — message will go via HTTP only');

  // 2. Сохраняем в БД через HTTP (надёжно, даже если WS недоступен)
  try {
    const fd = new FormData();
    fd.append('message', content);
    const resp = await fetch(`chat.php?id=${CHAT_ID}`, {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd,
    });
    const data = await resp.json();
    if (data.ok) {
      renderMessage({
        id: data.id, sender: APP_USER.username, sender_id: APP_USER.id,
        content, timestamp: data.timestamp || new Date().toISOString(),
      });
      lastMessageId = Math.max(lastMessageId, data.id || 0);
    }
  } catch(e) {
    showToast('Ошибка отправки', 'error');
  }

  sendBtn.disabled = false;
  msgInput.focus();

  // Сброс typing
  if (isTyping) { isTyping = false; wsSend({ type: 'typing', chat_id: CHAT_ID, is_typing: false }); }
}

sendBtn.addEventListener('click', sendMessage);
msgInput.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// Auto-resize + typing indicator
msgInput.addEventListener('input', () => {
  msgInput.style.height = 'auto';
  msgInput.style.height = Math.min(msgInput.scrollHeight, 160) + 'px';
  if (!isTyping) { isTyping = true; wsSend({ type: 'typing', chat_id: CHAT_ID, is_typing: true }); }
  clearTimeout(typingTimer);
  typingTimer = setTimeout(() => { isTyping = false; wsSend({ type: 'typing', chat_id: CHAT_ID, is_typing: false }); }, 2500);
});

// ── Node Modal ────────────────────────────────────────────
const nodesModal = document.getElementById('nodesModal');
document.getElementById('openNodesModal').addEventListener('click', () => { nodesModal.classList.add('open'); loadNodes(); });
document.getElementById('closeNodesModal').addEventListener('click', () => nodesModal.classList.remove('open'));
nodesModal.addEventListener('click', e => { if (e.target === nodesModal) nodesModal.classList.remove('open'); });
document.getElementById('nodeIndicator').addEventListener('click', () => { nodesModal.classList.add('open'); loadNodes(); });
document.getElementById('changeNodeBtn').addEventListener('click', () => { nodesModal.classList.add('open'); loadNodes(); });

async function loadNodes() {
  const list = document.getElementById('nodeListModal');
  list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:16px;font-size:13px">⏳ Загрузка узлов...</div>';

  try {
    const token = await getJWT();
    const resp = await fetch(`http://localhost:3001/api/nodes`, {
      headers: token ? { 'Authorization': `Bearer ${token}` } : {}
    });
    const data = await resp.json();

    if (data.ok && data.nodes && data.nodes.length) {
      list.innerHTML = data.nodes.map(n => `
        <div class="node-list-item ${wsCurrentUrl === n.ws_url ? 'selected' : ''}"
             data-ws="${escHtml(n.ws_url)}">
          <div class="node-dot ${n.online ? 'connected' : 'error'}"></div>
          <div class="node-info">
            <div class="node-name">${escHtml(n.name)}</div>
            <div class="node-addr">${escHtml(n.ws_url)}</div>
          </div>
          <span class="node-ping" style="color:${n.online ? 'var(--success)' : 'var(--danger)'}">
            ${n.online ? '● онлайн' : '● офлайн'}
          </span>
        </div>
      `).join('');

      list.querySelectorAll('.node-list-item').forEach(item => {
        item.addEventListener('click', () => {
          const wsUrl = item.dataset.ws;
          localStorage.setItem('nt-node', wsUrl);
          nodesModal.classList.remove('open');
          wsConnect(wsUrl);
        });
      });
    } else {
      list.innerHTML = '';
    }
  } catch(e) {
    list.innerHTML = '';
  }


  // Всегда добавляем локальный
  const localItem = document.createElement('div');
  localItem.className = `node-list-item ${wsCurrentUrl === 'ws://localhost:3001' ? 'selected' : ''}`;
  localItem.dataset.ws = 'ws://localhost:3001';
  localItem.innerHTML = `
    <div class="node-dot connected"></div>
    <div class="node-info">
      <div class="node-name">Локальный узел</div>
      <div class="node-addr">ws://localhost:3001</div>
    </div>
    <span id="localPing" class="node-ping">...</span>
  `;
  localItem.addEventListener('click', () => {
    localStorage.setItem('nt-node', 'ws://localhost:3001');
    nodesModal.classList.remove('open');
    wsConnect('ws://localhost:3001');
  });
  document.getElementById('nodeListModal').prepend(localItem);
  pingLocalNode();
}

async function pingLocalNode() {
  try {
    const t = Date.now();
    const r = await fetch('http://localhost:3001/health');
    const ms = Date.now() - t;
    const el = document.getElementById('localPing');
    if (el) { el.textContent = ms + ' ms'; el.style.color = ms < 100 ? 'var(--success)' : 'var(--warning)'; }
  } catch(e) {
    const el = document.getElementById('localPing');
    if (el) { el.textContent = 'офлайн'; el.style.color = 'var(--danger)'; }
  }
}

document.getElementById('connectCustomNode').addEventListener('click', () => {
  const addr = document.getElementById('customNodeInput').value.trim();
  if (!addr.startsWith('ws://') && !addr.startsWith('wss://')) {
    showToast('Адрес должен начинаться с ws:// или wss://', 'error'); return;
  }
  localStorage.setItem('nt-node', addr);
  nodesModal.classList.remove('open');
  wsConnect(addr);
});

document.getElementById('refreshBtn').addEventListener('click', fetchNewMessages);

// ── Init ──────────────────────────────────────────────────
scrollToBottom();
wsConnect(wsCurrentUrl);

// Heartbeat
setInterval(() => wsSend({ type: 'ping' }), 30000);

// Обновляем JWT за 5 минут до истечения
setInterval(async () => {
  if (jwtExpires && jwtExpires - Date.now() / 1000 < 300) {
    console.log('[JWT] Refreshing token...');
    localStorage.removeItem('nt-jwt');
    jwtToken = null;
    const token = await getJWT();
    if (token) wsSend({ type: 'auth', token }); // реавторизация
  }
}, 60000);
</script>
</body>
</html>