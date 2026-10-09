/**
 * StreamOrg overlay runtime, shared by every overlay type.
 *
 * Reads the overlay's key from the "#" of its link, asks StreamOrg for its
 * settings and signals, and checks again every few seconds: new settings
 * reach the type's script ("settings"), tests from the settings page fire
 * "test", and a new link reloads the page. The owner's own CSS (advanced
 * mode) comes with the settings. Inside the settings page's preview, the
 * parent page sends unsaved settings and tests directly, and answers
 * channel lookups. Sounds go through the shared SoundLib (sprite parts and
 * fades included), chat through the bundled ComfyJS.
 *
 * A type's script uses window.StreamOrgOverlay:
 *   on(event, fn)          "settings" (also called at once if known), "test",
 *                          and chat: "command", "chat", "reward", "sub",
 *                          "resub", "cheer", "raw" (each with one object)
 *   playSound(sound)       a sound setting {url, start, duration, volume, fade_in, fade_out}
 *   chat(fn)               fn(user, command, message, flags, extra) for chat commands
 *   allowed(flags, roles)  whether a chatter has one of the roles
 *   fill(template, data)   "{user} chegou!" → text, never HTML
 *   rich(template, data)   the same, with *words* in <strong> (a DOM fragment)
 *   message(text, emotes)  a chat message as DOM nodes, Twitch and BTTV emotes as images
 *   knownBots()            a Set of known bot logins (Twitch Insights), once
 *   lookup(login)          a channel's details for a shoutout, or null
 */
