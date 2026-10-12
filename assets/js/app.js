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
