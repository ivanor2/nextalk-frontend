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

    // Avatar preview & auto-submit reveal
    const avatarFile = document.getElementById('avatarFile');
    const avatarSubmit = document.getElementById('avatarSubmit');
    if (avatarFile && avatarSubmit) {
        avatarFile.addEventListener('change', () => {
            if (avatarFile.files.length > 0) {
                avatarSubmit.style.display = 'inline-flex';
            }
        });
    }
});
