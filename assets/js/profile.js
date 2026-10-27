/**
 * NexTalk Messenger — User Profile Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
    // Status character counter
    const statusInput = document.getElementById('status_text');
    const statusCounter = document.getElementById('statusCounter');
    if (statusInput && statusCounter) {
        statusInput.addEventListener('input', () => {
            statusCounter.textContent = statusInput.value.length;
        });
    }

    // Avatar preview & instant auto-upload
    const avatarFile = document.getElementById('avatarFile');
    const avatarForm = document.querySelector('.avatar-upload');
    const profileAvatarLarge = document.querySelector('.profile-avatar-large');
    const choosePhotoLabel = document.querySelector('label[for="avatarFile"]');

    if (avatarFile && avatarForm) {
        avatarFile.addEventListener('change', async () => {
            if (!avatarFile.files || avatarFile.files.length === 0) return;
            const file = avatarFile.files[0];

            // Client-side size validation (2 MB)
            if (file.size > 2 * 1024 * 1024) {
                if (typeof showToast === 'function') {
                    showToast('Размер файла превышает 2 МБ', 'error');
                } else {
                    alert('Размер файла превышает 2 МБ');
                }
                avatarFile.value = '';
                return;
            }

            // Instant visual preview
            const previewUrl = URL.createObjectURL(file);
            if (profileAvatarLarge) {
                profileAvatarLarge.innerHTML = `<img src="${previewUrl}" alt="Аватар" class="avatar-img">`;
            }

            // Auto-submit via AJAX
            const originalLabelText = choosePhotoLabel ? choosePhotoLabel.innerHTML : '';
            if (choosePhotoLabel) {
                choosePhotoLabel.innerHTML = '⏳ Загрузка...';
                choosePhotoLabel.style.pointerEvents = 'none';
            }

            const formData = new FormData();
            formData.append('action', 'upload_avatar');
            formData.append('avatar', file);

            try {
                const resp = await fetch('profile.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await resp.json().catch(() => null);

                if (resp.ok && data && data.ok) {
                    if (typeof showToast === 'function') {
                        showToast('✅ Аватар успешно сохранён!', 'success');
                    }
                    if (profileAvatarLarge && data.avatar_url) {
                        profileAvatarLarge.innerHTML = `<img src="${data.avatar_url}?t=${Date.now()}" alt="Аватар" class="avatar-img">`;
                    }
                    // Also update avatar in sidebar if present
                    const sidebarAvatar = document.querySelector('.sidebar .avatar');
                    if (sidebarAvatar && data.avatar_url) {
                        sidebarAvatar.innerHTML = `<img src="${data.avatar_url}?t=${Date.now()}" alt="Аватар" class="avatar-img">`;
                    }
                } else {
                    const err = (data && data.error) ? data.error : 'Не удалось загрузить аватар';
                    if (typeof showToast === 'function') {
                        showToast('❌ ' + err, 'error');
                    } else {
                        alert(err);
                    }
                }
            } catch (err) {
                console.error('Avatar upload error:', err);
                if (typeof showToast === 'function') {
                    showToast('❌ Ошибка сети при загрузке аватара', 'error');
                }
            } finally {
                if (choosePhotoLabel) {
                    choosePhotoLabel.innerHTML = originalLabelText;
                    choosePhotoLabel.style.pointerEvents = 'auto';
                }
                avatarFile.value = '';
            }
        });
    }
});
