/**
 * NexTalk Messenger — Authentication Scripts (Login & Register)
 */

document.addEventListener('DOMContentLoaded', () => {
    // ── Password Visibility Toggle ─────────────────────────────
    document.querySelectorAll('.password-toggle, #togglePass').forEach(btn => {
        btn.addEventListener('click', () => {
            const wrap = btn.closest('.password-wrap');
            const input = wrap ? wrap.querySelector('input') : document.getElementById('password');
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });

    // ── Forgot Password Modal ──────────────────────────────────
    const forgotModal = document.getElementById('forgotModal');
    const forgotLink = document.getElementById('forgotLink');
    const closeForgot = document.getElementById('closeForgot');
    const forgotSubmit = document.getElementById('forgotSubmit');

    if (forgotLink && forgotModal) {
        forgotLink.addEventListener('click', e => {
            e.preventDefault();
            forgotModal.classList.add('open');
        });
    }

    if (closeForgot && forgotModal) {
        closeForgot.addEventListener('click', () => {
            forgotModal.classList.remove('open');
        });
        forgotModal.addEventListener('click', e => {
            if (e.target === forgotModal) forgotModal.classList.remove('open');
        });
    }

    if (forgotSubmit) {
        forgotSubmit.addEventListener('click', () => {
            const emailInput = document.getElementById('forgotEmail');
            const resultEl = document.getElementById('forgotResult');
            const email = emailInput ? emailInput.value.trim() : '';
            if (!email) {
                if (resultEl) resultEl.innerHTML = '<span class="text-danger">Введите e-mail</span>';
                return;
            }
            if (resultEl) {
                resultEl.innerHTML = '<span class="text-success">✅ Если аккаунт с таким e-mail существует, инструкции будут отправлены.</span>';
            }
        });
    }

    // ── Password Strength Meter (Register) ─────────────────────
    const passInput = document.getElementById('password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');

    if (passInput && strengthFill && strengthLabel) {
        passInput.addEventListener('input', () => {
            const val = passInput.value;
            let score = 0;
            if (val.length >= 10) score++;
            if (val.length >= 14) score++;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^a-zA-Z0-9]/.test(val)) score++;

            const pct = Math.min(score * 20, 100);
            const colors = ['#F87171', '#F87171', '#FBBF24', '#4ADE80', '#4ADE80', '#4ADE80'];
            const labels = ['', 'Слабый', 'Средний', 'Хороший', 'Сильный', 'Отличный'];

            strengthFill.style.width = pct + '%';
            strengthFill.style.background = colors[score];
            strengthLabel.textContent = val.length > 0 ? labels[score] : '';
            strengthLabel.style.color = colors[score];
        });
    }

    // ── Register Form Validation ───────────────────────────────
    const regForm = document.getElementById('registerForm');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const displayName = document.getElementById('display_name').value.trim();
            const pass = document.getElementById('password').value;
            const passConfirm = document.getElementById('password_confirm').value;
            const agree = document.getElementById('agree').checked;

            let hasError = false;
            document.querySelectorAll('.field-error-js').forEach(el => el.remove());

            function showError(fieldId, msg) {
                const field = document.getElementById(fieldId);
                if (!field) return;
                field.classList.add('error-input');
                const div = document.createElement('div');
                div.className = 'field-error field-error-js';
                div.textContent = msg;
                field.parentElement.appendChild(div);
                hasError = true;
            }

            if (!/^[a-zA-Z0-9_]{3,32}$/.test(username)) {
                showError('username', '3–32 символа: латиница, цифры, _');
            }
            if (displayName.length < 1) {
                showError('display_name', 'Обязательное поле');
            }
            if (pass.length < 10) {
                showError('password', 'Минимум 10 символов');
            } else if (!/[a-zA-Z]/.test(pass) || !/[0-9]/.test(pass)) {
                showError('password', 'Буквы + цифры');
            }
            if (pass !== passConfirm) {
                showError('password_confirm', 'Пароли не совпадают');
            }
            if (!agree) {
                showError('agree', 'Примите правила');
            }

            if (hasError) {
                e.preventDefault();
            }
        });
    }

    // Clear validation error on input focus
    document.querySelectorAll('.field input, .field select').forEach(input => {
        input.addEventListener('focus', () => {
            input.classList.remove('error-input');
            const err = input.parentElement.querySelector('.field-error-js');
            if (err) err.remove();
        });
    });
});
