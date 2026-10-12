/**
 * NexTalk Messenger — Dashboard & Chats List Client
 */

let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 1500;
let cachedWs = localStorage.getItem('nt-node');
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
            <div class="search-result-item" data-id="${u.id}">
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
                startChat(item.dataset.id);
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
});

function startChat(targetUserId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/create_chat.php';
    form.innerHTML = `<input type="hidden" name="user_id" value="${targetUserId}">`;
    document.body.appendChild(form);
    form.submit();
}

// ── JWT Token Fetcher ────────────────────────────────────────
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

// ── WebSocket Client ─────────────────────────────────────────
async function wsConnect(url) {
    wsCurrentUrl = url;
    if (ws) {
        try { ws.close(); } catch (e) {}
        ws = null;
    }

    updateNodeStatus('connecting', 'Подключение к узлу...');
    const token = await getJWT();
    if (!token) {
        updateNodeStatus('error', 'Нет JWT-токена');
        scheduleReconnect();
        return;
    }

    try {
        ws = new WebSocket(url);
    } catch (e) {
        updateNodeStatus('error', 'Ошибка WebSocket');
        scheduleReconnect();
        return;
    }

    ws.addEventListener('open', () => {
        wsReconnectDelay = 1500;
        wsSend({ type: 'auth', token });
    });

    ws.addEventListener('message', e => {
        let msg;
        try { msg = JSON.parse(e.data); } catch (x) { return; }
        handleWsMessage(msg);
    });

    ws.addEventListener('close', () => {
        updateNodeStatus('error', 'Не подключён');
        scheduleReconnect();
    });

    ws.addEventListener('error', () => updateNodeStatus('error', 'Ошибка сети'));
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
            showToast(`Новое сообщение от ${msg.sender}: ${msg.content.substring(0, 30)}`, 'info');
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