(function () {
    'use strict';

    const body = document.body;
    const notice = document.getElementById('overlay-notice');
    const customCss = document.getElementById('overlay-custom-css');
    const key = decodeURIComponent((window.location.hash || '').slice(1));
    const listeners = {};
    const sound = new SoundLib('');
    const inPreview = window.parent !== window;
    const bttv = {};
    const lookups = {};

    let version = 0;
    let since = -1;
    let timer = null;
    let chatStarted = false;
    let emotesFor = null;
    let bots = null;
    let lookupSeq = 0;

    const say = function (text) {
        notice.textContent = text || '';
        notice.hidden = !text;
    };

    const overlay = window.StreamOrgOverlay = {
        type: body.dataset.type,
        settings: null,
        channel: null,
        channelId: null,
        preview: inPreview,
        sound: sound,

        on: function (event, fn) {
            (listeners[event] = listeners[event] || []).push(fn);
            if (event === 'settings' && overlay.settings) fn(overlay.settings);
            if (['command', 'chat', 'reward', 'sub', 'resub', 'cheer', 'raw'].indexOf(event) !== -1) startChat();
        },

        emit: function (event, data) {
            (listeners[event] || []).forEach(function (fn) {
                try { fn(data); } catch (e) { console.error(e); }
            });
        },

        soundName: function (ref) {
            return ref.url + '#' + (ref.start || 0) + '+' + (ref.duration || '');
        },

        playSound: function (ref) {
            if (!ref || !ref.url) return null;
            const name = overlay.soundName(ref);
            if (!sound.has(name)) preloadOne(ref);
            return sound.play(name, { volume: ref.volume ?? 1, fadeIn: ref.fade_in || 0, fadeOut: ref.fade_out || 0 });
        },

        allowed: function (flags, roles) {
            roles = roles || [];
            flags = flags || {};
            if (roles.indexOf('everyone') !== -1) return true;
            if (flags.broadcaster && roles.indexOf('broadcaster') !== -1) return true;
            if (flags.mod && roles.indexOf('mod') !== -1) return true;
            if (flags.vip && roles.indexOf('vip') !== -1) return true;
            if ((flags.subscriber || flags.founder) && roles.indexOf('subscriber') !== -1) return true;
            return !!flags.broadcaster;
        },

        fill: function (template, data) {
            return String(template || '').replace(/\{(\w+)\}/g, function (whole, name) {
                return data && data[name] !== undefined && data[name] !== null ? String(data[name]) : whole;
            });
        },

        rich: function (template, data) {
            const fragment = document.createDocumentFragment();
            overlay.fill(template, data).split('*').forEach(function (part, i) {
                if (part === '') return;
                if (i % 2) {
                    const strong = document.createElement('strong');
                    strong.textContent = part;
                    fragment.append(strong);
                } else {
                    fragment.append(document.createTextNode(part));
                }
            });
            return fragment;
        },

        message: function (text, emotes) {
            return renderMessage(String(text || ''), emotes);
        },

        knownBots: function () {
            if (!bots) {
                bots = fetch('https://api.twitchinsights.net/v1/bots/all')
                    .then(function (r) { return r.ok ? r.json() : { bots: [] }; })
                    .then(function (data) {
                        return new Set((data.bots || []).map(function (bot) {
                            return String(Array.isArray(bot) ? bot[0] : (bot.name || bot[0] || '')).toLowerCase();
                        }));
                    })
                    .catch(function () { return new Set(); });
            }
            return bots;
        },

        lookup: function (login) {
            login = String(login || '').replace(/^@/, '').trim().toLowerCase();
            if (!/^[a-z0-9_]{1,25}$/.test(login)) return Promise.resolve(null);

            if (inPreview) {
                return new Promise(function (resolve) {
                    const id = ++lookupSeq;
                    lookups[id] = resolve;
                    window.parent.postMessage({ type: 'streamorg-preview-lookup', id: id, login: login }, '*');
                    setTimeout(function () { if (lookups[id]) { delete lookups[id]; resolve(null); } }, 8000);
                });
            }

            return fetch(body.dataset.lookupUrl, { method: 'POST', body: new URLSearchParams({ k: key, login: login }), cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : { channel: null }; })
                .then(function (data) { return data.channel || null; })
                .catch(function () { return null; });
        },

        chat: function (fn) {
            overlay.on('command', function (c) { fn(c.user, c.command, c.message, c.flags, c.extra); });
        }
    };

    function preloadOne(ref) {
        sound.preload([{ name: overlay.soundName(ref), fileName: ref.url, start: ref.start || 0, duration: ref.duration || null, volume: ref.volume ?? 1, fadeIn: ref.fade_in || 0, fadeOut: ref.fade_out || 0 }]);
    }

    /** Twitch emote positions, as ComfyJS gives them ({id: ["0-4"]}) or as a raw tag ("25:0-4,12-16/1902:6-10"). */
    function emotePositions(emotes) {
        const out = [];
        if (!emotes) return out;

        if (typeof emotes === 'string') {
            emotes.split('/').forEach(function (part) {
                const pieces = part.split(':');
                (pieces[1] || '').split(',').forEach(function (range) {
                    const ends = range.split('-').map(Number);
                    if (pieces[0] && !isNaN(ends[0]) && !isNaN(ends[1])) out.push({ id: pieces[0], start: ends[0], end: ends[1] });
                });
            });
        } else {
            Object.keys(emotes).forEach(function (id) {
                (Array.isArray(emotes[id]) ? emotes[id] : []).forEach(function (range) {
                    const ends = String(range).split('-').map(Number);
                    out.push({ id: id, start: ends[0], end: ends[1] });
                });
            });
        }

        return out.sort(function (a, b) { return a.start - b.start; });
    }

    function emoteImage(src, alt) {
        const img = document.createElement('img');
        img.className = 'emote';
        img.alt = alt;
        img.src = src;
        return img;
    }

    function renderMessage(text, emotes) {
        const fragment = document.createDocumentFragment();
        const chars = Array.from(text);
        let at = 0;

        const words = function (piece) {
            piece.split(/(\s+)/).forEach(function (word) {
                if (bttv[word]) fragment.append(emoteImage('https://cdn.betterttv.net/emote/' + bttv[word] + '/2x', word));
                else if (word) fragment.append(document.createTextNode(word));
            });
        };

        emotePositions(emotes).forEach(function (emote) {
            if (emote.start < at || !/^[\w-]+$/.test(emote.id)) return;
            words(chars.slice(at, emote.start).join(''));
            fragment.append(emoteImage('https://static-cdn.jtvnw.net/emoticons/v2/' + emote.id + '/default/dark/2.0', chars.slice(emote.start, emote.end + 1).join('')));
            at = emote.end + 1;
        });

        words(chars.slice(at).join(''));
        return fragment;
    }

    function loadEmotes(channelId) {
        if (body.dataset.emotes !== '1' || emotesFor === (channelId || '')) return;
        emotesFor = channelId || '';

        const add = function (list) {
            (list || []).forEach(function (emote) { if (emote && emote.code && emote.id) bttv[emote.code] = emote.id; });
        };

        fetch('https://api.betterttv.net/3/cached/emotes/global').then(function (r) { return r.ok ? r.json() : []; }).then(add).catch(function () {});

        if (channelId) {
            fetch('https://api.betterttv.net/3/cached/users/twitch/' + encodeURIComponent(channelId))
                .then(function (r) { return r.ok ? r.json() : {}; })
                .then(function (data) { add(data.channelEmotes); add(data.sharedEmotes); })
                .catch(function () {});
        }
    }

    function flagsFromTags(tags) {
        const badges = tags.badges || '';
        return {
            broadcaster: badges.indexOf('broadcaster') !== -1,
            mod: tags.mod === '1',
            vip: badges.indexOf('vip') !== -1,
            subscriber: badges.indexOf('subscriber') !== -1 || badges.indexOf('founder') !== -1
        };
    }

    function startChat() {
        if (chatStarted || !overlay.channel || !window.ComfyJS || inPreview) return;
        chatStarted = true;

        const C = window.ComfyJS;
        C.onCommand = function (user, command, message, flags, extra) { overlay.emit('command', { user: user, command: command, message: message, flags: flags, extra: extra }); };
        C.onChat = function (user, message, flags, self, extra) { overlay.emit('chat', { user: user, message: message, flags: flags, extra: extra }); };
        C.onReward = function (user, reward, cost, message, extra) { overlay.emit('reward', { user: user, reward: reward, cost: cost, message: message, extra: extra }); };
        C.onSub = function (user, message, subTierInfo, extra) { overlay.emit('sub', { user: user, message: message, tier: subTierInfo, extra: extra }); };
        C.onResub = function (user, message, streakMonths, cumulativeMonths, subTierInfo, extra) {
            overlay.emit('resub', { user: user, message: message, streak: streakMonths, months: cumulativeMonths, tier: subTierInfo, extra: extra });
        };
        C.onCheer = function (user, message, bits, flags, extra) { overlay.emit('cheer', { user: user, message: message, bits: bits, flags: flags, extra: extra }); };
        C.Init(overlay.channel);

        const client = C.GetClient && C.GetClient();
        if (client && client.on) {
            client.on('raw_message', function (clone, data) {
                if (!data || !data.tags) return;
                overlay.emit('raw', { command: data.command, tags: data.tags, params: data.params || [], flags: flagsFromTags(data.tags) });
            });
        }
    }

    function preloadSounds(value) {
        if (!value || typeof value !== 'object') return;
        if (value.url && 'start' in value) {
            if (!sound.has(overlay.soundName(value))) preloadOne(value);
            return;
        }
        Object.keys(value).forEach(function (name) { preloadSounds(value[name]); });
    }

    function apply(settings, css) {
        overlay.settings = settings;
        if (typeof css === 'string') customCss.textContent = css;
        preloadSounds(settings);
        overlay.emit('settings', settings);
    }

    function schedule(seconds) {
        clearTimeout(timer);
        timer = setTimeout(poll, Math.max(2, seconds || 5) * 1000);
    }

    async function poll() {
        let state;

        try {
            const response = await fetch(body.dataset.stateUrl, {
                method: 'POST',
                body: new URLSearchParams({ k: key, v: String(version), since: String(since) }),
                cache: 'no-store'
            });

            if (response.status === 404) {
                say(body.dataset.unknownKey);
                return;
            }

            state = await response.json();
        } catch (e) {
            schedule(10);
            return;
        }

        if (state.state === 'off') {
            body.classList.add('is-off');
            version = 0;
            schedule(state.poll);
            return;
        }

        body.classList.remove('is-off');
        say('');
        overlay.channel = state.channel || null;
        overlay.channelId = state.channel_id || null;
        loadEmotes(overlay.channelId);

        if (state.settings) {
            version = state.version;
            apply(state.settings, state.css);
        }

        since = state.since;
        (state.signals || []).forEach(function (signal) {
            if (signal.kind === 'test') overlay.emit('test', signal.payload || {});
            if (signal.kind === 'reload') window.location.reload();
        });

        if (Object.keys(listeners).some(function (e) { return ['command', 'chat', 'reward', 'sub', 'resub', 'cheer', 'raw'].indexOf(e) !== -1; })) startChat();
        schedule(state.poll);
    }

    window.addEventListener('message', function (event) {
        if (!inPreview || event.source !== window.parent || !event.data || typeof event.data !== 'object') return;
        const data = event.data;

        if (data.type === 'streamorg-preview-settings' && data.settings) {
            overlay.channel = data.channel || null;
            overlay.channelId = data.channel_id || null;
            loadEmotes(overlay.channelId);
            apply(data.settings, data.css || '');
        }
        if (data.type === 'streamorg-preview-test') overlay.emit('test', data.payload || {});
        if (data.type === 'streamorg-preview-lookup-result' && lookups[data.id]) {
            lookups[data.id](data.channel || null);
            delete lookups[data.id];
        }
    });

    if (!key) {
        if (!inPreview) say(body.dataset.missingKey);
        return;
    }

    if (document.readyState === 'loading') window.addEventListener('DOMContentLoaded', poll);
    else poll();
})();
