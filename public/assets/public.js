/* Public pages (claim links, prizes, privacy): copy buttons and confirmations, without the app's scripts. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy]');
        if (!button) return;

        const field = document.querySelector(button.dataset.copy);
        if (!field) return;

        const done = function () {
            if (!button.dataset.label) button.dataset.label = button.textContent;
            button.textContent = button.dataset.copied || '✓';
            button.classList.add('copied');
            clearTimeout(button._restore);
            button._restore = setTimeout(function () {
                button.textContent = button.dataset.label;
                button.classList.remove('copied');
            }, 1600);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, function () {});
            return;
        }

        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { }
    });

    document.addEventListener('submit', function (event) {
        const button = event.submitter;

        if (button && button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    });
})();
