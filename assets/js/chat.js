/**
 * NexTalk Messenger — Chat Client & Realtime WebSocket Module
 */

let lastMessageId = (window.CHAT_CONFIG && window.CHAT_CONFIG.lastMessageId) || 0;
let ws = null;
let defaultNode = (window.CHAT_CONFIG && window.CHAT_CONFIG.defaultNode) || 'ws://127.0.0.1:3001';
let wsCurrentUrl = localStorage.getItem('nt-node') || defaultNode;
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

// ── Unread Tab Title Counter ────────────────────────────────
window.addEventListener('focus', () => {
    unreadCount = 0;
    document.title = originalTitle;
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
    const d = new Date(ts);
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

// ── JWT Token Retrieval ─────────────────────────────────────
async function getJWT() {
    if (jwtToken && jwtExpires > Date.now() / 1000 + 60) return jwtToken;
    const cached = localStorage.getItem('nt-jwt');
    const cachedExp = parseInt(localStorage.getItem('nt-jwt-exp') || '0');
    if (cached && cachedExp > Date.now() / 1000 + 60) {
        jwtToken = cached;
        jwtExpires = cachedExp;
        return jwtToken;
    }
    try {
        const resp = await fetch('api/token.php');
        const data = await resp.json();
        if (data.ok && data.token) {
            jwtToken = data.token;
            jwtExpires = Math.floor(Date.now() / 1000) + (data.expires_in || 86400 * 7);
            localStorage.setItem('nt-jwt', jwtToken);
            localStorage.setItem('nt-jwt-exp', String(jwtExpires));
            return jwtToken;
        }
    } catch (e) {}
    return null;
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

    const div = document.createElement('div');
    div.className = `message ${isMe ? 'out' : 'in'}`;
    div.dataset.id = msg.id || ('ws-' + Date.now());

    const timeStr = msg.timestamp
        ? formatTime(msg.timestamp)
        : (msg.created_at ? formatTime(msg.created_at) : '');

    const author = (!isMe && isGroup)
        ? `<div class="msg-author">${escHtml(msg.display || msg.sender || msg.username || 'Собеседник')}</div>`
        : '';

    const rawContent = msg.content || '';
    let bubbleBody = '';

    const attMatch = rawContent.match(/^\[attachment:(\{.*\})\]$/);
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
    if (ws) {
        try { ws.close(); } catch (e) {}
        ws = null;
    }

    setNodeStatus('connecting', 'Подключение...');
    const token = await getJWT();
    if (!token) {
        setNodeStatus('error', 'Нет JWT');
        scheduleReconnect();
        return;
    }

    try {
        ws = new WebSocket(url);
    } catch (e) {
        setNodeStatus('error', 'Ошибка URL');
        scheduleReconnect();
        return;
    }

    ws.addEventListener('open', () => {
        wsReconnectDelay = 1500;
        wsSend({ type: 'auth', token });
    });

    ws.addEventListener('message', e => {
        let m;
        try { m = JSON.parse(e.data); } catch (x) { return; }
        handleWsMsg(m);
    });

    ws.addEventListener('close', () => {
        setNodeStatus('error', 'Не подключён');
        scheduleReconnect();
    });

    ws.addEventListener('error', () => setNodeStatus('error', 'Ошибка WebSocket'));
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
            break;

        case 'joined':
            startPolling();
            break;

        case 'message':
            if (String(msg.chat_id) === String(chatId)) {
                if (!document.querySelector(`[data-id="${msg.id}"]`)) {
                    renderMessage(msg);
                    playNotificationSound();
                    triggerNotification(msg.sender || companionName, msg.content || '');
                    incrementUnread();
                }
                showTypingIndicator(false);
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
            if (msg.sender_id == curUserId) {
                lastMessageId = Math.max(lastMessageId, msg.id);
                return;
            }
            if (document.querySelector(`[data-id="${msg.id}"]`)) {
                lastMessageId = Math.max(lastMessageId, msg.id);
                return;
            }
            renderMessage({
                id: msg.id,
                sender: msg.username,
                sender_id: msg.sender_id,
                content: msg.content,
                timestamp: msg.created_at
            });
            lastMessageId = Math.max(lastMessageId, msg.id);
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

    // 1. WebSocket send
    wsSend({ type: 'send', chat_id: chatId, content, recipient: companionName });

    // 2. HTTP persistence
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
            renderMessage({
                id: data.id,
                sender: curUsername,
                sender_id: curUserId,
                content,
                timestamp: data.timestamp || new Date().toISOString(),
            });
            lastMessageId = Math.max(lastMessageId, data.id || 0);
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
            if (nodesModal && nodesModal.classList.contains('open')) {
                nodesModal.classList.remove('open');
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

    // ── Add Member Modal ──────────────────────────────────────
    const openAddMemberBtn = document.getElementById('openAddMemberBtn');
    const addMemberModal = document.getElementById('addMemberModal');
    const closeAddMemberModal = document.getElementById('closeAddMemberModal');

    if (openAddMemberBtn && addMemberModal) {
        openAddMemberBtn.addEventListener('click', () => {
            addMemberModal.classList.add('visible');
            const inp = document.getElementById('newMemberUsername');
            if (inp) inp.focus();
        });
    }

    if (closeAddMemberModal && addMemberModal) {
        closeAddMemberModal.addEventListener('click', () => {
            addMemberModal.classList.remove('visible');
        });
        addMemberModal.addEventListener('click', e => {
            if (e.target === addMemberModal) {
                addMemberModal.classList.remove('visible');
            }
        });
    }

    // Initial scroll & WS connect
    scrollToBottom();
    wsConnect(wsCurrentUrl);
    setInterval(() => wsSend({ type: 'ping' }), 30000);
});
