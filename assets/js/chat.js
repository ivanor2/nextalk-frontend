/**
 * NexTalk Messenger — Chat Client & Realtime WebSocket Module
 */

let lastMessageId = (window.CHAT_CONFIG && window.CHAT_CONFIG.lastMessageId) || 0;
let ws = null;
const safeStorage = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    remove(k) { try { localStorage.removeItem(k); } catch (e) {} }
};

let defaultNode = (window.CHAT_CONFIG && window.CHAT_CONFIG.defaultNode) || 'ws://127.0.0.1:3001';
let wsCurrentUrl = safeStorage.get('nt-node') || defaultNode;
if (window.location.protocol === 'https:' && wsCurrentUrl.startsWith('ws://')) {
    wsCurrentUrl = defaultNode.startsWith('wss://') ? defaultNode : wsCurrentUrl.replace(/^ws:\/\//, 'wss://');
}
let wsReconnectTimer = null;
let wsReconnectDelay = 1500;
let jwtToken = null;
let jwtExpires = 0;
let isTyping = false;
let typingTimer = null;
let typingHideTimer = null;
let pollTimer = null;
let unreadCount = 0;
const originalTitle = document.title;

// ── Notification Sound ──────────────────────────────────────
function playNotificationSound() {
    if (localStorage.getItem('nt-sound') === 'false') return;
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.25);
    } catch (e) {}
}

// ── System Notifications ────────────────────────────────────
function triggerNotification(sender, text) {
    if (localStorage.getItem('nt-notif') !== 'true') return;
    if (Notification.permission !== 'granted') return;
    if (!document.hidden) return;

    const showPreview = localStorage.getItem('nt-preview') !== 'false';
    const bodyText = showPreview ? text : 'Новое сообщение';

    try {
        const notif = new Notification(`NexTalk: ${sender}`, {
            body: bodyText,
            icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50" fill="%236C63FF"/></svg>'
        });
        notif.onclick = () => {
            window.focus();
            notif.close();
        };
    } catch (e) {}
}

window.addEventListener('focus', () => {
    unreadCount = 0;
    document.title = originalTitle;
    const cid = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;
    if (cid) fetch(`api/mark_read.php?chat_id=${cid}`, { method: 'POST' }).catch(() => {});
});

function incrementUnread() {
    if (document.hidden) {
        unreadCount++;
        document.title = `(${unreadCount}) ${originalTitle}`;
    }
}

