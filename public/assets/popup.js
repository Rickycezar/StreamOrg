/* Pages opened as small windows: offer a Close button when the window was opened by the app. */
(function () {
    'use strict';

    document.querySelectorAll('[data-close-window]').forEach(function (button) {
        if (!window.opener && window.history.length > 1) return;

        button.classList.remove('hidden');
        button.addEventListener('click', function () { window.close(); });
    });
})();
