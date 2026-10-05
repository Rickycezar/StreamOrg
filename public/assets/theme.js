/* Resolves the "auto" theme mode before the first paint, and follows the device when it switches. */
(function () {
    'use strict';

    const root = document.documentElement;
    const query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function apply() {
        const mode = root.dataset.themeMode || 'light';
        const dark = mode === 'dark' || (mode === 'auto' && query && query.matches);
        const variant = dark ? root.dataset.themeDark : root.dataset.themeLight;

        if (variant) root.dataset.theme = variant;
    }

    function setMode(mode) {
        root.dataset.themeMode = mode;
        apply();
    }

    if (query) {
        if (query.addEventListener) query.addEventListener('change', apply);
        else if (query.addListener) query.addListener(apply);
    }

    window.StreamOrgTheme = { apply: apply, setMode: setMode };
    apply();
})();
