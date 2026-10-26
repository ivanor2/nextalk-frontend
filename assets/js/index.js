/**
 * NexTalk Messenger — Dashboard & Chats List Client
 */

let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 1500;
const safeStorage = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    remove(k) { try { localStorage.removeItem(k); } catch (e) {} }
};

let cachedWs = safeStorage.get('nt-node');
let defaultNode = (window.APP_CONFIG && window.APP_CONFIG.defaultNode) ? window.APP_CONFIG.defaultNode : 'ws://127.0.0.1:3001';
let wsCurrentUrl = cachedWs || defaultNode;
if (window.location.protocol === 'https:' && wsCurrentUrl.startsWith('ws://')) {
    wsCurrentUrl = defaultNode.startsWith('wss://') ? defaultNode : wsCurrentUrl.replace(/^ws:\/\//, 'wss://');
}

let jwtToken = null;
let jwtExpires = 0;

function updateNodeStatus(state, text) {
    const dot = document.getElementById('nodeStatusDot');
    const label = document.getElementById('nodeStatusText');
    if (dot) dot.className = 'node-dot ' + state;
    if (label) label.textContent = text;
}

// ── User Search ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
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
                    renderSearchResults(users);
                } catch (e) {}
            }, 250);
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('#searchBox')) {
                searchDropdown.classList.remove('visible');
            }
        });
    }

    function renderSearchResults(users) {
        if (!users.length) {
            searchDropdown.innerHTML = '<div class="search-no-result">Никого не найдено</div>';
            searchDropdown.classList.add('visible');
            return;
        }
        searchDropdown.innerHTML = users.map(u => `
            <div class="search-result-item" data-id="${u.id}" data-username="${escHtml(u.username)}">
                <div class="avatar avatar-sm">
                    ${escHtml(u.username[0].toUpperCase())}
                </div>
                <div class="search-result-info">
                    <div class="result-name">${escHtml(u.username)}</div>
                    <div class="result-id">ID: ${u.id}</div>
                </div>
            </div>
        `).join('');
        searchDropdown.classList.add('visible');

        searchDropdown.querySelectorAll('.search-result-item').forEach(item => {
            item.addEventListener('click', () => {
                startChat(item.dataset.id, item.dataset.username || '');
            });
        });
    }

    // ── Group Creation Modal ─────────────────────────────────
    const createGroupModal = document.getElementById('createGroupModal');
    const openGroupBtns = [document.getElementById('openCreateGroupBtn'), document.getElementById('welcomeCreateGroupBtn')];
    const closeGroupModal = document.getElementById('closeGroupModal');

    openGroupBtns.forEach(b => {
        b?.addEventListener('click', () => createGroupModal && createGroupModal.classList.add('open'));
    });
    closeGroupModal?.addEventListener('click', () => createGroupModal && createGroupModal.classList.remove('open'));
    if (createGroupModal) {
        createGroupModal.addEventListener('click', e => {
            if (e.target === createGroupModal) createGroupModal.classList.remove('open');
        });
    }

    // ── Node Modal ───────────────────────────────────────────
    const nodesModal = document.getElementById('nodesModal');
    const openNodesBtn = document.getElementById('openNodesModal');
    const changeNodeBtn = document.getElementById('changeNodeBtn');
    const closeNodesModal = document.getElementById('closeNodesModal');
    const connectCustomBtn = document.getElementById('connectCustomNode');

    const openNodeModalHandler = () => {
        if (nodesModal) {
            nodesModal.classList.add('open');
            pingLocalNode();
        }
    };

    openNodesBtn?.addEventListener('click', openNodeModalHandler);
    changeNodeBtn?.addEventListener('click', openNodeModalHandler);
    closeNodesModal?.addEventListener('click', () => nodesModal && nodesModal.classList.remove('open'));

    if (nodesModal) {
        nodesModal.addEventListener('click', e => {
            if (e.target === nodesModal) nodesModal.classList.remove('open');
        });
    }

    connectCustomBtn?.addEventListener('click', () => {
        const addrInput = document.getElementById('customNodeInput');
        const addr = addrInput ? addrInput.value.trim() : '';
        if (!addr.startsWith('ws://') && !addr.startsWith('wss://')) {
            showToast('Адрес должен начинаться с ws:// или wss://', 'error');
            return;
        }
        localStorage.setItem('nt-node', addr);
        if (nodesModal) nodesModal.classList.remove('open');
        wsConnect(addr);
    });

    // ── Command Palette (Ctrl+K) ─────────────────────────────
    const cmdPaletteOverlay = document.getElementById('cmdPaletteOverlay');
    const cmdPaletteInput = document.getElementById('cmdPaletteInput');

    function openCmdPalette() {
        if (cmdPaletteOverlay && cmdPaletteInput) {
            cmdPaletteOverlay.classList.add('open');
            cmdPaletteInput.value = '';
            cmdPaletteInput.focus();
        }
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            searchDropdown?.classList.remove('visible');
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openCmdPalette();
        }
    });

    if (cmdPaletteOverlay) {
        cmdPaletteOverlay.addEventListener('click', e => {
            if (e.target === cmdPaletteOverlay) cmdPaletteOverlay.classList.remove('open');
        });
    }

    // ── Initialize WebSocket ─────────────────────────────────
    wsConnect(wsCurrentUrl);
    setInterval(() => wsSend({ type: 'ping' }), 30000);

    // ── Live Chat Updates & Polling ──────────────────────────
    setInterval(fetchChatsList, 4000);
    window.addEventListener('focus', fetchChatsList);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchChatsList();
    });
});

