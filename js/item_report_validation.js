document.querySelectorAll('[data-item-report]').forEach(form => {
    const requiredFields = form.querySelectorAll('input[required], textarea[required]');
    const image = form.elements.item_image;
    const submit = form.querySelector('button[type="submit"]');
    const validate = () => {
        requiredFields.forEach(field => {
            field.setCustomValidity(field.value.trim() ? '' : 'This field is required.');
        });
        const file = image.files[0];
        const supported = !file || (/\.(jpe?g|png|webp)$/i.test(file.name)
            && file.size > 0 && file.size <= 2 * 1024 * 1024);
        image.setCustomValidity(supported ? '' : 'Choose a JPG, PNG, or WebP image up to 2 MB.');
    };
    form.addEventListener('input', event => {
        validate();
        const errorId = event.target.name + '-error';
        const describedBy = (event.target.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (event.target.validity?.valid && describedBy.includes(errorId)) {
            document.getElementById(errorId)?.remove();
            const hints = describedBy.filter(id => id !== errorId);
            if (hints.length) event.target.setAttribute('aria-describedby', hints.join(' '));
            else event.target.removeAttribute('aria-describedby');
            event.target.removeAttribute('aria-invalid');
        }
    });
    form.addEventListener('submit', event => {
        validate();
        if (!form.reportValidity() || submit.disabled) {
            event.preventDefault();
            return;
        }
        submit.disabled = true;
    });
    window.addEventListener('pageshow', () => {
        submit.disabled = false;
    });
});
