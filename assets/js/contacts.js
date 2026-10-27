/**
 * NexTalk Messenger — Contacts Management Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('contactSearch');
    const searchDropdown = document.getElementById('contactSearchDropdown');
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
                    const curUserId = window.APP_USER ? window.APP_USER.id : null;
                    renderSearchResults(users.filter(u => u.id != curUserId));
                } catch (e) {
                    // search error ignored
                }
            }, 300);
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('#contactSearchBox')) {
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
        searchDropdown.innerHTML = users.map(u => {
            const av = u.avatar_path
                ? `<img src="api/avatar.php?path=${encodeURIComponent(u.avatar_path)}" class="avatar-img" alt="${escHtml(u.username)}">`
                : escHtml((u.display_name || u.username)[0].toUpperCase());
            const bio = u.status_text
                ? `<div class="result-bio">“${escHtml(u.status_text)}”</div>`
                : '';
            return `
                <div class="search-result-item" data-id="${u.id}">
                    <div class="avatar avatar-sm">${av}</div>
                    <div class="search-result-info">
                        <div class="result-name">${escHtml(u.display_name || u.username)} <span class="result-username text-muted">@${escHtml(u.username)}</span></div>
                        ${bio}
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="addContact(${u.id})">+ Добавить</button>
                </div>
            `;
        }).join('');
        searchDropdown.classList.add('visible');
    }
});

// ── Contact Actions ──────────────────────────────────────────
async function addContact(contactId) {
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('contact_id', contactId);
    try {
        const r = await fetch('contacts.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            showToast(d.status === 'accepted' ? 'Контакт добавлен!' : 'Запрос отправлен', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(d.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showToast('Ошибка сети', 'error');
    }
}

async function acceptContact(contactId, btn) {
    const fd = new FormData();
    fd.append('action', 'accept');
    fd.append('contact_id', contactId);
    try {
        const r = await fetch('contacts.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            showToast('Контакт принят!', 'success');
            if (btn) btn.closest('.contact-item').remove();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(d.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showToast('Ошибка сети', 'error');
    }
}

async function blockContact(contactId, btn) {
    const fd = new FormData();
    fd.append('action', 'block');
    fd.append('contact_id', contactId);
    try {
        const r = await fetch('contacts.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            showToast('Пользователь заблокирован', 'info');
            if (btn) btn.closest('.contact-item').remove();
        } else {
            showToast(d.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showToast('Ошибка сети', 'error');
    }
}

async function removeContact(contactId, btn) {
    if (!confirm('Удалить контакт?')) return;
    const fd = new FormData();
    fd.append('action', 'remove');
    fd.append('contact_id', contactId);
    try {
        const r = await fetch('contacts.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            showToast('Контакт удалён', 'info');
            if (btn) btn.closest('.contact-item').remove();
        } else {
            showToast(d.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showToast('Ошибка сети', 'error');
    }
}

function startChatWith(userId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/create_chat.php';
    form.innerHTML = `<input type="hidden" name="user_id" value="${userId}">`;
    document.body.appendChild(form);
    form.submit();
}
