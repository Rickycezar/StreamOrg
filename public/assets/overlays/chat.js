/**
 * Chat (from the chat_*_new pages): the channel's chat with Twitch and
 * BTTV emotes, GIFs, highlighted messages, chosen rewards (or all), subs,
 * resubs, bits and watch streaks, each with its own look and line of text.
 * Bots are left out (the known-bots list and the streamer's own list);
 * messages can fade after a while, and only the latest few are kept. A
 * sound can play for each message. Tests show sample messages.
 */
(function () {
    'use strict';

    const overlay = window.StreamOrgOverlay;
    const stage = document.getElementById('overlay-stage');
    const container = document.createElement('div');
    container.className = 'chatContainer';
    stage.append(container);

    let bots = new Set();

    function s() { return overlay.settings || {}; }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function ignored(user) {
        const login = String(user || '').toLowerCase();
        return (s().ignored_users || []).indexOf(login) !== -1 || (s().hide_known_bots !== false && bots.has(login));
    }

    function safeColor(value) {
        return /^#[0-9a-f]{3,8}$/i.test(value || '') ? value : '#ffffff';
    }

    /** Adds a message: kind is chat, highlight, reward, event (subs, bits, streaks) or gif. */
    function add(kind, item) {
        if (!overlay.settings || ignored(item.user)) return;
        const settings = s();
        const card = el('div', 'chatMessage' + (kind === 'event' ? ' gradient-bg' : kind === 'chat' ? '' : ' ' + kind));

        const head = el('div', 'username');
        const badges = el('div', 'badges');
        const flags = item.flags || {};
        [['broadcaster', '👑'], ['mod', '⚔️'], ['vip', '💎'], ['subscriber', '⭐']].forEach(function (b) {
            if (flags[b[0]]) badges.append(el('span', '', b[1]));
        });
        const name = el('div', 'name', item.user);
        name.style.color = safeColor(item.color);
        head.append(badges, name);
        card.append(head);

        if (item.system) card.append(el('div', 'system-text', item.system));

        const message = el('div', 'message');
        if (kind === 'gif') {
            const img = el('img');
            img.alt = '';
            img.src = item.gif;
            message.append(img);
        } else {
            message.append(overlay.message(item.message, item.emotes));
        }
        if (kind === 'gif' || message.childNodes.length) card.append(message);

        container.append(card);

        while (container.children.length > (settings.max_messages || 30)) container.firstElementChild.remove();

        if (settings.hide_after > 0) {
            setTimeout(function () {
                card.classList.add('leaving');
                setTimeout(function () { card.remove(); }, 650);
            }, settings.hide_after * 1000);
        }

        if (settings.message_sound) overlay.playSound(settings.message_sound);
    }

    function systemText(template, data) { return overlay.fill(template, data); }

    overlay.on('settings', function (settings) {
        const root = document.documentElement.style;
        root.setProperty('--chat-font-size', (settings.font_size || 22) + 'px');
        root.setProperty('--chat-emote-size', (settings.emote_size || 32) + 'px');
        root.setProperty('--chat-card', settings.card_color || '#0f0f12');
        root.setProperty('--chat-text', settings.text_color || '#e0e0e0');
        root.setProperty('--chat-highlight', settings.highlight_color || '#ffd700');
        root.setProperty('--chat-reward', settings.reward_color || '#00e5ff');
        document.body.classList.remove('theme-cards', 'theme-outline', 'align-left', 'align-right');
        document.body.classList.add('theme-' + (settings.theme || 'cards'), 'align-' + (settings.align || 'left'));
        document.body.classList.toggle('show-badges', !!settings.show_badges);

        if (settings.hide_known_bots !== false) overlay.knownBots().then(function (set) { bots = set; });
    });

    overlay.on('chat', function (c) {
        const highlighted = c.flags && c.flags.highlighted;
        if (highlighted && s().show_highlights === false) return;
        add(highlighted ? 'highlight' : 'chat', {
            user: c.user, message: c.message, emotes: c.extra && c.extra.messageEmotes, color: c.extra && c.extra.userColor,
            flags: c.flags, system: highlighted ? s().text_highlight : ''
        });
    });

    overlay.on('reward', function (c) {
        const settings = s();
        if (settings.show_rewards === false) return;
        if ((settings.rewards || []).length && settings.rewards.map(function (r) { return r.toLowerCase(); }).indexOf(String(c.reward).toLowerCase()) === -1) return;
        add('reward', {
            user: c.user, message: c.message, emotes: c.extra && c.extra.messageEmotes, color: c.extra && c.extra.userColor,
            system: systemText(settings.text_reward, { user: c.user, reward: c.reward, cost: c.cost })
        });
    });

    overlay.on('sub', function (c) {
        if (s().show_subs === false) return;
        add('event', { user: c.user, message: c.message, emotes: c.extra && c.extra.messageEmotes, color: c.extra && c.extra.userColor, system: systemText(s().text_sub, { user: c.user }) });
    });

    overlay.on('resub', function (c) {
        if (s().show_subs === false) return;
        add('event', {
            user: c.user, message: c.message, emotes: c.extra && c.extra.messageEmotes, color: c.extra && c.extra.userColor,
            system: systemText(s().text_resub, { user: c.user, months: c.months, streak: c.streak })
        });
    });

    overlay.on('cheer', function (c) {
        if (s().show_cheers === false) return;
        add('event', { user: c.user, message: c.message, emotes: c.extra && c.extra.messageEmotes, color: c.extra && c.extra.userColor, flags: c.flags, system: systemText(s().text_cheer, { user: c.user, bits: c.bits }) });
    });

    /**
     * Messages that arrive only as raw chat lines: GIFs, and watch streaks
     * (whose emotes come as Twitch's raw tag, "25:0-4/1902:6-10", read by
     * the same renderer as the watch-streak alert).
     */
    function raw(event) {
        const tags = event.tags;
        const user = tags['display-name'] || tags.login;

        if (event.command === 'PRIVMSG' && tags.gifs && s().show_gifs !== false) {
            const gif = String(tags.gifs).split('|')[2] || '';
            if (/^https:\/\/[^\s"'<>]+$/.test(gif)) add('gif', { user: user, gif: gif, color: tags.color, flags: event.flags });
        }

        if (event.command === 'USERNOTICE' && tags['msg-param-category'] === 'watch-streak' && s().show_streaks !== false) {
            add('event', {
                user: user, message: event.params[1] || '', emotes: tags.emotes || null, color: tags.color, flags: event.flags,
                system: systemText(s().text_streak, { user: user, streak: tags['msg-param-value'] })
            });
        }
    }

    overlay.on('raw', raw);

    overlay.on('test', function (payload) {
        const settings = s();
        const user = payload.user || 'StreamOrg';
        const sample = { user: user, message: 'Hello chat! Kappa', emotes: { 25: ['12-16'] }, color: '#9146ff', flags: { broadcaster: true } };

        if (payload.event === 'highlight') add('highlight', Object.assign(sample, { system: settings.text_highlight }));
        else if (payload.event === 'reward') add('reward', Object.assign(sample, { system: systemText(settings.text_reward, { user: user, reward: (settings.rewards || [])[0] || 'Hydrate', cost: 500 }) }));
        else if (payload.event === 'sub') add('event', Object.assign(sample, { system: systemText(settings.text_resub, { user: user, months: 12, streak: 3 }) }));
        else if (payload.event === 'streak') {
            raw({
                command: 'USERNOTICE',
                tags: { 'display-name': user, login: String(user).toLowerCase(), color: sample.color, emotes: '25:12-16', 'msg-param-category': 'watch-streak', 'msg-param-value': '15' },
                params: ['#' + (overlay.channel || user), sample.message],
                flags: sample.flags
            });
        }
        else add('chat', sample);
    });
})();