function scrollToBottom(smooth = false) {
    const w = document.getElementById('messagesWrap');
    if (w) w.scrollTo({ top: w.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

function formatTime(ts) {
    if (!ts) return '';
    const safeTs = (typeof ts === 'string' && ts.includes(' ') && !ts.includes('T'))
        ? ts.replace(' ', 'T')
        : ts;
    const d = new Date(safeTs);
    if (isNaN(d.getTime())) return '';
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

// ── JWT Token Retrieval ─────────────────────────────────────
async function getJWT() {
    if (window.CHAT_CONFIG && window.CHAT_CONFIG.token) {
        jwtToken = window.CHAT_CONFIG.token;
        jwtExpires = Math.floor(Date.now() / 1000) + 86400 * 7;
        return jwtToken;
    }
    if (jwtToken && jwtExpires > Date.now() / 1000 + 60) return jwtToken;
    try {
        const resp = await fetch('api/token.php');
        const data = await resp.json();
        if (data.ok && data.token) {
            jwtToken = data.token;
            jwtExpires = Math.floor(Date.now() / 1000) + (data.expires_in || 86400 * 7);
            return jwtToken;
        }
    } catch (e) {}
    return null;
}

function formatMessagePreview(content) {
    if (!content) return '';
    const trimmed = String(content).trim();
    if (trimmed.startsWith('[attachment:')) {
        const m = trimmed.match(/^\[attachment:(\{[\s\S]*\})\]$/);
        if (m) {
            try {
                const att = JSON.parse(m[1]);
                const icon = att.is_image ? '🖼️' : '📎';
                return `${icon} ${att.filename || 'Вложение'}`;
            } catch (e) {}
        }
        return '📎 Вложение';
    }
    return trimmed;
}

// ── Render Message (Attachments + Bubbles) ──────────────────
function renderMessage(msg) {
    const curUserId = window.CHAT_CONFIG ? window.CHAT_CONFIG.userId : 0;
    const curUsername = window.CHAT_CONFIG ? window.CHAT_CONFIG.username : '';
    const isGroup = window.CHAT_CONFIG ? window.CHAT_CONFIG.isGroup : false;

    const isMe = (msg.sender === curUsername || msg.sender_id == curUserId);
    const wrap = document.getElementById('messagesWrap');
    if (!wrap) return;

    const empty = wrap.querySelector('#emptyChat');
    if (empty) empty.remove();

    const rawContent = msg.content || '';
    const numId = Number(msg.id);
    if (!isNaN(numId) && numId > 0) {
        lastMessageId = Math.max(lastMessageId, numId);
    }

    const div = document.createElement('div');
    div.className = `message ${isMe ? 'out' : 'in'}`;
    div.dataset.id = msg.id || ('ws-' + Date.now());
    div.dataset.content = rawContent;
    div.dataset.sender = msg.sender || msg.username || '';

    const timeStr = msg.timestamp
        ? formatTime(msg.timestamp)
        : (msg.created_at ? formatTime(msg.created_at) : '');

    const author = (!isMe && isGroup)
        ? `<div class="msg-author">${escHtml(msg.display || msg.sender || msg.username || 'Собеседник')}</div>`
        : '';

    let bubbleBody = '';
    const trimmed = rawContent.trim();
    const attMatch = trimmed.match(/^\[attachment:(\{[\s\S]*\})\]$/);
    if (attMatch) {
        try {
            const att = JSON.parse(attMatch[1]);
            if (att.is_image) {
                bubbleBody = `
                    <a href="${escHtml(att.url)}" target="_blank" rel="noopener">
                        <img src="${escHtml(att.url)}" class="img-attachment" alt="${escHtml(att.filename)}">
                    </a>
                    <div class="file-desc-sub">${escHtml(att.filename)} (${formatBytes(att.size)})</div>
                `;
            } else {
                bubbleBody = `
                    <a href="${escHtml(att.url)}" target="_blank" download="${escHtml(att.filename)}" class="file-attachment-card">
                        <div class="file-icon">📄</div>
                        <div class="file-details">
                            <div class="file-name">${escHtml(att.filename)}</div>
                            <div class="file-size">${formatBytes(att.size)} · Нажмите чтобы скачать</div>
                        </div>
                    </a>
                `;
            }
        } catch (e) {
            bubbleBody = escHtml(rawContent).replace(/\n/g, '<br>');
        }
    } else {
        bubbleBody = escHtml(rawContent).replace(/\n/g, '<br>');
    }

    const statusHtml = isMe ? `<span class="msg-status">✓✓</span>` : '';

    div.innerHTML = `
        ${author}
        <div class="msg-bubble">${bubbleBody}</div>
        <div class="msg-footer">
            <span class="msg-time">${timeStr}</span>
            ${statusHtml}
        </div>
    `;

    const typing = document.getElementById('typingIndicator');
    wrap.insertBefore(div, typing);

    const atBottom = wrap.scrollHeight - wrap.scrollTop - wrap.clientHeight < 140;
    if (atBottom || isMe) scrollToBottom(true);
}

// ── Node Status UI ──────────────────────────────────────────
function setNodeStatus(state, text) {
    ['nodeStatusDot', 'nodeIndicatorDot'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.className = 'node-dot ' + state;
    });
    const s = document.getElementById('nodeStatusText');
    const n = document.getElementById('nodeIndicatorText');
    const infoNode = document.getElementById('infoPanelNode');
    if (s) s.textContent = text;
    if (n) n.textContent = text.length > 22 ? text.substring(0, 19) + '...' : text;
    if (infoNode) infoNode.textContent = text;
}

// ── WebSocket Connection ────────────────────────────────────
async function wsConnect(url) {
    wsCurrentUrl = url;
    clearTimeout(wsReconnectTimer);

    if (ws) {
        try {
            ws.onopen = null;
            ws.onmessage = null;
            ws.onclose = null;
            ws.onerror = null;
            ws.close();
        } catch (e) {}
        ws = null;
    }

    setNodeStatus('connecting', 'Подключение...');
    const token = await getJWT();
    if (!token) {
        setNodeStatus('error', 'Нет JWT');
        scheduleReconnect();
        return;
    }

    let socket;
    try {
        socket = new WebSocket(url);
    } catch (e) {
        setNodeStatus('error', 'Ошибка URL');
        scheduleReconnect();
        return;
    }

    ws = socket;

    socket.addEventListener('open', () => {
        if (ws !== socket) return;
        wsReconnectDelay = 1500;
        wsSend({ type: 'auth', token });
    });

    socket.addEventListener('message', e => {
        if (ws !== socket) return;
        let m;
        try { m = JSON.parse(e.data); } catch (x) { return; }
        handleWsMsg(m);
    });

    socket.addEventListener('close', () => {
        if (ws !== socket) return;
        setNodeStatus('error', 'Не подключён');
        scheduleReconnect();
    });

    socket.addEventListener('error', () => {
        if (ws !== socket) return;
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
        ws.send(JSON.stringify(data));
        return true;
    }
    return false;
}

// ── Unread Badge for Other Chats ────────────────────────────
async function updateSidebarBadge() {
    try {
        const resp = await fetch('api/get_chats.php?t=' + Date.now());
        const data = await resp.json();
        if (data.ok && Array.isArray(data.chats)) {
            const currentChatId = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;
            let otherUnread = 0;
            data.chats.forEach(c => {
                if (String(c.id) !== String(currentChatId)) {
                    otherUnread += parseInt(c.unread_count || 0, 10);
                }
            });
            const badge = document.getElementById('sidebarChatsBadge');
            if (badge) {
                if (otherUnread > 0) {
                    badge.textContent = String(otherUnread);
                    badge.classList.remove('d-none');
                } else {
                    badge.textContent = '0';
                    badge.classList.add('d-none');
                }
            }
        }
    } catch (e) {}
}

// ── Handle Incoming Messages ────────────────────────────────
function handleWsMsg(msg) {
    const chatId = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;
    const isGroup = window.CHAT_CONFIG ? window.CHAT_CONFIG.isGroup : false;
    const companionName = window.CHAT_CONFIG ? window.CHAT_CONFIG.companionName : '';

    switch (msg.type) {
        case 'auth_ok':
            setNodeStatus('connected', wsCurrentUrl.replace(/^wss?:\/\//, ''));
            wsSend({ type: 'join', chat_id: chatId });
            if (!isGroup) {
                const cs = document.getElementById('chatStatus');
                const ss = document.getElementById('sidebarStatus');
                if (cs) { cs.textContent = 'онлайн'; cs.className = 'chat-header-status online'; }
                if (ss) ss.textContent = 'онлайн';
            }
            updateSidebarBadge();
            break;

        case 'joined':
            startPolling();
            break;

        case 'message':
            if (String(msg.chat_id) === String(chatId)) {
                const numId = Number(msg.id);
                if (!isNaN(numId) && numId > 0) {
                    lastMessageId = Math.max(lastMessageId, numId);
                }
                const existingById = msg.id ? document.querySelector(`[data-id="${msg.id}"]`) : null;
                const existingByContent = Array.from(document.querySelectorAll('.message.in'))
                    .find(el => el.dataset.content === msg.content && (el.dataset.sender === msg.sender || !el.dataset.sender));

                if (!existingById && !existingByContent) {
                    renderMessage(msg);
                    playNotificationSound();
                    triggerNotification(msg.sender || companionName, formatMessagePreview(msg.content || ''));
                    incrementUnread();
                    if (!document.hidden) {
                        fetch(`api/mark_read.php?chat_id=${chatId}`, { method: 'POST' }).catch(() => {});
                    }
                } else if (existingByContent && !existingById && msg.id) {
                    existingByContent.dataset.id = msg.id;
                }
                showTypingIndicator(false);
            } else {
                // Incoming message from another chat while in this chat
                playNotificationSound();
                const preview = formatMessagePreview(msg.content);
                showToast(`💬 ${msg.sender}: ${preview.substring(0, 30)}`, 'info');
                triggerNotification(msg.sender, preview);
                incrementUnread();

                const badge = document.getElementById('sidebarChatsBadge');
                if (badge) {
                    const current = parseInt(badge.textContent || '0', 10);
                    badge.textContent = String(current + 1);
                    badge.classList.remove('d-none');
                }
            }
            break;

        case 'typing':
            if (String(msg.chat_id) === String(chatId) && !isGroup) {
                showTypingIndicator(msg.is_typing);
                if (msg.is_typing) {
                    clearTimeout(typingHideTimer);
                    typingHideTimer = setTimeout(() => showTypingIndicator(false), 4000);
                }
            }
            break;

        case 'user_status':
            if (msg.username === companionName && !isGroup) {
                const s = document.getElementById('chatStatus');
                const sb = document.getElementById('sidebarStatus');
                const av = document.getElementById('companionAvatar');
                if (msg.online) {
                    if (s) { s.textContent = 'онлайн'; s.className = 'chat-header-status online'; }
                    if (sb) sb.textContent = 'онлайн';
                    if (av && !av.querySelector('.online-dot')) {
                        const dot = document.createElement('div');
                        dot.className = 'online-dot';
                        av.appendChild(dot);
                    }
                } else {
                    if (s) { s.textContent = 'не в сети'; s.className = 'chat-header-status'; }
                    if (sb) sb.textContent = 'не в сети';
                    av?.querySelector('.online-dot')?.remove();
                }
            }
            break;
    }
}

function showTypingIndicator(show) {
    const el = document.getElementById('typingIndicator');
    if (el) {
        el.classList.toggle('visible', show);
        if (show) scrollToBottom(true);
    }
}

// ── Polling Fallback ────────────────────────────────────────
function startPolling() {
    stopPolling();
    pollTimer = setInterval(fetchNewMessages, 3000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function fetchNewMessages() {
    const chatId = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;
    const curUserId = window.CHAT_CONFIG ? window.CHAT_CONFIG.userId : 0;
    try {
        const resp = await fetch(`api/get_messages.php?chat_id=${chatId}&last_id=${lastMessageId}&t=${Date.now()}`);
        const data = await resp.json();
        if (!data.ok || !data.messages?.length) return;
        data.messages.forEach(msg => {
            const numId = Number(msg.id);
            if (!isNaN(numId) && numId > 0) {
                lastMessageId = Math.max(lastMessageId, numId);
            }
            if (msg.sender_id == curUserId) {
                const existingMe = document.querySelector(`[data-id="${msg.id}"]`);
                if (!existingMe) {
                    const temp = Array.from(document.querySelectorAll('.message.out[data-id^="ws-"]'))
                        .find(m => m.dataset.content === msg.content);
                    if (temp) temp.dataset.id = msg.id;
                }
                return;
            }
            if (document.querySelector(`[data-id="${msg.id}"]`)) {
                return;
            }
            // Check if already displayed via temporary ws- ID or matching content
            const existingTemp = Array.from(document.querySelectorAll('.message.in'))
                .find(m => m.dataset.content === msg.content && (m.dataset.sender === msg.username || !m.dataset.sender));
            if (existingTemp) {
                existingTemp.dataset.id = msg.id;
                return;
            }

            renderMessage({
                id: msg.id,
                sender: msg.username,
                sender_id: msg.sender_id,
                content: msg.content,
                timestamp: msg.created_at
            });
            playNotificationSound();
            incrementUnread();
        });
    } catch (e) {}
}

// ── Send Message Logic ──────────────────────────────────────
async function sendTextMessage(customText = null) {
    const msgInput = document.getElementById('msgInput');
    const sendBtn = document.getElementById('sendBtn');
    const chatId = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;
    const curUsername = window.CHAT_CONFIG ? window.CHAT_CONFIG.username : '';
    const curUserId = window.CHAT_CONFIG ? window.CHAT_CONFIG.userId : 0;
    const companionName = window.CHAT_CONFIG ? window.CHAT_CONFIG.companionName : '';

    const content = (customText !== null ? customText : (msgInput ? msgInput.value : '')).trim();
    if (!content) return;

    if (customText === null && msgInput) {
        msgInput.value = '';
        msgInput.style.height = '';
    }
    if (sendBtn) sendBtn.disabled = true;

    // 1. HTTP persistence first (gets the real database message ID)
    try {
        const fd = new FormData();
        fd.append('message', content);
        const resp = await fetch(`chat.php?id=${chatId}`, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const data = await resp.json();
        if (data.ok) {
            const dbId = data.id;
            const timestamp = data.timestamp || new Date().toISOString();
            renderMessage({
                id: dbId,
                sender: curUsername,
                sender_id: curUserId,
                content,
                timestamp,
            });
            if (dbId) {
                lastMessageId = Math.max(lastMessageId, Number(dbId));
            }

            // 2. Broadcast via WebSocket with real DB message ID
            wsSend({
                type: 'send',
                chat_id: chatId,
                content,
                db_message_id: dbId,
                recipient: companionName
            });
        } else {
            showToast(data.error || 'Ошибка отправки', 'error');
        }
    } catch (e) {
        showToast('Ошибка сохранения сообщения', 'error');
    }

    if (sendBtn) sendBtn.disabled = false;
    if (msgInput) msgInput.focus();

    if (isTyping) {
        isTyping = false;
        wsSend({ type: 'typing', chat_id: chatId, is_typing: false });
    }
}

// ── DOM Event Listeners ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const msgInput = document.getElementById('msgInput');
    const sendBtn = document.getElementById('sendBtn');
    const attachBtn = document.getElementById('attachBtn');
    const attachmentInput = document.getElementById('attachmentInput');
    const chatId = window.CHAT_CONFIG ? window.CHAT_CONFIG.chatId : 0;

    sendBtn?.addEventListener('click', () => sendTextMessage());

    msgInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendTextMessage();
        }
    });

    msgInput?.addEventListener('input', () => {
        msgInput.style.height = 'auto';
        msgInput.style.height = Math.min(msgInput.scrollHeight, 160) + 'px';
        if (!isTyping) {
            isTyping = true;
            wsSend({ type: 'typing', chat_id: chatId, is_typing: true });
        }
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            isTyping = false;
            wsSend({ type: 'typing', chat_id: chatId, is_typing: false });
        }, 2500);
    });

    // File attachments
    attachBtn?.addEventListener('click', () => attachmentInput?.click());
    attachmentInput?.addEventListener('change', async () => {
        const file = attachmentInput.files[0];
        if (!file) return;

        if (file.size > 10 * 1024 * 1024) {
            showToast('Размер файла превышает 10 МБ', 'error');
            attachmentInput.value = '';
            return;
        }

        showToast(`Загрузка файла ${file.name}...`, 'info');
        const fd = new FormData();
        fd.append('file', file);

        try {
            const resp = await fetch('api/upload_attachment.php', { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.ok && data.attachment) {
                const payload = `[attachment:${JSON.stringify(data.attachment)}]`;
                await sendTextMessage(payload);
                showToast('Файл успешно прикреплён!', 'success');
            } else {
                showToast(data.error || 'Ошибка загрузки файла', 'error');
            }
        } catch (e) {
            showToast('Не удалось загрузить файл', 'error');
        }

        attachmentInput.value = '';
    });

    // Info panel toggle
    const chatInfoPanel = document.getElementById('chatInfoPanel');
    const toggleInfoBtn = document.getElementById('toggleInfoBtn');
    const closeInfoBtn = document.getElementById('closeInfoBtn');
    const headerTitle = document.getElementById('chatHeaderTitle');
    const compAvatar = document.getElementById('companionAvatar');

    function toggleInfo() {
        if (chatInfoPanel) chatInfoPanel.classList.toggle('hidden');
    }

    toggleInfoBtn?.addEventListener('click', toggleInfo);
    closeInfoBtn?.addEventListener('click', toggleInfo);
    headerTitle?.addEventListener('click', toggleInfo);
    compAvatar?.addEventListener('click', toggleInfo);

    document.getElementById('clearViewBtn')?.addEventListener('click', () => {
        if (confirm('Очистить сообщения на экране?')) {
            const wrap = document.getElementById('messagesWrap');
            if (wrap) wrap.innerHTML = '<div class="empty-chat" id="emptyChat">💬 Экран очищен</div>';
        }
    });

    document.getElementById('refreshBtn')?.addEventListener('click', fetchNewMessages);

    // ── Command Palette (Ctrl+K) ──────────────────────────────
    const cmdPaletteOverlay = document.getElementById('cmdPaletteOverlay');
    const cmdPaletteInput = document.getElementById('cmdPaletteInput');

    function openCmdPalette() {
        if (cmdPaletteOverlay && cmdPaletteInput) {
            cmdPaletteOverlay.classList.add('open');
            cmdPaletteInput.value = '';
            cmdPaletteInput.focus();
        }
    }

    cmdPaletteOverlay?.addEventListener('click', e => {
        if (e.target === cmdPaletteOverlay) cmdPaletteOverlay.classList.remove('open');
    });

    // ── Hotkeys ───────────────────────────────────────────────
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (cmdPaletteOverlay && cmdPaletteOverlay.classList.contains('open')) {
                cmdPaletteOverlay.classList.remove('open');
                return;
            }
            const currentNodesModal = document.getElementById('nodesModal');
            if (currentNodesModal && currentNodesModal.classList.contains('open')) {
                currentNodesModal.classList.remove('open');
                return;
            }
            const currentAddMemberModal = document.getElementById('addMemberModal');
            if (currentAddMemberModal && currentAddMemberModal.classList.contains('open')) {
                currentAddMemberModal.classList.remove('open');
                return;
            }
            if (chatInfoPanel && !chatInfoPanel.classList.contains('hidden')) {
                chatInfoPanel.classList.add('hidden');
                return;
            }
            window.location.href = 'index.php';
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openCmdPalette();
        }
    });

    // ── Node Modal ────────────────────────────────────────────
    const nodesModal = document.getElementById('nodesModal');
    async function loadNodes() {
        const list = document.getElementById('nodeListModal');
        if (!list) return;
        list.innerHTML = '<div class="text-center text-muted p-16 fs-13">⏳ Загрузка узлов...</div>';
        try {
            const token = await getJWT();
            const resp = await fetch('http://localhost:3001/api/nodes', {
                headers: token ? { 'Authorization': `Bearer ${token}` } : {}
            });
            const data = await resp.json();
            if (data.ok && data.nodes && data.nodes.length) {
                list.innerHTML = data.nodes.map(n => `
                    <div class="node-list-item ${wsCurrentUrl === n.ws_url ? 'selected' : ''}" data-ws="${escHtml(n.ws_url)}">
                        <div class="node-dot ${n.online ? 'connected' : 'error'}"></div>
                        <div class="node-info">
                            <div class="node-name">${escHtml(n.name)}</div>
                            <div class="node-addr">${escHtml(n.ws_url)}</div>
                        </div>
                        <span class="node-ping ${n.online ? 'text-success' : 'text-danger'}">
                            ${n.online ? '● онлайн' : '● офлайн'}
                        </span>
                    </div>
                `).join('');
            } else {
                list.innerHTML = '';
            }
        } catch (e) {
            list.innerHTML = '';
        }

        const localItem = document.createElement('div');
        localItem.className = `node-list-item ${wsCurrentUrl === 'ws://localhost:3001' ? 'selected' : ''}`;
        localItem.dataset.ws = 'ws://localhost:3001';
        localItem.innerHTML = `
            <div class="node-dot connected"></div>
            <div class="node-info">
                <div class="node-name">Локальный узел</div>
                <div class="node-addr">ws://localhost:3001</div>
            </div>
            <span class="node-ping text-success">● онлайн</span>
        `;
        localItem.addEventListener('click', () => {
            localStorage.setItem('nt-node', 'ws://localhost:3001');
            nodesModal?.classList.remove('open');
            wsConnect('ws://localhost:3001');
        });
        list.prepend(localItem);

        list.querySelectorAll('.node-list-item').forEach(item => {
            item.addEventListener('click', () => {
                const wsUrl = item.dataset.ws;
                if (wsUrl) {
                    localStorage.setItem('nt-node', wsUrl);
                    nodesModal?.classList.remove('open');
                    wsConnect(wsUrl);
                }
            });
        });
    }

    const openNodes = () => {
        if (nodesModal) {
            nodesModal.classList.add('open');
            loadNodes();
        }
    };

    document.getElementById('openNodesModal')?.addEventListener('click', openNodes);
    document.getElementById('nodeIndicator')?.addEventListener('click', openNodes);
    document.getElementById('changeNodeBtn')?.addEventListener('click', openNodes);
    document.getElementById('closeNodesModal')?.addEventListener('click', () => nodesModal?.classList.remove('open'));
    if (nodesModal) {
        nodesModal.addEventListener('click', e => {
            if (e.target === nodesModal) nodesModal.classList.remove('open');
        });
    }

    document.getElementById('connectCustomNode')?.addEventListener('click', () => {
        const addrInput = document.getElementById('customNodeInput');
        const addr = addrInput ? addrInput.value.trim() : '';
        if (!addr.startsWith('ws://') && !addr.startsWith('wss://')) {
            showToast('Адрес должен начинаться с ws:// или wss://', 'error');
            return;
        }
        localStorage.setItem('nt-node', addr);
        nodesModal?.classList.remove('open');
        wsConnect(addr);
    });

    // ── Sidebar User Search ───────────────────────────────────
    const searchInput = document.getElementById('userSearch');
    const searchDropdown = document.getElementById('searchDropdown');
    let searchTimer;
    if (searchInput && searchDropdown) {
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
                    const resp = await fetch(`api/search_users.php?q=${encodeURIComponent(q)}`);
                    const users = await resp.json();
                    if (!users.length) {
                        searchDropdown.innerHTML = '<div class="search-no-result">Никого не найдено</div>';
                        searchDropdown.classList.add('visible');
                        return;
                    }
                    searchDropdown.innerHTML = users.map(u => `
                        <div class="search-result-item" data-id="${u.id}">
                            <div class="avatar avatar-sm">${escHtml(u.username[0].toUpperCase())}</div>
                            <div class="search-result-info">
                                <div class="result-name">${escHtml(u.username)}</div>
                                <div class="result-id">ID: ${u.id}</div>
                            </div>
                        </div>
                    `).join('');
                    searchDropdown.classList.add('visible');
                    searchDropdown.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'api/create_chat.php';
                            form.innerHTML = `<input type="hidden" name="user_id" value="${item.dataset.id}">`;
                            document.body.appendChild(form);
                            form.submit();
                        });
                    });
                } catch (e) {}
            }, 250);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('#searchBox')) {
                searchDropdown.classList.remove('visible');
            }
        });
    }

    // ── Add Member Modal & Form ──────────────────────────────
    const openAddMemberBtn = document.getElementById('openAddMemberBtn');
    const headerAddMemberBtn = document.getElementById('headerAddMemberBtn');
    const addMemberModal = document.getElementById('addMemberModal');
    const closeAddMemberModal = document.getElementById('closeAddMemberModal');
    const addMemberForm = document.getElementById('addMemberForm');
    const newMemberUsernameInput = document.getElementById('newMemberUsername');

    function openAddMemberDialog() {
        if (!addMemberModal) return;
        addMemberModal.classList.add('open');
        if (newMemberUsernameInput) {
            newMemberUsernameInput.value = '';
            setTimeout(() => newMemberUsernameInput.focus(), 60);
        }
    }

    function closeAddMemberDialog() {
        if (!addMemberModal) return;
        addMemberModal.classList.remove('open');
    }

    openAddMemberBtn?.addEventListener('click', openAddMemberDialog);
    headerAddMemberBtn?.addEventListener('click', openAddMemberDialog);
    closeAddMemberModal?.addEventListener('click', closeAddMemberDialog);

    if (addMemberModal) {
        addMemberModal.addEventListener('click', e => {
            if (e.target === addMemberModal) {
                closeAddMemberDialog();
            }
        });
    }

    if (addMemberForm) {
        addMemberForm.addEventListener('submit', async e => {
            e.preventDefault();
            const submitBtn = addMemberForm.querySelector('button[type="submit"]');
            const usernameVal = newMemberUsernameInput ? newMemberUsernameInput.value.trim() : '';
            if (!usernameVal) {
                showToast('Введите имя пользователя', 'error');
                return;
            }

            const originalBtnText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Добавление...';
            }

            try {
                const formData = new FormData(addMemberForm);
                const resp = await fetch('api/add_chat_member.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await resp.json().catch(() => null);

                if (resp.ok && data && data.ok) {
                    showToast('✅ Пользователь добавлен в группу!', 'success');
                    closeAddMemberDialog();

                    const addedName = data.username || usernameVal.replace(/^@/, '');
                    const membersList = document.getElementById('chatMembersList');
                    if (membersList) {
                        const firstChar = (addedName.charAt(0) || 'U').toUpperCase();
                        const row = document.createElement('div');
                        row.className = 'member-item-row';
                        row.innerHTML = `
                            <div class="avatar avatar-sm">${escHtml(firstChar)}</div>
                            <span class="member-item-name">${escHtml(addedName)}</span>
                        `;
                        membersList.appendChild(row);
                    }

                    // Update count indicators in UI
                    const currentCount = membersList ? membersList.children.length : null;
                    if (currentCount !== null) {
                        const countTitle = document.getElementById('chatMembersCountTitle');
                        if (countTitle) countTitle.textContent = `Участники (${currentCount})`;
                        const cs = document.getElementById('chatStatus');
                        if (cs) cs.textContent = `${currentCount} участников`;
                        const ss = document.getElementById('sidebarStatus');
                        if (ss) ss.textContent = `${currentCount} участников`;
                    }
                } else {
                    const err = (data && data.error) ? data.error : 'Не удалось добавить участника';
                    showToast('❌ ' + err, 'error');
                }
            } catch (err) {
                console.error('Failed to add member:', err);
                showToast('❌ Ошибка сети при добавлении пользователя', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }
        });
    }

    // Initial scroll & WS connect
    scrollToBottom();
    updateSidebarBadge();
    wsConnect(wsCurrentUrl);
    setInterval(() => wsSend({ type: 'ping' }), 30000);
});
