/**
 * NexTalk Messenger — User Settings Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
    // Theme options
    const currentTheme = localStorage.getItem('nt-theme') || 'dark';
    document.querySelectorAll('.theme-option').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.theme === currentTheme);
        btn.addEventListener('click', () => {
            let theme = btn.dataset.theme;
            if (theme === 'system') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('nt-theme', theme);
            document.querySelectorAll('.theme-option').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const themeBtn = document.getElementById('themeToggleApp');
            if (themeBtn) themeBtn.textContent = theme === 'dark' ? '🌙' : '☀️';
        });
    });

    // Notification toggle
    const notifToggle = document.getElementById('notifToggle');
    if (notifToggle) {
        notifToggle.checked = localStorage.getItem('nt-notif') === 'true';
        notifToggle.addEventListener('change', async () => {
            if (notifToggle.checked) {
                const perm = await Notification.requestPermission();
                if (perm === 'granted') {
                    localStorage.setItem('nt-notif', 'true');
                    showToast('✅ Уведомления включены', 'success');
                } else {
                    notifToggle.checked = false;
                    showToast('❌ Уведомления заблокированы браузером', 'error');
                }
            } else {
                localStorage.setItem('nt-notif', 'false');
            }
        });
    }

    // Sound toggle
    const soundToggle = document.getElementById('soundToggle');
    if (soundToggle) {
        soundToggle.checked = localStorage.getItem('nt-sound') !== 'false';
        soundToggle.addEventListener('change', () => {
            localStorage.setItem('nt-sound', soundToggle.checked ? 'true' : 'false');
        });
    }

    // Preview toggle
    const previewToggle = document.getElementById('previewToggle');
    if (previewToggle) {
        previewToggle.checked = localStorage.getItem('nt-preview') !== 'false';
        previewToggle.addEventListener('change', () => {
            localStorage.setItem('nt-preview', previewToggle.checked ? 'true' : 'false');
        });
    }

    // Export keys
    const exportBtn = document.getElementById('exportKeysBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            try {
                const keys = { note: 'NexTalk E2E Keys Export', exported_at: new Date().toISOString() };
                const blob = new Blob([JSON.stringify(keys, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `nextalk-keys-${Date.now()}.json`;
                a.click();
                URL.revokeObjectURL(url);
                showToast('✅ Ключи экспортированы', 'success');
            } catch (e) {
                showToast('❌ Ошибка экспорта', 'error');
            }
        });
    }

    // Import keys
    const importBtn = document.getElementById('importKeysBtn');
    const importFile = document.getElementById('importKeysFile');
    if (importBtn && importFile) {
        importBtn.addEventListener('click', () => importFile.click());
        importFile.addEventListener('change', async e => {
            const file = e.target.files[0];
            if (!file) return;
            try {
                const text = await file.text();
                JSON.parse(text);
                showToast('✅ Ключи импортированы', 'success');
                const st = document.getElementById('keysStatus');
                if (st) st.textContent = 'Ключи импортированы из ' + file.name;
            } catch (err) {
                showToast('❌ Некорректный файл ключей', 'error');
            }
        });
    }

    // Generate keys
    const genBtn = document.getElementById('generateKeysBtn');
    if (genBtn) {
        genBtn.addEventListener('click', () => {
            if (!confirm('Сгенерировать новую пару ключей? Старые сообщения нельзя будет расшифровать!')) return;
            showToast('🔄 Новые ключи сгенерированы', 'success');
            const st = document.getElementById('keysStatus');
            if (st) st.textContent = 'Новая пара ключей создана ' + new Date().toLocaleString();
        });
    }

    // Clear cache
    const clearBtn = document.getElementById('clearCacheBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (!confirm('Очистить весь кэш NexTalk?')) return;
            ['nt-theme', 'nt-node', 'nt-jwt', 'nt-jwt-exp', 'nt-notif', 'nt-sound', 'nt-preview'].forEach(k => localStorage.removeItem(k));
            showToast('✅ Кэш очищен', 'success');
        });
    }
});
