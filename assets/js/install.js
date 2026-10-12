/**
 * NexTalk Messenger — Node Installation Wizard Scripts
 */

function copyCode(btn) {
    const code = btn.parentElement.querySelector('code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        const orig = btn.textContent;
        btn.textContent = 'Скопировано! ✓';
        btn.classList.add('text-success');
        setTimeout(() => {
            btn.textContent = orig;
            btn.classList.remove('text-success');
        }, 2000);
    });
}
