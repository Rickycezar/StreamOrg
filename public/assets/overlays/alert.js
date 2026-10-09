/**
 * Custom alert overlay: a card with a message ("{user}" is who fired it),
 * an optional image and sound, shown for a few seconds at the top, middle
 * or bottom of the screen. Fired by the Test button or by a chat command
 * from the chosen roles; several in a row wait their turn.
 */
(function () {
    'use strict';

    const overlay = window.StreamOrgOverlay;
    const stage = document.getElementById('overlay-stage');
    const queue = [];
    let busy = false;

    function style(settings) {
        const root = document.documentElement.style;
        root.setProperty('--alert-accent', settings.accent || '#9146ff');
        root.setProperty('--alert-text', settings.text_color || '#ffffff');
        root.setProperty('--alert-size', (settings.font_size || 44) + 'px');
        stage.dataset.position = settings.position || 'center';
    }

    function show(item) {
        const settings = overlay.settings || {};
        busy = true;

        const card = document.createElement('div');
        card.className = 'alert-card';

        if (settings.image && settings.image.url) {
            const img = document.createElement('img');
            img.className = 'alert-image';
            img.alt = '';
            img.src = settings.image.url;
            card.append(img);
        }

        const text = document.createElement('div');
        text.className = 'alert-text';
        text.textContent = overlay.fill(settings.message || '{user}', { user: item.user || '' });
        card.append(text);

        stage.replaceChildren(card);
        card.getBoundingClientRect();
        card.classList.add('in');

        if (settings.sound) overlay.playSound(settings.sound);

        setTimeout(function () {
            card.classList.remove('in');
            card.classList.add('out');
            overlay.sound.stop(0.6);
            setTimeout(function () {
                card.remove();
                busy = false;
                next();
            }, 650);
        }, Math.max(1, settings.show_seconds || 6) * 1000);
    }

    function next() {
        if (busy || !queue.length) return;
        show(queue.shift());
    }

    function fire(item) {
        if (queue.length >= 20) return;
        queue.push(item);
        next();
    }

    overlay.on('settings', style);
    overlay.on('test', function (payload) { fire({ user: payload.user || 'StreamOrg' }); });
    overlay.chat(function (user, command, message, flags) {
        const settings = overlay.settings || {};
        if (!settings.command || command.toLowerCase() !== settings.command) return;
        if (!overlay.allowed(flags, settings.roles)) return;
        fire({ user: user });
    });
})();
