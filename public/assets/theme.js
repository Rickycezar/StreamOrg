/* Landing page, visitors only: follow the system's light/dark preference.
   An external file (not inline) so the Content-Security-Policy can forbid
   inline scripts. Loaded blocking in <head> to apply before first paint. */
if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    document.documentElement.setAttribute('data-theme', 'dark');
}
