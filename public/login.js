'use strict';

const passwordToggle = document.querySelector('.password-toggle');
const passwordInput = document.getElementById('password');

if (passwordToggle && passwordInput) {
    passwordToggle.hidden = false;
    passwordToggle.addEventListener('click', () => {
        const visible = passwordInput.type === 'password';
        passwordInput.type = visible ? 'text' : 'password';
        passwordToggle.setAttribute('aria-pressed', String(visible));
        passwordToggle.querySelector('.ti').className = visible ? 'ti ti-eye-off' : 'ti ti-eye';
    });
}