function startChat(targetUserId, targetUsername = '') {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/create_chat.php';
    form.innerHTML = `
        <input type="hidden" name="user_id" value="${escHtml(targetUserId)}">
        <input type="hidden" name="username" value="${escHtml(targetUsername)}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ── JWT Token Fetcher ────────────────────────────────────────
async function getJWT() {
    if (window.APP_CONFIG && window.APP_CONFIG.token) {
        jwtToken = window.APP_CONFIG.token;
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

// ── WebSocket Client ─────────────────────────────────────────
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

    updateNodeStatus('connecting', 'Подключение к узлу...');
    const token = await getJWT();
    if (!token) {
        updateNodeStatus('error', 'Нет JWT-токена');
        scheduleReconnect();
        return;
    }

    let socket;
    try {
        socket = new WebSocket(url);
    } catch (e) {
        updateNodeStatus('error', 'Ошибка WebSocket');
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
        let msg;
        try { msg = JSON.parse(e.data); } catch (x) { return; }
        handleWsMessage(msg);
    });

    socket.addEventListener('close', () => {
        if (ws !== socket) return;
        updateNodeStatus('error', 'Не подключён');
        scheduleReconnect();
    });

    socket.addEventListener('error', () => {
        if (ws !== socket) return;
        updateNodeStatus('error', 'Ошибка сети');
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

function formatTime(ts) {
    if (!ts) return '';
    const safeTs = (typeof ts === 'string' && ts.includes(' ') && !ts.includes('T'))
        ? ts.replace(' ', 'T')
        : ts;
    const d = new Date(safeTs);
    if (isNaN(d.getTime())) return '';
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
}

function formatMessagePreview(content) {
    if (!content) return 'Нет сообщений';
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

function updateGlobalUnread(total) {
    const badges = [
        document.getElementById('globalUnreadBadge'),
        document.getElementById('sidebarChatsBadge')
    ];
    badges.forEach(b => {
        if (!b) return;
        if (total > 0) {
            b.textContent = String(total);
            b.classList.remove('d-none');
        } else {
            b.textContent = '0';
            b.classList.add('d-none');
        }
    });
    const titleBase = 'NexTalk — Мессенджер';
    document.title = total > 0 ? `(${total}) ${titleBase}` : titleBase;
}

function updateChatListItem(msg) {
    const chatList = document.getElementById('chatList');
    if (!chatList) return;

    const chatId = String(msg.chat_id || '');
    let chatItem = chatList.querySelector(`.chat-item[data-chat-id="${chatId}"]`);
    const preview = formatMessagePreview(msg.content);
    const timeStr = formatTime(msg.timestamp || Date.now());
    const curUserId = window.APP_CONFIG ? window.APP_CONFIG.userId : 0;
    const curUsername = window.APP_CONFIG ? window.APP_CONFIG.username : '';
    const isMe = (msg.sender === curUsername || msg.user_id === curUserId);

    if (chatItem) {
        // Update preview text
        const previewEl = chatItem.querySelector('.chat-item-preview');
        if (previewEl) {
            previewEl.textContent = (isMe ? 'Вы: ' : '') + (preview.length > 42 ? preview.substring(0, 40) + '...' : preview);
        }

        // Update time
        let timeEl = chatItem.querySelector('.chat-item-time');
        if (!timeEl) {
            timeEl = document.createElement('div');
            timeEl.className = 'chat-item-time';
            chatItem.querySelector('.chat-item-top')?.appendChild(timeEl);
        }
        timeEl.textContent = timeStr;

        // Update unread badge if incoming message
        if (!isMe) {
            let bottomEl = chatItem.querySelector('.chat-item-bottom');
            let badge = chatItem.querySelector('.unread-badge');
            if (!badge && bottomEl) {
                badge = document.createElement('span');
                badge.className = 'unread-badge';
                badge.textContent = '1';
                bottomEl.appendChild(badge);
            } else if (badge) {
                const count = parseInt(badge.textContent || '0', 10) + 1;
                badge.textContent = String(count);
            }
        }

        // Move to top of chat list (after header/top-bar)
        const topBar = chatList.querySelector('.chat-list-top');
        if (topBar && topBar.nextSibling !== chatItem) {
            topBar.after(chatItem);
        }

        // Highlight animation
        chatItem.classList.remove('chat-item-highlight');
        void chatItem.offsetWidth;
        chatItem.classList.add('chat-item-highlight');

        // Recalculate total unread
        let total = 0;
        chatList.querySelectorAll('.chat-item .unread-badge').forEach(b => {
            total += parseInt(b.textContent || '0', 10);
        });
        updateGlobalUnread(total);
    } else {
        // Dialogue not in list, fetch full list
        fetchChatsList();
    }
}

let isFetchingChats = false;
async function fetchChatsList() {
    if (isFetchingChats) return;
    isFetchingChats = true;
    try {
        const resp = await fetch('api/get_chats.php?t=' + Date.now());
        const data = await resp.json();
        if (data.ok && Array.isArray(data.chats)) {
            renderChatsList(data.chats);
        }
    } catch (e) {
    } finally {
        isFetchingChats = false;
    }
}

function renderChatsList(chats) {
    const chatList = document.getElementById('chatList');
    if (!chatList) return;

    const curUserId = window.APP_CONFIG ? window.APP_CONFIG.userId : 0;
    const topBar = chatList.querySelector('.chat-list-top');
    const existingEmpty = chatList.querySelector('.chat-list-empty');

    if (!chats.length) {
        if (!existingEmpty) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'chat-list-empty';
            emptyDiv.innerHTML = `<div class="chat-empty-icon">💬</div>Пока нет диалогов.<br>Найдите пользователя в поиске выше!`;
            chatList.querySelectorAll('.chat-item').forEach(el => el.remove());
            if (topBar) topBar.after(emptyDiv);
            else chatList.appendChild(emptyDiv);
        }
        updateGlobalUnread(0);
        return;
    }

    if (existingEmpty) existingEmpty.remove();

    let totalUnread = 0;
    let lastAnchor = topBar;

    chats.forEach(chat => {
        const isGroup = (chat.type || 'private') === 'group';
        const chatName = chat.companion_name || chat.name || (isGroup ? 'Группа' : 'Чат');
        const initial = (chatName[0] || '?').toUpperCase();
        const rawLastMsg = chat.last_message || 'Нет сообщений';
        const myMsg = (chat.last_sender_id == curUserId);
        const time = chat.last_message_time ? formatTime(chat.last_message_time) : '';
        const preview = formatMessagePreview(rawLastMsg);
        const previewText = (myMsg ? 'Вы: ' : '') + (preview.length > 42 ? preview.substring(0, 40) + '...' : preview);
        const unreadCount = parseInt(chat.unread_count || 0, 10);
        totalUnread += unreadCount;

        let item = chatList.querySelector(`.chat-item[data-chat-id="${chat.id}"]`);
        if (!item) {
            item = document.createElement('a');
            item.className = 'chat-item';
            item.href = `chat.php?id=${chat.id}`;
            item.dataset.chatId = chat.id;
            item.innerHTML = `
                <div class="avatar ${isGroup ? 'avatar-group-grad' : ''}">
                    ${escHtml(initial)}
                </div>
                <div class="chat-item-body">
                    <div class="chat-item-top">
                        <div class="chat-item-name flex-row-gap-6">
                            ${escHtml(chatName)}
                            ${isGroup ? '<span class="badge-status badge-active badge-micro">группа</span>' : ''}
                        </div>
                        ${time ? `<div class="chat-item-time">${escHtml(time)}</div>` : ''}
                    </div>
                    <div class="chat-item-bottom">
                        <div class="chat-item-preview">${escHtml(previewText)}</div>
                        ${unreadCount > 0 ? `<span class="unread-badge">${unreadCount}</span>` : ''}
                    </div>
                </div>
            `;
        } else {
            const prevEl = item.querySelector('.chat-item-preview');
            if (prevEl) prevEl.textContent = previewText;

            let timeEl = item.querySelector('.chat-item-time');
            if (time) {
                if (!timeEl) {
                    timeEl = document.createElement('div');
                    timeEl.className = 'chat-item-time';
                    item.querySelector('.chat-item-top')?.appendChild(timeEl);
                }
                timeEl.textContent = time;
            }

            // Update badge
            const bottomEl = item.querySelector('.chat-item-bottom');
            let badge = item.querySelector('.unread-badge');
            if (unreadCount > 0) {
                if (!badge && bottomEl) {
                    badge = document.createElement('span');
                    badge.className = 'unread-badge';
                    bottomEl.appendChild(badge);
                }
                if (badge) badge.textContent = String(unreadCount);
            } else if (badge) {
                badge.remove();
            }
        }

        // Reorder DOM so that items follow chats array order strictly (most recent at top!)
        if (lastAnchor) {
            if (lastAnchor.nextSibling !== item) {
                lastAnchor.after(item);
            }
            lastAnchor = item;
        } else {
            chatList.appendChild(item);
            lastAnchor = item;
        }
    });

    updateGlobalUnread(totalUnread);
}

const seenMessageIds = new Set();

function handleWsMessage(msg) {
    switch (msg.type) {
        case 'auth_ok':
            updateNodeStatus('connected', wsCurrentUrl.replace(/^wss?:\/\//, ''));
            break;
        case 'auth_error':
            updateNodeStatus('error', 'Ошибка авторизации');
            localStorage.removeItem('nt-jwt');
            jwtToken = null;
            scheduleReconnect();
            break;
        case 'message':
            if (msg.id) {
                const sId = String(msg.id);
                if (seenMessageIds.has(sId)) return;
                seenMessageIds.add(sId);
                if (seenMessageIds.size > 200) {
                    const first = seenMessageIds.values().next().value;
                    seenMessageIds.delete(first);
                }
            }
            const preview = formatMessagePreview(msg.content);
            showToast(`Новое сообщение от ${msg.sender}: ${preview.substring(0, 30)}`, 'info');
            playNotificationSound();
            triggerNotification(msg.sender, preview);
            updateChatListItem(msg);
            break;
    }
}

async function pingLocalNode() {
    const pingEl = document.getElementById('localNodePing');
    if (!pingEl) return;
    try {
        const t = Date.now();
        const resp = await fetch('http://localhost:3001/health');
        const ms = Date.now() - t;
        if (resp.ok) {
            pingEl.textContent = ms + ' ms';
            pingEl.className = 'node-ping ' + (ms < 150 ? 'text-success' : 'text-warning');
        } else {
            pingEl.textContent = 'онлайн';
            pingEl.className = 'node-ping text-success';
        }
    } catch (e) {
        pingEl.textContent = 'онлайн';
        pingEl.className = 'node-ping text-success';
    }
}
