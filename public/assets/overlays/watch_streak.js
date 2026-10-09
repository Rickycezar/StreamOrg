/**
 * Watch-streak alert (from seq_alert): when a viewer shares their watch
 * streak in chat, a pixel-art card shows their name in their chat colour,
 * the streak and their message (with emotes). Streaks listed as big get the
 * full show and their own sound and time on screen. Rules per streak
 * ([streak 100]) and per viewer ([user name]: a sound for their big
 * streaks, or always, and a dedication under the card) come from advanced
 * mode. Alerts wait their turn; tests use a random streak.
 */
(function () {
    'use strict';

    const overlay = window.StreamOrgOverlay;
    const stage = document.getElementById('overlay-stage');
    const queue = [];
    let busy = false;
    let hideTimer = null;

    const container = document.createElement('div');
    container.className = 'alertContainer';
    stage.append(container);

    const TWITCH_COLORS = ['#FF0000', '#0000FF', '#008000', '#B22222', '#FF7F50', '#9ACD32', '#FF4500', '#2E8B57',
        '#DAA520', '#D2691E', '#5F9EA0', '#1E90FF', '#FF69B4', '#8A2BE2', '#00FF7F'];

    function s() { return overlay.settings || {}; }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function hexToRgb(hex) {
        const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!m) return null;
        const n = parseInt(m[1], 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }

    function css(c) { return 'rgb(' + Math.round(c.r) + ', ' + Math.round(c.g) + ', ' + Math.round(c.b) + ')'; }
    function mix(a, b, t) { return { r: a.r + (b.r - a.r) * t, g: a.g + (b.g - a.g) * t, b: a.b + (b.b - a.b) * t }; }

    function luminance(c) {
        const v = [c.r, c.g, c.b].map(function (x) {
            x /= 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    }

    function contrast(a, b) {
        const la = luminance(a), lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }

    /** The card's colours from the viewer's chat colour (or a Twitch default picked from their name). */
    function palette(color, user) {
        let rgb = hexToRgb(color);
        if (!rgb) {
            const hash = Array.from(user || '').reduce(function (sum, ch) { return sum + ch.charCodeAt(0); }, 0);
            rgb = hexToRgb(TWITCH_COLORS[hash % TWITCH_COLORS.length]);
        }

        const black = { r: 16, g: 16, b: 24 };
        const white = { r: 255, g: 255, b: 255 };
        const panel = hexToRgb(s().panel_color) || hexToRgb('#1c1c3c');
        let name = rgb;
        for (let i = 0; i < 10 && contrast(name, panel) < 4.5; i++) name = mix(name, white, 0.15);

        return {
            '--user-color': css(rgb),
            '--user-light': css(mix(rgb, white, 0.45)),
            '--user-dark': css(mix(rgb, black, 0.45)),
            '--user-ink': contrast(rgb, black) >= contrast(rgb, white) ? '#101018' : '#f8f8f8',
            '--user-name': css(name),
            spark: css(rgb)
        };
    }

    function bigStreaks() { return (s().big_streaks || []).map(Number); }

    function rule(map, target) {
        return map && Object.prototype.hasOwnProperty.call(map, target) ? map[target] : null;
    }

    /** A rule StreamOrg sends only to this channel for this viewer (not a setting). */
    function special(login) {
        return rule(s()._special, login);
    }

    /** Which sound plays: the viewer's (always, or on big streaks), the streak's, the big one, the normal one. */
    function soundFor(alert) {
        const settings = s();
        if (special(alert.login)) return special(alert.login).sound || null;
        const user = rule(settings.users, alert.login) || {};
        const allowed = !settings.user_sounds_sub_only || alert.flags.subscriber || alert.flags.founder;
        const streak = rule(settings.streaks, String(alert.streak)) || {};

        if (user.always) return user.sound || null;

        return [
            alert.isBig && allowed ? user.sound : null,
            streak.sound,
            alert.isBig ? settings.sound_big : null,
            settings.sound_normal
        ].filter(Boolean)[0] || null;
    }

    function wave(text) {
        const fragment = document.createDocumentFragment();
        Array.from(text).forEach(function (ch, i) {
            const span = el('span', '', ch === ' ' ? ' ' : ch);
            span.style.setProperty('--i', i);
            fragment.append(span);
        });
        return fragment;
    }

    function render(alert) {
        const settings = s();
        const colors = palette(alert.color, alert.user);
        const user = rule(settings.users, alert.login) || {};
        const widget = el('div', 'streak-alert' + (alert.isBig ? ' big' : ''));
        Object.keys(colors).forEach(function (k) { if (k.indexOf('--') === 0) widget.style.setProperty(k, colors[k]); });

        if (alert.isBig) {
            const tape = el('div', 'streak-tape');
            const tapeText = overlay.fill(settings.tape || '★ {streak}', { streak: alert.streak, user: alert.user }) + ' ';
            tape.append(el('div', 'streak-tape-track', tapeText.repeat(20)));
            const banner = el('div', 'streak-banner');
            banner.append(wave(overlay.fill(settings.banner || '', { streak: alert.streak, user: alert.user })));
            widget.append(tape, banner);
        }

        const card = el('div', 'pixel-card');
        if (alert.isBig) card.append(el('div', 'grass'));

        const main = el('div', 'card-main');
        const block = el('div', 'streak-block');
        const num = el('div', String(alert.streak).length >= 3 ? 'streak-num long' : 'streak-num', alert.isBig ? '0' : String(alert.streak));
        block.append(num);

        const content = el('div', 'streak-content');
        const name = el('div', 'streak-name', alert.user);
        if (alert.isTest) name.append(el('span', 'streak-test-tag', alert.isTest));
        const line = el('div', 'streak-line');
        line.append(overlay.rich(alert.isBig ? settings.line_big : settings.line_small, { streak: alert.streak, user: alert.user }));
        content.append(name, line);

        if (settings.show_message !== false && alert.message) {
            const msg = el('div', 'streak-msg');
            msg.append(overlay.message(alert.message, alert.emotes));
            content.append(msg);
        }

        main.append(block, content);
        card.append(main, el('div', 'earth'));
        widget.append(card);

        const dedicated = (special(alert.login) || {}).dedication || user.dedication;
        if (dedicated) {
            const dedication = el('div', 'streak-dedication');
            dedication.append(wave(dedicated));
            widget.append(dedication);
        }

        container.replaceChildren(widget);
        widget.getBoundingClientRect();
        widget.classList.add('in');

        const sound = soundFor(alert);
        if (sound) overlay.playSound(sound);

        if (alert.isBig) {
            setTimeout(function () {
                countUp(num, alert.streak, 900);
                sparks(block, colors.spark);
            }, 550);
        }

        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, (alert.isBig ? settings.show_seconds_big : settings.show_seconds) * 1000);
    }

    function countUp(node, target, duration) {
        const start = performance.now();
        const tick = function (now) {
            const t = Math.min(1, (now - start) / duration);
            node.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
        setTimeout(function () { node.textContent = String(target); }, duration + 100);
    }

    function sparks(origin, color) {
        const colors = ['#f8d830', '#f8f8f8', '#80e030', color];
        for (let i = 0; i < 28; i++) {
            const spark = el('div', 'spark');
            const angle = Math.random() * Math.PI * 2;
            const dist = 90 + Math.random() * 160;
            spark.style.setProperty('--x', Math.round(Math.cos(angle) * dist / 4) * 4 + 'px');
            spark.style.setProperty('--y', Math.round(Math.sin(angle) * dist / 4) * 4 + 'px');
            spark.style.setProperty('--s', [12, 12, 16, 20][i % 4] + 'px');
            spark.style.setProperty('--c', colors[i % colors.length]);
            spark.style.setProperty('--dur', (0.7 + Math.random() * 0.6) + 's');
            spark.style.setProperty('--delay', (Math.random() * 0.15) + 's');
            origin.append(spark);
            spark.addEventListener('animationend', function () { spark.remove(); });
        }
    }

    function hide() {
        const widget = container.querySelector('.streak-alert');
        overlay.sound.stop(s().music_fade || 0);

        if (!widget) {
            busy = false;
            next();
            return;
        }

        widget.classList.remove('in');
        widget.classList.add('out');
        setTimeout(function () {
            container.replaceChildren();
            setTimeout(function () {
                busy = false;
                next();
            }, (s().queue_gap || 0) * 1000);
        }, 600);
    }

    function next() {
        if (busy || !queue.length || !overlay.settings) return;
        busy = true;
        render(queue.shift());
    }

    function add(alert) {
        if (queue.length >= 30) return;
        alert.isBig = bigStreaks().indexOf(alert.streak) !== -1;
        queue.push(alert);
        next();
    }

    function randomStreak(big) {
        const list = bigStreaks();
        if (big) return list.length ? list[Math.floor(Math.random() * list.length)] : 100;
        let n;
        do { n = Math.floor(Math.random() * 98) + 2; } while (list.indexOf(n) !== -1);
        return n;
    }

    overlay.on('settings', function (settings) {
        const root = document.documentElement.style;
        root.setProperty('--streak-panel', settings.panel_color || '#1c1c3c');
        root.setProperty('--streak-highlight', settings.highlight_color || '#f8d830');
        root.setProperty('--streak-bottom', (settings.bottom ?? 48) + 'px');
        next();
    });

    overlay.on('test', function (payload) {
        const user = payload.user || 'StreamOrg';
        add({
            user: user,
            login: String(user).toLowerCase(),
            streak: randomStreak(payload.event === 'streak_big'),
            message: 'GG Kappa',
            emotes: '25:3-7',
            color: TWITCH_COLORS[Math.floor(Math.random() * TWITCH_COLORS.length)],
            flags: { subscriber: true },
            isTest: payload.label || 'TEST'
        });
    });

    overlay.on('raw', function (event) {
        const tags = event.tags;
        if (event.command !== 'USERNOTICE' || tags['msg-param-category'] !== 'watch-streak') return;

        add({
            user: tags['display-name'] || tags.login,
            login: String(tags.login || '').toLowerCase(),
            streak: parseInt(tags['msg-param-value'], 10) || 0,
            message: event.params[1] || '',
            emotes: tags.emotes || null,
            color: tags.color || null,
            flags: event.flags,
            isTest: false
        });
    });
})();
