document.querySelectorAll('[data-temporary-password-toggle]').forEach(button => {
    const password = document.getElementById(button.getAttribute('aria-controls'));
    button.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(visible));
    });
});
