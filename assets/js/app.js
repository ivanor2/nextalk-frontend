/**
 * NexTalk Messenger — Core Client Application Library
 */

// ── Toast Notifications ─────────────────────────────────────
function showToast(message, type = 'info', duration = 3500) {
    let container = document.getElementById('toasts') || document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toasts';
        container.id = 'toasts';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icons = { success: '✅', error: '❌', info: '💬', warning: '⚠️' };
    toast.innerHTML = `<span class="toast-icon">${icons[type] || '💬'}</span> <span class="toast-msg">${message}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ── Theme Manager ──────────────────────────────────────────
(function initTheme() {
    const saved = localStorage.getItem('nt-theme') || localStorage.getItem('nextalk_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);

    function updateIcons(theme) {
        const icon = theme === 'dark' ? '☀️' : '🌙';
        document.querySelectorAll('#themeToggleApp, #themeTogglePub, .theme-toggle').forEach(btn => {
            btn.textContent = icon;
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateIcons(saved);
        document.querySelectorAll('#themeToggleApp, #themeTogglePub, .theme-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme') || 'dark';
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('nt-theme', next);
                localStorage.setItem('nextalk_theme', next);
                updateIcons(next);
            });
        });
    });
})();

// ── String Escaping Helper ──────────────────────────────────
function escHtml(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

// ── Modal Helpers ───────────────────────────────────────────
function openModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) el.classList.add('open');
}

function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) el.classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        const cmdOverlay = document.getElementById('cmdPaletteOverlay');
        if (cmdOverlay) cmdOverlay.classList.remove('open');
    }
});

// ── Global Realtime & Background Notifier (for pages other than chat.php and index.php) ──
(function initGlobalNotifier() {
    document.addEventListener('DOMContentLoaded', () => {
        // Only run on pages that have the sidebarChatsBadge and do NOT run their own chat/index WebSocket
        if (window.CHAT_CONFIG || document.getElementById('chatList') || !document.getElementById('sidebarChatsBadge')) {
            return;
        }

        async function checkGlobalUnread() {
            try {
                const resp = await fetch('api/get_chats.php?t=' + Date.now());
                if (!resp.ok) return;
                const data = await resp.json();
                if (data.ok && Array.isArray(data.chats)) {
                    const total = data.total_unread != null
                        ? parseInt(data.total_unread, 10)
                        : data.chats.reduce((sum, c) => sum + parseInt(c.unread_count || 0, 10), 0);

                    const badge = document.getElementById('sidebarChatsBadge');
                    if (badge) {
                        if (total > 0) {
                            badge.textContent = String(total);
                            badge.classList.remove('d-none');
                        } else {
                            badge.textContent = '0';
                            badge.classList.add('d-none');
                        }
                    }
                    const baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
                    document.title = total > 0 ? `(${total}) ${baseTitle}` : baseTitle;
                }
            } catch (e) {}
        }

        checkGlobalUnread();
        setInterval(checkGlobalUnread, 4000);
        window.addEventListener('focus', checkGlobalUnread);

        // Connect to WebSocket for instant notifications on secondary pages
        if (window.APP_CONFIG || window.CHAT_CONFIG) {
            return;
        }

        const cachedWs = localStorage.getItem('nt-node') || 'ws://127.0.0.1:3001';
        let wsUrl = cachedWs;
        if (window.location.protocol === 'https:' && wsUrl.startsWith('ws://')) {
            wsUrl = wsUrl.replace(/^ws:\/\//, 'wss://');
        }

        fetch('api/token.php')
            .then(r => r.json())
            .then(data => {
                if (!data.ok || !data.token) return;
                try {
                    const gws = new WebSocket(wsUrl);
                    gws.addEventListener('open', () => {
                        gws.send(JSON.stringify({ type: 'auth', token: data.token }));
                    });
                    gws.addEventListener('message', e => {
                        try {
                            const m = JSON.parse(e.data);
                            if (m.type === 'message') {
                                showToast(`💬 ${m.sender}: ${String(m.content).substring(0, 30)}`, 'info');
                                checkGlobalUnread();
                            }
                        } catch (err) {}
                    });
                    setInterval(() => {
                        if (gws.readyState === WebSocket.OPEN) {
                            gws.send(JSON.stringify({ type: 'ping' }));
                        }
                    }, 30000);
                } catch (err) {}
            })
            .catch(() => {});
    });
})();
