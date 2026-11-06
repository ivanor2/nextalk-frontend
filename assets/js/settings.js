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

    const username = (window.SETTINGS_CONFIG && window.SETTINGS_CONFIG.username) || '';

    async function refreshKeysStatus() {
        const st = document.getElementById('keysStatus');
        if (!st) return;
        const diag = window.E2EE && E2EE.diagnose ? E2EE.diagnose() : { ok: window.E2EE && E2EE.available() };
        if (!diag.ok) {
            if (diag.reason === 'insecure_context') {
                const httpsUrl = 'https://' + location.host + location.pathname;
                st.innerHTML = `
                    <div style="margin-top:10px; padding:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:8px; font-size:13px; line-height:1.5;">
                        <strong style="color:#ef4444;">⚠️ Браузер отключил Web Crypto API на HTTP</strong><br>
                        Все современные браузеры (Chrome, Edge, Firefox) требуют безопасный контекст (HTTPS или localhost) для работы криптографии.<br>
                        👉 <a href="${httpsUrl}" style="color:var(--accent); font-weight:600; text-decoration:underline;">Открыть страницу через HTTPS (${location.host})</a>
                        или добавьте адрес в <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>.
                    </div>
                `;
            } else {
                st.innerHTML = '<span class="text-danger">⚠️ Web Crypto API недоступен в этом браузере</span>';
            }
            return;
        }
        if (!username) return;
        try {
            await E2EE.ensureIdentity(username);
            const info = await E2EE.identityInfo(username);
            if (info && info.public_key) {
                st.innerHTML = `
                    <div class="keys-info-active">
                        <div class="keys-fp-row">
                            <span class="keys-fp-label">Отпечаток ключа (Fingerprint):</span>
                            <code class="keys-fp-val">${info.fingerprint}</code>
                        </div>
                        <div class="keys-desc-sub">
                            ✓ Ключи активны на этом устройстве (ECDH P-256 + Double Ratchet)
                        </div>
                    </div>
                `;
            }
        } catch (e) {
            st.textContent = 'Ошибка загрузки статуса ключей: ' + e.message;
        }
    }

    refreshKeysStatus();

    // Export keys
    const exportBtn = document.getElementById('exportKeysBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', async () => {
            if (!window.E2EE || !username) return;
            try {
                const pass = prompt('Введите пароль для защиты файла ключей (или оставьте пустым для экспорта без пароля):');
                if (pass === null) return;

                const exported = await E2EE.exportIdentity(username, pass);
                const blob = new Blob([JSON.stringify(exported, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `nextalk-keys-${username}-${Date.now()}.json`;
                a.click();
                URL.revokeObjectURL(url);
                showToast('✅ Ключи экспортированы' + (pass ? ' (зашифрованы паролем)' : ''), 'success');
            } catch (e) {
                showToast('❌ Ошибка экспорта: ' + e.message, 'error');
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
                const json = JSON.parse(text);
                let pass = '';
                if (json.encrypted) {
                    pass = prompt('Файл защищен паролем. Введите пароль для расшифровки:');
                    if (pass === null) { importFile.value = ''; return; }
                }

                const res = await E2EE.importIdentity(json, pass);
                await fetch('api/sync_key.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ public_key: res.public_key })
                }).catch(() => {});

                showToast('✅ Ключи импортированы', 'success');
                await refreshKeysStatus();
            } catch (err) {
                showToast('❌ Ошибка импорта: ' + err.message, 'error');
            } finally {
                importFile.value = '';
            }
        });
    }

    // Generate keys
    const genBtn = document.getElementById('generateKeysBtn');
    if (genBtn) {
        genBtn.addEventListener('click', async () => {
            if (!window.E2EE || !username) return;
            if (!confirm('Сгенерировать новую пару ключей? Старые сообщения нельзя будет расшифровать!')) return;
            try {
                const fresh = await E2EE.regenerateIdentity(username);
                await fetch('api/sync_key.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ public_key: fresh.pub })
                }).catch(() => {});

                showToast('🔄 Новая пара ключей создана и сохранена', 'success');
                await refreshKeysStatus();
            } catch (e) {
                showToast('❌ Ошибка генерации: ' + e.message, 'error');
            }
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
