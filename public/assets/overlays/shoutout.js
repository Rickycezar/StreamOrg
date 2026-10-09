/**
 * Shoutout (from so_amorph): a moderator types "!so name" (any of the
 * chosen commands, by the chosen roles) and the channel appears with its
 * picture, category and title, looked up by StreamOrg. Colours are fixed
 * or worked out from the channel's name (the same name always gets the
 * same colours); rules per channel ([user name]) set its own colours and
 * sound. Shoutouts wait their turn; a test shows your own channel.
 */
(function () {
    'use strict';

    const overlay = window.StreamOrgOverlay;
    const stage = document.getElementById('overlay-stage');
    const queue = [];
    let busy = false;

    const widget = document.createElement('div');
    widget.className = 'amorph-container';
    widget.innerHTML = '<div class="amorph four"></div><div class="amorph three"></div><div class="amorph two"></div><div class="amorph one"></div>'
        + '<div class="info-bar"><div class="text-area"><h1></h1><h2></h2><div class="separator"></div><h3></h3></div></div>';
    stage.append(widget);

    function s() { return overlay.settings || {}; }

    function safeUrl(value) {
        return typeof value === 'string' && /^https:\/\/[^\s"'()\\]+$/.test(value) ? value : null;
    }

    /** Colours worked out from a name: the same name always gets the same ones. */
    function seeded(name) {
        const settings = s();
        let hash = 0;
        for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);

        const value = function (offset) {
            const x = Math.sin(hash + offset + (settings.hue_shift ?? 50)) * 10000;
            return x - Math.floor(x);
        };
        const hsl = function (offset, sat, light) { return 'hsl(' + Math.floor(value(offset) * 360) + ', ' + sat + '%, ' + light + '%)'; };
        const light = settings.lightness ?? 15;

        return {
            bar_color: hsl(1, settings.saturation ?? 35, light),
            text_color: light > 30 ? '#2d2d2d' : '#efefef',
            accent_color: hsl(2, 90, 35),
            blob1: hsl(3, 95, 50),
            blob2: hsl(4, 80, 40),
            blob3: hsl(5, 90, 70)
        };
    }

    function theme(channel) {
        const settings = s();
        const base = settings.colors === 'auto' ? seeded(channel.name || channel.login) : settings;
        const own = (settings.users && settings.users[channel.login]) || {};
        const pick = function (key) { return own[key] || base[key]; };
        const style = widget.style;

        style.setProperty('--bar-background', pick('bar_color'));
        style.setProperty('--blob1', pick('blob1'));
        style.setProperty('--blob2', pick('blob2'));
        style.setProperty('--blob3', pick('blob3'));
        style.setProperty('--text-color', pick('text_color'));
        style.setProperty('--text-accent', pick('accent_color'));
    }

    async function show(login) {
        const settings = s();
        const channel = await overlay.lookup(login);

        if (!channel) {
            setTimeout(done, 500);
            return;
        }

        const art = settings.show_box_art !== false ? safeUrl(channel.box_art) : null;
        const avatar = safeUrl(channel.avatar);
        widget.querySelector('h1').textContent = channel.name || channel.login;
        widget.querySelector('h2').textContent = settings.show_category !== false ? (channel.category || settings.no_category || '') : '';
        widget.querySelector('h3').textContent = settings.show_title !== false ? (channel.title || '') : '';
        widget.querySelector('.amorph.one').style.backgroundImage = avatar ? 'url("' + avatar + '")' : '';
        widget.style.setProperty('--box-art', art ? 'url("' + art + '")' : 'none');
        widget.classList.toggle('no-art', !art);
        theme(channel);

        widget.classList.remove('out');
        widget.getBoundingClientRect();
        widget.classList.add('in');

        const own = (settings.users && settings.users[channel.login]) || {};
        const sound = own.sound || settings.sound;
        if (sound) overlay.playSound(sound);

        setTimeout(function () {
            overlay.sound.stop(sound && sound.fade_out ? sound.fade_out : 0);
            widget.classList.remove('in');
            widget.classList.add('out');
            setTimeout(done, (settings.queue_gap ?? 2) * 1000 + 1400);
        }, (settings.show_seconds || 13) * 1000);
    }

    function done() {
        busy = false;
        next();
    }

    function next() {
        if (busy || !queue.length || !overlay.settings) return;
        busy = true;
        show(queue.shift());
    }

    function add(login) {
        login = String(login || '').trim().split(/\s+/)[0].replace(/^@/, '');
        if (!login || queue.length >= 20) return;
        queue.push(login);
        next();
    }

    overlay.on('settings', next);

    overlay.on('test', function (payload) { add(payload.user || overlay.channel); });

    overlay.on('command', function (c) {
        const settings = s();
        if ((settings.commands || []).indexOf(String(c.command).toLowerCase()) === -1) return;
        if (!overlay.allowed(c.flags, settings.roles)) return;
        add(c.message);
    });
})();
