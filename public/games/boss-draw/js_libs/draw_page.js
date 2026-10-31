/**
 * BossDraw page
 *
 * The draw page: a fixed 1280×720 stage scaled to fit the window, a panel
 * for the streamer (everyday options in "Partida", technical ones in
 * "Avançado"), the speed buttons and fullscreen on top. Texts come from
 * draw_lang.js. With a Twitch channel connected, chat joins with the entry
 * command and the broadcaster can run it with !open, !start and !reset.
 *
 * Two modes (STREAMORG_INTEGRATION.md): public, where the streamer sets
 * everything and the result is only shown; embedded, inside a StreamOrg
 * page that sends a bossdraw:config message, where the channel, command,
 * prize and winners come from StreamOrg, settings are kept by StreamOrg
 * (the sandboxed frame has no storage) and the confirmed result goes back
 * to it by postMessage.
 *
 * @author Henrique Cezar
 * @license MIT
 */

import { DrawSound, DRAW_SOUND_DEFAULTS } from './draw_sound.js';

/** Page constants. */
const DRAW_PAGE = {
    defaultEntry: '!join',
    maxWinners: 10,
    stageWidth: 1280,
    stageHeight: 720,
    minWidth: 640,
    minHeight: 360,
    storageKey: 'bossDrawParams',
    settingsKey: 'bossDrawSettings',
    soundKey: 'bossDrawSounds',
    audioKey: 'bossDrawAudio',
    handshakeMs: 1000,
    handshakeTries: 10,
    savedTimeoutMs: 15000,
    settingsDebounceMs: 2000,
    settingsMaxBytes: 8192,
    textsMaxBytes: 65536,
    lockable: ['channel', 'entry', 'prize', 'winners']
};

/** Technical parameters shown in "Avançado": input step, or choices for a select (winners and hearts live on the main tab). */
const PARAM_INFO = {
    rangedShare: [0.05],
    tickMs: [100],
    playerDamageMin: [1],
    playerDamageMax: [1],
    bossHp: [100],
    bossHpFrom: [null, ['fixed', 'entrants', 'alive']],
    bossHpPerEntrant: [1],
    bossHpGrowth: [0.05],
    firstAttackMs: [100],
    attackMinMs: [100],
    attackMaxMs: [100],
    bossFocus: [0.25],
    targetRatio: [0.05],
    targetBase: [null, ['side', 'alive', 'entrants']],
    minTargets: [1],
    minWipesSide: [null, ['yes', 'no']],
    respawnMs: [100],
    maxDurationMs: [1000]
};

/** Broadcaster commands in chat, by action; aliases share an action. */
const CHAT_COMMANDS = {
    open: ['open', 'abrir'],
    start: ['start', 'iniciar'],
    reopen: ['reopen', 'reabrir'],
    reset: ['reset', 'resetar']
};

const $ = (id) => document.getElementById(id);
const arenaHost = $('arena-host');
const framed = window.parent !== window;

let playback = null;
let lastResult = null;
let lobby = null;
let lockedEntrants = [];
/** Rounds of the current draw: the first battle, then one per "draw another". */
let rounds = [];
/** Winners who kept their place, in prize order. */
let kept = [];
/** Winners who stayed silent in their showdown: never winners again in this draw. */
const forfeited = new Set();
/** Logins that cannot enter (embedded config). */
let excluded = new Set();
let startedAt = null;
let finishedAt = null;
let confirmed = false;
let pendingConfirm = null;
let confirmTimer = null;
let chatTimer = null;
let speed = 1;
let chatOpen = false;
let connectedChannel = null;
let phase = null;
/** Who was kicked in the last entry phase (kept for "reopen" after a battle). */
let lastKicks = [];

/** Embedded mode: set by a valid bossdraw:config. */
let embedded = false;
let embedConfig = null;
let locks = new Set();
/** Settings while embedded (the sandboxed frame has no storage), by storage key. */
let memory = {};
let settingsTimer = null;
let handshakeTimer = null;
let textsFromConfig = null;

/**
 * Snake-case form of a parameter name, for its text keys.
 *
 * @param {string} name - Like "bossHpFrom".
 * @returns {string} Like "boss_hp_from".
 */
function snake(name) {
    return name.replace(/[A-Z]/g, c => `_${c.toLowerCase()}`);
}

/**
 * Reads a saved value: from StreamOrg's settings while embedded, else from
 * this browser.
 *
 * @param {string} key - Storage key.
 * @param {*} fallback - Value when nothing is saved or storage is blocked.
 */
function stored(key, fallback) {
    if (embedded) {
        return key in memory ? JSON.parse(JSON.stringify(memory[key])) : fallback;
    }
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch (e) {
        return fallback;
    }
}

/**
 * Saves a value: handed to StreamOrg while embedded, else kept in this
 * browser when storage is allowed.
 *
 * @param {string} key - Storage key.
 * @param {*} value - Value (null removes it).
 */
function store(key, value) {
    if (embedded) {
        if (value === null) {
            delete memory[key];
        }
        else {
            memory[key] = JSON.parse(JSON.stringify(value));
        }
        queueSettings();
        return;
    }
    try {
        if (value === null) {
            localStorage.removeItem(key);
        }
        else {
            localStorage.setItem(key, JSON.stringify(value));
        }
    } catch (e) {}
}

/** Page settings: channel, prize text, entry command, showdown, language. */
const settings = Object.assign({ channel: '', prize: '', entry: DRAW_PAGE.defaultEntry, showdown: false, showdownSeconds: 60, lang: '',
    entryFrom: 'everyone', subBonus: 'none', subBonusHearts: 1, extraRegen: false, listDuplicates: 'ignore', listMinHearts: 1, listMaxHearts: 5,
    bossCustom: false, bossName: '' },
    stored(DRAW_PAGE.settingsKey, {}));
delete settings.confirmUrl;
const query = new URLSearchParams(location.search);
if (query.get('canal')) {
    settings.channel = query.get('canal');
}

/** Saves the page settings (in this browser; while embedded they come from StreamOrg's config instead). */
function saveSettings() {
    if (!embedded) {
        store(DRAW_PAGE.settingsKey, settings);
    }
}

/** Panel fields of the draw options (DRAW_GAME.options), by option key. */
const OPTION_FIELDS = {
    hearts: 'main-hits',
    showdown: 'showdown-on',
    showdown_seconds: 'showdown-seconds',
    entry_from: 'rule-entry-from',
    sub_bonus: 'rule-sub-bonus',
    sub_bonus_hearts: 'rule-sub-hearts',
    extra_regen: 'rule-extra-regen',
    list_duplicates: 'rule-list-dups',
    list_min_hearts: 'rule-list-min',
    list_max_hearts: 'rule-list-max',
    boss_custom: 'boss-custom',
    boss_name: 'boss-name'
};

/** The options in use, each checked against its declaration. */
function optionValues() {
    const raw = {
        hearts: Number($('main-hits').value),
        showdown: !!settings.showdown,
        showdown_seconds: Number(settings.showdownSeconds),
        entry_from: settings.entryFrom,
        sub_bonus: settings.subBonus,
        sub_bonus_hearts: Number(settings.subBonusHearts),
        extra_regen: !!settings.extraRegen,
        list_duplicates: settings.listDuplicates,
        list_min_hearts: Number(settings.listMinHearts),
        list_max_hearts: Number(settings.listMaxHearts),
        boss_custom: !!settings.bossCustom,
        boss_name: String(settings.bossName || '')
    };
    const out = {};
    for (const option of DRAW_GAME.options) {
        out[option.key] = drawOptionValue(option, raw[option.key]);
    }
    return out;
}

/**
 * Puts option values (from StreamOrg's config) into the panel; unknown keys
 * are ignored, invalid or missing values fall back to the default.
 *
 * @param {object} values
 */
function applyOptions(values) {
    const v = {};
    for (const option of DRAW_GAME.options) {
        v[option.key] = drawOptionValue(option, values[option.key]);
    }
    settings.showdown = v.showdown;
    settings.showdownSeconds = v.showdown_seconds;
    settings.entryFrom = v.entry_from;
    settings.subBonus = v.sub_bonus;
    settings.subBonusHearts = v.sub_bonus_hearts;
    settings.extraRegen = v.extra_regen;
    settings.listDuplicates = v.list_duplicates;
    settings.listMinHearts = Math.min(v.list_min_hearts, v.list_max_hearts);
    settings.listMaxHearts = Math.max(v.list_min_hearts, v.list_max_hearts);
    settings.bossCustom = v.boss_custom;
    settings.bossName = v.boss_name;
    $('main-hits').value = v.hearts;
    fillRules();
}

/** Fills the "Regras" tab: the choices of each select (in the current language) and the values. */
function fillRules() {
    for (const select of document.querySelectorAll('select[data-option]')) {
        const option = DRAW_GAME.options.find(o => o.key === select.dataset.option);
        select.textContent = '';
        for (const choice of option.choices) {
            select.appendChild(new Option(drawT(`option.${option.key}.${choice}`), choice));
        }
    }
    $('rule-entry-from').value = settings.entryFrom;
    $('rule-sub-bonus').value = settings.subBonus;
    $('rule-sub-hearts').value = settings.subBonusHearts;
    $('rule-sub-hearts').disabled = locks.has('options.sub_bonus_hearts') || settings.subBonus !== 'flat';
    $('rule-extra-regen').checked = !!settings.extraRegen;
    $('rule-list-dups').value = settings.listDuplicates;
    $('rule-list-min').value = settings.listMinHearts;
    $('rule-list-max').value = settings.listMaxHearts;
    for (const [key, id] of [['list_min_hearts', 'rule-list-min'], ['list_max_hearts', 'rule-list-max']]) {
        $(id).disabled = locks.has(`options.${key}`) || settings.listDuplicates !== 'hearts';
    }
    $('boss-custom').checked = !!settings.bossCustom;
    $('boss-name').value = settings.bossName;
    $('boss-name').placeholder = connectedChannel || settings.channel || drawT('option.boss_name');
    $('boss-name').disabled = locks.has('options.boss_name') || !settings.bossCustom;
    $('showdown-on').checked = !!settings.showdown;
    $('showdown-seconds').value = settings.showdownSeconds;
}

/** The custom boss name in use (the channel name when left empty), or '' for the bosses' own names. */
function bossNameInUse() {
    if (!settings.bossCustom) {
        return '';
    }
    return String(settings.bossName || '').trim().slice(0, 40) || connectedChannel || settings.channel || '';
}

/** Shows the boss name on the entry screen. */
function showBossName() {
    if (lobby) {
        lobby.arena.bossLabel.textContent = drawBossLabel(lobby.arena.bossId, 1, bossNameInUse());
    }
}

/**
 * Whether a chatter may enter under the entry rule.
 *
 * @param {{tier: number, vip: boolean}} who
 * @returns {boolean}
 */
function mayEnter(who) {
    const rule = settings.entryFrom;
    const role = rule === 'everyone' || who.tier > 0 || (rule === 'subs_vips' && who.vip);
    return role && passesBadgeGate(who);
}

/**
 * The badge gate StreamOrg may set (config.badges): a chatter passes when
 * they show one of the badges, or hold one of the roles StreamOrg lets in
 * without it (config.badgeRoles: subscriber, vip, moderator).
 *
 * @param {{tier: number, vip: boolean, mod: boolean, badges: Object<string, string>}} who
 * @returns {boolean}
 */
function passesBadgeGate(who) {
    if (!requiredBadges().length) {
        return true;
    }
    const roles = embedConfig.badgeRoles;
    return hasRequiredBadge(who.badges || {})
        || (roles.includes('subscriber') && who.tier > 0)
        || (roles.includes('vip') && !!who.vip)
        || (roles.includes('moderator') && !!who.mod);
}

/**
 * The badges StreamOrg asks for (config.badges): only chatters showing one
 * of them may join. None outside StreamOrg, or when it sends none.
 *
 * @returns {Array<{id: string, name: string, level: string|null, title: string, image: string}>}
 */
function requiredBadges() {
    return embedded && embedConfig ? embedConfig.badges : [];
}

/**
 * Whether a chatter shows one of the badges StreamOrg asks for (always true without them).
 *
 * @param {Object<string, string>} badges - Badge name → level, as the chat sends them.
 * @returns {boolean}
 */
function hasRequiredBadge(badges) {
    const wanted = requiredBadges();
    if (!wanted.length) {
        return true;
    }
    const shown = Object.fromEntries(Object.entries(badges).map(([k, v]) => [String(k).toLowerCase(), String(v)]));
    return wanted.some(w => w.name in shown && (!w.level || shown[w.name] === w.level));
}

/**
 * Extra hearts for a subscriber under the bonus rule.
 *
 * @param {number} tier - 0 (not subscribed) to 3.
 * @returns {number}
 */
function bonusFor(tier) {
    if (!tier || settings.subBonus === 'none') {
        return 0;
    }
    return settings.subBonus === 'tier' ? tier : drawOptionValue(DRAW_GAME.options.find(o => o.key === 'sub_bonus_hearts'), settings.subBonusHearts);
}

/**
 * Subscription and VIP from a chat message's badges: the subscriber badge
 * version is 0–999 for Tier 1, 2000+ for Tier 2 and 3000+ for Tier 3
 * (founders count as Tier 1); months come from the badge info.
 *
 * @param {object} flags - ComfyJS flags.
 * @param {object} extra - ComfyJS extra.
 * @returns {{tier: number, months: number, vip: boolean}}
 */
function chatStatus(flags, extra) {
    const badges = (extra && extra.userBadges) || {};
    let tier = 0;
    if (badges.subscriber !== undefined || badges.founder !== undefined || (flags && (flags.subscriber || flags.founder))) {
        const version = Number(badges.subscriber) || 0;
        tier = version >= 3000 ? 3 : version >= 2000 ? 2 : 1;
    }
    let months = 0;
    const info = extra && extra.userState && extra.userState['badge-info'];
    if (info && typeof info === 'object' && (info.subscriber || info.founder)) {
        months = Number(info.subscriber || info.founder) || 0;
    }
    else if (typeof info === 'string') {
        const m = info.match(/(?:subscriber|founder)\/(\d+)/);
        months = m ? Number(m[1]) : 0;
    }
    const state = (extra && extra.userState) || {};
    return {
        tier,
        months,
        vip: badges.vip !== undefined || !!(flags && flags.vip) || state.vip === true || state.vip === '1',
        mod: !!(flags && (flags.mod || flags.broadcaster)) || state.mod === true || state.mod === '1',
        badges: Object.assign({}, badges)
    };
}

/**
 * Joins an entrant through the rules: refused (silently) when the entry
 * rule leaves them out; subscribers get their bonus hearts.
 *
 * @param {object} entry - For DrawLobby.join(), with tier and vip.
 * @param {object} [options] - For DrawLobby.join().
 */
function joinByRules(entry, options) {
    if (!lobby) {
        return;
    }
    const tier = Math.max(0, Math.min(3, Math.floor(Number(entry.tier) || 0)));
    const who = { tier, vip: !!entry.vip, mod: !!entry.mod, badges: entry.badges || {} };
    if (!mayEnter(who)) {
        return;
    }
    let bonus = bonusFor(tier);
    if (entry.fromList && settings.listDuplicates === 'hearts') {
        const base = readParams().hitsPerPlayer;
        const total = Math.min(settings.listMaxHearts, Math.max(settings.listMinHearts, base + bonus + (entry.extra || 0)));
        bonus = total - base;
    }
    lobby.join(Object.assign({}, entry, who, { bonus }), options);
}

/** Rebuilds the entry screen with the current rules and hearts, keeping its players, kicks and open state. */
function rebuildLobby() {
    if (!lobby) {
        return;
    }
    const wasOpen = chatOpen;
    openLobby(lobby.entrants(), lobby.kickedList());
    chatOpen = wasOpen;
    refreshHint();
}

/** Lists the kicked players in "Regras", each with a button to let them back in. */
function renderKicked() {
    const box = $('kicked-list');
    box.textContent = '';
    const list = lobby ? lobby.kickedList() : [];
    if (!list.length) {
        const none = document.createElement('span');
        none.className = 'hint-text';
        none.textContent = drawT('rules.kicked_none');
        box.appendChild(none);
    }
    for (const k of list) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn small';
        button.textContent = `${k.name} · ${drawT('rules.unkick')}`;
        button.addEventListener('click', () => {
            lobby.unkick([k.id]);
            renderKicked();
        });
        box.appendChild(button);
    }
    $('unkick-all').disabled = !list.length;
}

/** The rules changed: the entry screen is rebuilt with them (bonus hearts redone, those left out removed). */
function rulesChanged() {
    fillRules();
    if (!embedded) {
        saveSettings();
    }
    rebuildLobby();
}

/**
 * Lowercase, no accents, single spaces: how chat text and the entry command are compared.
 *
 * @param {string} text
 * @returns {string}
 */
function normalize(text) {
    return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
}

/**
 * The entry command: a single "!word" lets chatters pick a side after it
 * (!join melee); any other phrase only enters, and the side is drawn.
 */
function entryInfo() {
    const raw = (settings.entry || '').trim() || DRAW_PAGE.defaultEntry;
    const norm = normalize(raw);
    const picksSide = /^![^\s!]+$/.test(norm);
    const name = picksSide ? norm.slice(1) : null;
    return { raw, norm, picksSide, commands: name === 'join' ? ['join', 'entrar'] : name ? [name] : [] };
}

/** The number of winners asked for (1–10, and no more than StreamOrg's while embedded). */
function wantedWinners() {
    const most = embedded && embedConfig ? embedConfig.maxWinners : DRAW_PAGE.maxWinners;
    return Math.min(most, Math.max(1, Math.round(Number($('main-winners').value) || DRAW_DEFAULTS.winners)));
}

/** Builds the technical parameter inputs ("Avançado") and the two on the main tab. */
function buildParams(values) {
    const box = $('params');
    box.textContent = '';
    for (const [key, def] of Object.entries(DRAW_DEFAULTS)) {
        if (!PARAM_INFO[key]) {
            continue;
        }
        const [step, choices] = PARAM_INFO[key];
        const wrap = document.createElement('label');
        wrap.textContent = drawT(`param.${snake(key)}`);
        let input;
        if (choices) {
            input = document.createElement('select');
            for (const value of choices) {
                input.appendChild(new Option(drawT(`param.${snake(key)}.${value}`), value));
            }
        }
        else {
            input = document.createElement('input');
            input.type = 'number';
            input.step = step;
        }
        input.name = key;
        input.value = values[key] ?? def;
        input.addEventListener('change', saveParams);
        wrap.appendChild(input);
        box.appendChild(wrap);
    }
    $('main-winners').value = embedded && embedConfig
        ? Math.min(embedConfig.maxWinners, Number($('main-winners').value) || embedConfig.winners)
        : values.winners ?? DRAW_DEFAULTS.winners;
    $('main-hits').value = values.hitsPerPlayer ?? DRAW_DEFAULTS.hitsPerPlayer;
}

/** All battle parameters, from both tabs. */
function readParams() {
    const out = {};
    for (const input of $('params').querySelectorAll('[name]')) {
        out[input.name] = typeof DRAW_DEFAULTS[input.name] === 'number' ? Number(input.value) : input.value;
    }
    out.winners = wantedWinners();
    out.extraRegen = !!settings.extraRegen;
    out.hitsPerPlayer = Math.min(5, Math.max(1, Math.round(Number($('main-hits').value) || DRAW_DEFAULTS.hitsPerPlayer)));
    return out;
}

/** Remembers the parameters (while embedded, without hearts and winners: StreamOrg sends those). */
function saveParams() {
    $('main-winners').value = wantedWinners();
    const params = readParams();
    if (embedded) {
        delete params.hitsPerPlayer;
        delete params.winners;
        delete params.extraRegen;
    }
    store(DRAW_PAGE.storageKey, params);
}

/**
 * Shows the head counts.
 *
 * @param {{melee: number, ranged: number}} counts
 */
function showCounts(counts) {
    renderKicked();
    $('counts').textContent = drawT('entries.counts', { total: counts.melee + counts.ranged, ranged: counts.ranged, melee: counts.melee });
}

/** Shows the prize text on top of the arena. */
function showPrize() {
    $('prize').textContent = settings.prize || '';
    placePrize();
}

/**
 * Moves the prize ribbon (and the showdown countdown under it) down when
 * the buttons on top would cover it, as in a narrow frame.
 */
function placePrize() {
    const stage = $('stage');
    stage.style.setProperty('--top-offset', '0px');
    const prize = $('prize');
    if (!prize.textContent) {
        return;
    }
    const scale = Number(stage.style.getPropertyValue('--stage-scale')) || 1;
    const ribbon = prize.getBoundingClientRect();
    const bars = [document.querySelector('.controlbar'), document.querySelector('.topbar')].map(el => el.getBoundingClientRect());
    const covered = bars.some(b => b.right > ribbon.left && b.left < ribbon.right && b.bottom > ribbon.top);
    if (covered) {
        const below = Math.max(...bars.map(b => b.bottom)) + 6;
        stage.style.setProperty('--top-offset', `${Math.ceil((below - ribbon.top) / scale)}px`);
    }
}

/**
 * Shows a line (and an optional smaller second line) at the bottom of the arena.
 *
 * @param {string} text
 * @param {string} [small]
 */
function setHint(text, small, badges = []) {
    const hint = $('hint');
    hint.textContent = text;
    if (small) {
        const line = document.createElement('small');
        if (badges.length) {
            line.appendChild(document.createTextNode(`${drawT('entries.badges_only')} `));
            for (const badge of badges) {
                const chip = document.createElement('span');
                chip.className = 'hint-badge';
                if (badge.image) {
                    const icon = document.createElement('img');
                    icon.src = badge.image;
                    icon.alt = '';
                    chip.appendChild(icon);
                }
                chip.appendChild(document.createTextNode(badge.title || badge.id));
                line.appendChild(chip);
            }
            const roles = embedConfig ? embedConfig.badgeRoles : [];
            if (roles.length) {
                line.appendChild(document.createTextNode(` ${drawT('entries.badge_roles', { roles: roles.map(r => drawT(`role.${r}`)).join(', ') })}`));
            }
            line.appendChild(document.createTextNode(' · '));
        }
        line.appendChild(document.createTextNode(small));
        hint.appendChild(line);
    }
}

/** Turns the open / start / reopen / clear buttons (panel and screen) on or off for the current phase. */
function updateButtons() {
    const now = currentPhase();
    const busy = now === 'running' || now === 'showdown';
    const state = {
        open: now === 'idle' || now === 'result' || now === 'done',
        start: now === 'idle' || now === 'open',
        reopen: now === 'idle' || now === 'result' || now === 'done',
        clear: !busy
    };
    for (const [key, ids] of Object.entries({ open: ['open', 'bar-open'], start: ['run', 'bar-start'], reopen: ['reopen', 'bar-reopen'], clear: ['reset', 'bar-clear'] })) {
        for (const id of ids) {
            $(id).disabled = !state[key];
        }
    }
}

/** The bottom hint for the entry state (only while a chat is connected), the buttons and the phase report. */
function refreshHint() {
    updatePhase();
    updateButtons();
    if (!connectedChannel || playback) {
        setHint('');
        return;
    }
    if (!chatOpen) {
        setHint(drawT('entries.closed'), drawT('entries.closed_wait'));
        return;
    }
    const entry = entryInfo();
    const how = entry.picksSide ? drawT('entries.open_pick', { command: entry.raw }) : drawT('entries.open_phrase', { phrase: entry.raw });
    const only = { subs: 'entries.only_subs', subs_vips: 'entries.only_subs_vips' }[settings.entryFrom];
    setHint(drawT('entries.open'), only ? `${drawT(only)} · ${how}` : how, requiredBadges());
}

/** Forgets the current draw: rounds, kept and forfeited winners. */
function clearDraw() {
    rounds = [];
    kept = [];
    forfeited.clear();
    confirmed = false;
    pendingConfirm = null;
    clearTimeout(confirmTimer);
    startedAt = null;
    finishedAt = null;
}

/**
 * Opens a fresh entry phase.
 *
 * @param {Array} [restore] - Entrants to put back (from the last draw), on their sides and heroes.
 * @param {Array<{id: string, name: string}>} [kicks] - Kicked players who stay out.
 */
function openLobby(restore = [], kicks = []) {
    stopChat();
    if (playback) {
        playback.destroy();
        playback = null;
    }
    if (lobby) {
        lobby.destroy();
    }
    clearDraw();
    $('result-box').hidden = true;
    $('showdown-box').hidden = true;
    $('panel-toggle').classList.remove('has-result');
    $('panel-toggle').textContent = drawT('page.panel');
    $('skip').disabled = true;
    $('back').disabled = true;
    lobby = new DrawLobby(arenaHost, { hitsPerPlayer: readParams().hitsPerPlayer, sound, onChange: showCounts, bossName: bossNameInUse() });
    for (const k of kicks) {
        lobby.kicked.add(k.id);
        lobby.kickedNames.set(k.id, k.name);
    }
    for (const e of restore) {
        joinByRules({ login: e.id, name: e.name, color: e.color, side: e.kind, hero: e.hero, tier: e.tier, vip: e.vip,
            extra: e.extra, fromList: e.fromList, joined: e.joined, badges: e.badges, mod: e.mod }, { instant: true });
        const p = lobby.players.get(e.id);
        if (p) {
            p.picked = e.picked;
        }
    }
    setEntryEnabled(true);
    renderKicked();
    refreshHint();
}

/**
 * Turns the entry controls on or off (off while a battle is on screen).
 *
 * @param {boolean} on
 */
function setEntryEnabled(on) {
    for (const id of ['join', 'simulate', 'fake', 'add-list']) {
        $(id).disabled = !on;
    }
}

/** A made-up chatter name. */
function fakeName() {
    const a = ['Dark', 'Tiny', 'Mega', 'Lazy', 'Noob', 'Pixel', 'Crit', 'Salty', 'Turbo', 'Sleepy', 'Goblin', 'Lucky'];
    const b = ['Slayer', 'Potato', 'Knight', 'Wizard', 'Gamer', 'Bard', 'Raccoon', 'Paladin', 'Ninja', 'Muffin', 'Archer', 'Toast'];
    const name = a[Math.floor(Math.random() * a.length)] + b[Math.floor(Math.random() * b.length)];
    return Math.random() < 0.6 ? name + Math.floor(Math.random() * 1000) : name;
}

/** A made-up Twitch colour: often none (default colour), sometimes a dark one. */
function fakeColor() {
    const colors = ['#FF0000', '#1E90FF', '#9ACD32', '#FF69B4', '#8A2BE2', '#DAA520', '#00FF7F', '#0000FF', '#2E2E2E', '#5B1A8C', '#FFFFFF'];
    return Math.random() < 0.3 ? null : colors[Math.floor(Math.random() * colors.length)];
}

/** A made-up subscription: most chatters are not subscribed; some are VIPs. */
function fakeStatus() {
    const r = Math.random();
    return { tier: r < 0.65 ? 0 : r < 0.85 ? 1 : r < 0.95 ? 2 : 3, vip: Math.random() < 0.05 };
}

/** A made-up side pick (none when the entry command does not let chat pick). */
function fakeSide() {
    if (!entryInfo().picksSide) {
        return null;
    }
    const r = Math.random();
    return r < 0.4 ? null : r < 0.7 ? 'melee' : 'ranged';
}

/**
 * A side from free text: corpo/melee or distancia/ranged.
 *
 * @param {string} text
 * @returns {string|null}
 */
function readSide(text) {
    const words = normalize(text).split(' ');
    if (words.some(w => ['corpo', 'melee', 'cc'].includes(w))) {
        return 'melee';
    }
    if (words.some(w => ['distancia', 'ranged', 'dist', 'longe'].includes(w))) {
        return 'ranged';
    }
    return null;
}

/**
 * Reads the pasted list: "name [#color] [side]" per line.
 *
 * @returns {Array<{name: string, color: string|null, side: string|null}>}
 */
function readList() {
    const byName = new Map();
    for (const line of $('entrants').value.split('\n')) {
        const parts = line.trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            continue;
        }
        const key = parts[0].toLowerCase();
        if (byName.has(key)) {
            byName.get(key).extra++;
            continue;
        }
        const tier = parts.find(x => /^t[1-3]$/i.test(x));
        byName.set(key, {
            name: parts[0],
            color: parts.find(x => /^#[0-9a-f]{6}$/i.test(x)) || null,
            side: readSide(parts.slice(1).join(' ')),
            tier: tier ? Number(tier[1]) : 0,
            vip: parts.some(x => x.toLowerCase() === 'vip'),
            extra: 0,
            fromList: true
        });
    }
    return [...byName.values()];
}

/**
 * Adds entrants one after the other (walking in), or all at once when many.
 *
 * @param {Array} entries
 */
function addMany(entries) {
    if (!lobby) {
        return;
    }
    sound.unlock();
    entries.forEach((entry, i) => {
        if (entries.length > 40) {
            joinByRules(entry, { instant: true });
        }
        else {
            setTimeout(() => joinByRules(entry), i * 120);
        }
    });
}

/** Starts or stops a stream of fake chat: joins, and now and then someone switching sides. */
function toggleChat() {
    if (chatTimer) {
        stopChat();
        return;
    }
    sound.unlock();
    $('simulate').textContent = drawT('adv.simulate_stop');
    const tick = () => {
        if (!lobby) {
            stopChat();
            return;
        }
        const players = [...lobby.players.values()];
        if (entryInfo().picksSide && players.length > 3 && Math.random() < 0.25) {
            const p = players[Math.floor(Math.random() * players.length)];
            lobby.join({ name: p.name, side: p.kind === 'melee' ? 'ranged' : 'melee' });
        }
        else {
            joinByRules(Object.assign({ name: fakeName(), color: fakeColor(), side: fakeSide() }, fakeStatus()));
        }
        chatTimer = setTimeout(tick, 250 + Math.random() * 600);
    };
    tick();
}

/** Stops the fake chat. */
function stopChat() {
    clearTimeout(chatTimer);
    chatTimer = null;
    $('simulate').textContent = drawT('adv.simulate');
}

/** Entrants who can still win this draw: not kept, not forfeited, not excluded. */
function eligibleEntrants() {
    return lockedEntrants.filter(e => !kept.includes(e.id) && !forfeited.has(e.id) && !excluded.has(e.id));
}

/**
 * Says why a draw cannot start: on the arena while entries are open, in the panel otherwise.
 *
 * @param {number} n - Entrants needed.
 */
function tooFew(n) {
    const text = drawT('entries.min', { n });
    if (lobby) {
        lobby.arena.showBanner(text, 3000);
    }
    else {
        $('confirm-status').textContent = text;
    }
}

/**
 * Closes the entries (the first time) and runs and plays a battle: a new
 * draw, or a replacement round for the places forfeited in a showdown.
 *
 * @param {boolean} newSeed - Forget the seed field and pick a new one.
 * @param {boolean} [replacement] - Draw only the forfeited places, among the eligible entrants.
 */
function startDraw(newSeed, replacement = false) {
    if (confirmed) {
        return;
    }
    if (lobby) {
        lockedEntrants = lobby.entrants().filter(e => !excluded.has(e.id));
    }
    if (!replacement) {
        clearDraw();
    }
    const pool = eligibleEntrants();
    const wanted = wantedWinners() - kept.length;
    const winners = replacement ? Math.min(wanted, pool.length - 1) : wanted;
    const needed = Math.max(2, winners + 1);
    if (winners < 1 || pool.length < needed) {
        tooFew(Math.max(2, wanted + 1));
        return;
    }
    chatOpen = false;
    stopChat();
    if (lobby) {
        lastKicks = lobby.kickedList();
        lobby.destroy();
        lobby = null;
    }
    if (newSeed) {
        $('seed').value = '';
    }
    if (playback) {
        playback.destroy();
    }
    setEntryEnabled(false);
    $('back').disabled = false;
    $('result-box').hidden = true;
    $('confirm-status').textContent = '';
    startedAt = startedAt || new Date();
    lastResult = simulateDraw(pool, Object.assign(readParams(), { winners }), $('seed').value);
    $('seed').value = lastResult.seed;
    sound.unlock();
    playback = new DrawPlayback(arenaHost, lastResult, {
        speed,
        sound,
        onEnd: showResult,
        onShowdown: showShowdown,
        showdown: settings.showdown ? { seconds: settings.showdownSeconds } : null,
        bossName: bossNameInUse()
    });
    playback.setSpeed(speed);
    playback.play();
    $('skip').disabled = false;
    refreshHint();
}

/**
 * A person of the draw as the result lists them.
 *
 * @param {string} id - Login (lowercase).
 * @returns {{login: string, name: string}}
 */
function person(id) {
    const entry = lockedEntrants.find(e => e.id === id);
    return { login: id, name: entry ? entry.name : id };
}

/**
 * One round for the record: enough to replay it from its seed.
 *
 * @param {object} round
 * @returns {object}
 */
function roundRecord(round) {
    const r = round.result;
    return {
        seed: r.seed,
        params: r.params,
        players: r.players.map(p => {
            const entry = lockedEntrants.find(e => e.id === p.id) || {};
            return { login: p.id, name: p.name, side: p.kind, color: p.color, hero: p.hero, picked: p.picked,
                tier: entry.tier || 0, vip: !!entry.vip, bonus: p.bonus || 0, joined: p.joined };
        }),
        winners: round.kept.map(person),
        forfeited: round.forfeited.map(person),
        eliminated: r.eliminated.map(e => ({ login: e.id, t: e.t, cycle: e.cycle })),
        bosses: r.cycles,
        durationMs: r.durationMs
    };
}

/** The result (STREAMORG_INTEGRATION.md section 4): the first round on top, every round in "rounds". */
function resultPayload() {
    const first = roundRecord(rounds[0]);
    const out = {
        contract: DRAW_GAME.contract,
        game: DRAW_GAME.name,
        version: DRAW_GAME.version,
        channel: connectedChannel || settings.channel || null,
        prize: settings.prize || null,
        entry: entryInfo().raw,
        winners: kept.slice(0, wantedWinners()).map(person),
        forfeited: [...forfeited].map(person),
        entrants: rounds[0].result.players.length,
        seed: first.seed,
        startedAt: startedAt ? startedAt.toISOString() : null,
        finishedAt: finishedAt ? finishedAt.toISOString() : null,
        durationMs: rounds.reduce((sum, r) => sum + r.result.durationMs, 0),
        bosses: first.bosses,
        params: first.params,
        players: first.players,
        eliminated: first.eliminated,
        options: optionValues(),
        rounds: rounds.map(roundRecord)
    };
    if (embedConfig && embedConfig.giveaway) {
        out.giveaway = embedConfig.giveaway;
    }
    if (requiredBadges().length) {
        out.badges = requiredBadges().map(b => b.id);
        out.badgeRoles = embedConfig.badgeRoles.slice();
    }
    return out;
}

/** Shows the result section and flags the panel button. */
function showResult(result) {
    const roundKept = result.confirmed || result.winners;
    const roundLost = result.forfeited || [];
    rounds.push({ result, kept: roundKept.slice(), forfeited: roundLost.slice() });
    kept.push(...roundKept);
    for (const id of roundLost) {
        forfeited.add(id);
    }
    finishedAt = new Date();
    $('skip').disabled = true;
    $('showdown-box').hidden = true;
    const names = kept.map(id => person(id).name);
    $('winner-line').textContent = names.length ? `🏆 ${names.join(', ')}` : drawT('result.none');
    const lost = [...forfeited].map(id => person(id).name);
    $('lost-line').textContent = drawT('showdown.lost_panel', { names: lost.join(', ') });
    $('lost-line').hidden = !lost.length;
    const missing = wantedWinners() - kept.length;
    const canRedraw = missing > 0 && eligibleEntrants().length >= 2;
    $('redraw').hidden = !canRedraw;
    $('fewer-line').textContent = drawT('result.fewer', { n: kept.length, total: wantedWinners() });
    $('fewer-line').hidden = !(missing > 0 && !canRedraw && kept.length > 0);
    $('result-json').textContent = JSON.stringify(resultPayload(), null, 2);
    $('result-box').hidden = false;
    addStageConfirm();
    $('rerun').disabled = false;
    $('confirm-status').textContent = '';
    $('panel-toggle').classList.add('has-result');
    $('panel-toggle').textContent = drawT('page.panel_result');
    refreshHint();
}

/**
 * The showdown started: the panel gets a "present" button per winner
 * (for testing, or when the streamer hears them some other way).
 *
 * @param {string[]} ids - Winners' ids.
 */
function showShowdown(ids) {
    const holder = $('showdown-buttons');
    holder.textContent = '';
    for (const id of ids) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn small';
        button.dataset.id = id;
        button.textContent = drawT('showdown.present', { name: lastResult.players.find(p => p.id === id).name });
        button.addEventListener('click', () => {
            if (playback && playback.winnerSpoke(id)) {
                button.disabled = true;
            }
        });
        holder.appendChild(button);
    }
    $('showdown-box').hidden = false;
    $('skip').disabled = true;
    refreshHint();
}

/**
 * Inside StreamOrg, puts the confirm button on the winner screen, under the
 * winners' names, with a line for StreamOrg's answer. The public page has
 * no confirmation: the result is only shown.
 */
function addStageConfirm() {
    const panel = arenaHost.querySelector('.draw-result');
    if (!embedded || !kept.length || !panel) {
        return;
    }
    const box = document.createElement('div');
    box.className = 'stage-confirm';
    const button = document.createElement('button');
    button.id = 'stage-confirm-button';
    button.type = 'button';
    button.className = 'btn gold';
    button.textContent = drawT('result.confirm_send');
    button.addEventListener('click', confirmResult);
    const status = document.createElement('p');
    status.id = 'stage-confirm-status';
    box.append(button, status);
    const champions = panel.querySelector('.draw-champions');
    champions.after(box);
}

/**
 * Updates the confirm button and the answer line (on the winner screen and in the panel).
 *
 * @param {boolean} enabled - Whether the button can be pressed.
 * @param {string} [text] - Status text.
 */
function confirmUi(enabled, text) {
    const button = $('stage-confirm-button');
    if (button) {
        button.disabled = !enabled;
    }
    if (text !== undefined) {
        $('confirm-status').textContent = text;
        if ($('stage-confirm-status')) {
            $('stage-confirm-status').textContent = text;
        }
    }
}

/** Locks the result after StreamOrg saved it: no second confirmation, no redraw. */
function lockResult() {
    confirmed = true;
    confirmUi(false);
    for (const id of ['rerun', 'redraw', 'back']) {
        $(id).disabled = true;
    }
    refreshHint();
}

/** Sends the result to StreamOrg (embedded mode only) and waits for its answer. */
function confirmResult() {
    if (!embedded || !rounds.length || confirmed || pendingConfirm) {
        return;
    }
    const id = Array.from(crypto.getRandomValues(new Uint8Array(4)), b => b.toString(16).padStart(2, '0')).join('');
    pendingConfirm = id;
    confirmUi(false, drawT('result.sending'));
    post({ type: 'bossdraw:result', id, result: resultPayload() });
    clearTimeout(confirmTimer);
    confirmTimer = setTimeout(() => {
        if (pendingConfirm === id) {
            pendingConfirm = null;
            confirmUi(true, drawT('result.no_answer'));
        }
    }, DRAW_PAGE.savedTimeoutMs);
}

/**
 * StreamOrg's answer to a confirmation.
 *
 * @param {object} data - bossdraw:saved message.
 */
function onSaved(data) {
    if (!pendingConfirm || data.id !== pendingConfirm) {
        return;
    }
    pendingConfirm = null;
    clearTimeout(confirmTimer);
    const message = typeof data.message === 'string' ? data.message.slice(0, 300) : '';
    if (data.ok === true) {
        confirmUi(false, message);
        lockResult();
    }
    else {
        confirmUi(true, message);
    }
}

/**
 * Sets the battle speed (1×, 2× or 3×).
 *
 * @param {number} value
 */
function setSpeed(value) {
    speed = value;
    for (const b of document.querySelectorAll('.speeds .btn')) {
        b.setAttribute('aria-pressed', String(Number(b.dataset.speed) === value));
    }
    if (playback) {
        playback.setSpeed(value);
    }
}

/** Default sounds with the saved changes applied. */
function soundDefs() {
    const overrides = stored(DRAW_PAGE.soundKey, {});
    const defs = {};
    for (const [name, def] of Object.entries(DRAW_SOUND_DEFAULTS)) {
        const custom = Array.isArray(overrides[name]) ? overrides[name].map(v => (v === null ? undefined : v)) : null;
        defs[name] = Object.assign({}, def, custom ? { zzfx: custom } : {});
    }
    return defs;
}

/**
 * Turns the text of a ZzFX array (holes allowed, as the designer copies it) into numbers.
 *
 * @param {string} text - Like "zzfx(...[.5,,220,,.1])" or "[.5,,220]".
 * @returns {Array|null}
 */
function parseZzfx(text) {
    const m = text.match(/\[([^\]]*)\]/);
    if (!m) {
        return null;
    }
    const parts = m[1].split(',').map(p => p.trim());
    if (parts.some(p => p !== '' && !Number.isFinite(Number(p)))) {
        return null;
    }
    return parts.map(p => (p === '' ? undefined : Number(p)));
}

/**
 * Writes a ZzFX array the way the designer does (empty slots for defaults).
 *
 * @param {Array} params
 * @returns {string}
 */
function formatZzfx(params) {
    const out = [];
    for (let i = 0; i < params.length; i++) {
        out.push(params[i] === undefined || params[i] === null ? '' : String(params[i]));
    }
    return `[${out.join(',')}]`;
}

/** One row per sound, with a play button and its editable array. */
function buildSounds() {
    const box = $('sounds');
    box.textContent = '';
    for (const [name, def] of Object.entries(soundDefs())) {
        const row = document.createElement('div');
        row.className = 'sound-row';
        const label = document.createElement('code');
        label.textContent = name;
        const play = document.createElement('button');
        play.className = 'btn small';
        play.type = 'button';
        play.textContent = '▶';
        play.addEventListener('click', () => {
            sound.unlock();
            sound.play(name, { force: true });
        });
        const input = document.createElement('input');
        input.type = 'text';
        input.value = formatZzfx(def.zzfx);
        input.addEventListener('change', () => {
            const params = parseZzfx(input.value);
            input.classList.toggle('bad', !params);
            if (!params) {
                return;
            }
            const overrides = stored(DRAW_PAGE.soundKey, {});
            overrides[name] = params.map(v => (v === undefined ? null : v));
            store(DRAW_PAGE.soundKey, overrides);
            sound.setSounds(soundDefs());
            input.value = formatZzfx(params);
        });
        row.append(label, play, input);
        box.appendChild(row);
    }
}

const sound = new DrawSound(soundDefs());

/** Applies the saved volume and mute switch to the player and the panel. */
function loadAudio() {
    const audio = Object.assign({ volume: 0.8, music: 0.5, muted: false, effectsOff: false, musicOff: false }, stored(DRAW_PAGE.audioKey, {}));
    sound.setVolume(audio.volume);
    sound.setMusicVolume(audio.music);
    sound.setMuted(!!audio.muted);
    sound.setEffectsMuted(!!audio.effectsOff);
    sound.setMusicMuted(!!audio.musicOff);
    showAudio();
}

/** Puts the sound state into the panel and the quick controls on top. */
function showAudio() {
    $('mute').checked = sound.muted;
    $('volume').value = sound.volume;
    $('fx-quick').value = sound.volume;
    $('music-volume').value = sound.musicVolume;
    $('music-quick').value = sound.musicVolume;
    $('fx-toggle').setAttribute('aria-pressed', String(!sound.effectsMuted));
    $('music-toggle').setAttribute('aria-pressed', String(!sound.musicMuted));
}

/**
 * Applies and remembers the volumes and mute switches.
 *
 * @param {object} [change] - What changed: volume, music, muted, effectsOff, musicOff.
 */
function saveAudio(change = {}) {
    if ('volume' in change) {
        sound.setVolume(Number(change.volume));
    }
    if ('music' in change) {
        sound.setMusicVolume(Number(change.music));
    }
    if ('muted' in change) {
        sound.setMuted(!!change.muted);
    }
    if ('effectsOff' in change) {
        sound.setEffectsMuted(!!change.effectsOff);
    }
    if ('musicOff' in change) {
        sound.setMusicMuted(!!change.musicOff);
    }
    showAudio();
    store(DRAW_PAGE.audioKey, { volume: sound.volume, music: sound.musicVolume, muted: sound.muted,
        effectsOff: sound.effectsMuted, musicOff: sound.musicMuted });
}

/**
 * Connects to a channel's chat through ComfyJS (read-only, anonymous),
 * dropping the previous one.
 *
 * @param {string} channel
 */
function connectChat(channel) {
    const name = normalize(channel).replace(/^#/, '').replace(/[^a-z0-9_]/g, '').slice(0, 25);
    if (!window.ComfyJS || !name) {
        return;
    }
    if (connectedChannel) {
        try {
            ComfyJS.Disconnect();
        } catch (e) {}
    }
    connectedChannel = name;
    settings.channel = name;
    $('channel').value = name;
    $('boss-name').placeholder = name;
    showBossName();
    if (!embedded) {
        saveSettings();
    }
    $('chat-status').textContent = drawT('stream.connecting', { channel: name });
    $('chat-dot').classList.remove('on');
    ComfyJS.onConnected = () => {
        $('chat-status').textContent = drawT('stream.connected', { channel: name });
        $('chat-dot').classList.add('on');
    };
    ComfyJS.onCommand = (user, command, message, flags, extra) => onChat(user, `!${command}${message ? ` ${message}` : ''}`, flags, extra, command, message);
    ComfyJS.onChat = (user, message, flags, self, extra) => onChat(user, message, flags, extra, null, null);
    ComfyJS.Init(name);
    refreshHint();
    checkSound();
}

/**
 * Runs a streamer action (from the broadcaster's chat or StreamOrg's
 * buttons), ignoring one that does not fit the current phase.
 *
 * @param {string} action - open, start or reset.
 */
function runAction(action) {
    const now = currentPhase();
    if (action === 'open' && (now === 'idle' || now === 'result' || now === 'done')) {
        openEntries();
    }
    else if (action === 'start' && (now === 'open' || now === 'idle')) {
        startDraw(true);
    }
    else if (action === 'reopen' && (now === 'idle' || now === 'result' || now === 'done')) {
        reopenEntries();
    }
    else if (action === 'reset' && now !== 'running' && now !== 'showdown') {
        resetEntries();
    }
}

/**
 * Handles one chat message: a winner speaking in the showdown, broadcaster
 * commands, then the entry command. With a "!word" command, "!word
 * melee/ranged" picks a side; with any other entry phrase the message must
 * be that phrase (or start with it) and the side is drawn, keeping the
 * sides even.
 *
 * @param {string} user - Display name.
 * @param {string} text - Whole message.
 * @param {object} flags - ComfyJS flags.
 * @param {object} extra - ComfyJS extra (username, displayName, userColor…).
 * @param {string|null} command - Command name when the message starts with "!".
 * @param {string|null} args - Text after the command.
 */
function onChat(user, text, flags, extra, command, args) {
    const login = String((extra && extra.username) || user || '').trim().toLowerCase();
    if (playback && playback.inShowdown) {
        for (const id of new Set([login, extra && extra.displayName, user].filter(Boolean).map(n => String(n).trim().toLowerCase()))) {
            if (playback.winnerSpoke(id)) {
                const button = [...$('showdown-buttons').children].find(b => b.dataset.id === id);
                if (button) {
                    button.disabled = true;
                }
            }
        }
        return;
    }
    if (command && flags.broadcaster) {
        const action = Object.keys(CHAT_COMMANDS).find(k => CHAT_COMMANDS[k].includes(String(command).toLowerCase()));
        if (action) {
            runAction(action);
            return;
        }
    }
    if (!lobby || !chatOpen || excluded.has(login)) {
        return;
    }
    const entry = entryInfo();
    const name = (extra && extra.displayName) || user;
    const color = extra && extra.userColor;
    const status = chatStatus(flags, extra);
    if (entry.picksSide) {
        if (command && entry.commands.includes(normalize(command))) {
            joinByRules(Object.assign({ login, name, color, side: readSide(args) }, status));
        }
    }
    else {
        const said = normalize(text);
        if (said === entry.norm || said.startsWith(`${entry.norm} `)) {
            joinByRules(Object.assign({ login, name, color, side: null }, status));
        }
    }
}

/** Opens the entries to chat: on the players already there, or on a fresh field after a battle. */
function openEntries() {
    if (playback && !playback.finished) {
        return;
    }
    if (playback || !lobby) {
        openLobby();
    }
    chatOpen = true;
    refreshHint();
}

/** Opens the entries again keeping the players (after a battle: the ones who fought) and the kicks. */
function reopenEntries() {
    if (playback && !playback.finished) {
        return;
    }
    if (playback) {
        openLobby(lockedEntrants, lastKicks);
    }
    else if (!lobby) {
        openLobby();
    }
    chatOpen = true;
    refreshHint();
}

/** Clears everything (players and kicks) and closes the entries. */
function resetEntries() {
    openLobby();
    chatOpen = false;
    refreshHint();
}

/** Asks for a click when the browser keeps the sound blocked (OBS does not). */
function checkSound() {
    sound.unlock();
    setTimeout(() => {
        if (!sound.audioRunning()) {
            $('gate').hidden = false;
        }
    }, 300);
}

/** Where the draw is: idle, open, running, showdown, result or done. */
function currentPhase() {
    if (confirmed) {
        return 'done';
    }
    if (playback) {
        if (playback.inShowdown) {
            return 'showdown';
        }
        return playback.finished ? 'result' : 'running';
    }
    return chatOpen ? 'open' : 'idle';
}

/** Tells StreamOrg when the phase changes. */
function updatePhase() {
    const now = currentPhase();
    if (now !== phase) {
        phase = now;
        if (embedded) {
            post({ type: 'bossdraw:state', phase: now });
        }
    }
}

/**
 * Posts a message to the StreamOrg page around the game.
 *
 * @param {object} message - With its type; the contract is added.
 */
function post(message) {
    if (framed) {
        window.parent.postMessage(Object.assign({}, message, { contract: DRAW_GAME.contract }), '*');
    }
}

/** Hands the current settings to StreamOrg, at most once every two seconds. */
function queueSettings() {
    if (!embedded || settingsTimer) {
        return;
    }
    settingsTimer = setTimeout(() => {
        settingsTimer = null;
        const payload = {
            params: Object.assign({}, memory[DRAW_PAGE.storageKey] || {}),
            sounds: memory[DRAW_PAGE.soundKey] || {},
            audio: memory[DRAW_PAGE.audioKey] || {}
        };
        delete payload.params.winners;
        delete payload.params.hitsPerPlayer;
        delete payload.params.extraRegen;
        if (JSON.stringify(payload).length <= DRAW_PAGE.settingsMaxBytes) {
            post({ type: 'bossdraw:settings', settings: payload });
        }
    }, DRAW_PAGE.settingsDebounceMs);
}

/**
 * The saved settings StreamOrg sends back, checked value by value (they may
 * come from an older version); unknown or bad values are dropped.
 *
 * @param {*} raw - The config's settings (untrusted).
 * @returns {object} Storage key → value.
 */
function cleanSettings(raw) {
    const out = {};
    if (!raw || typeof raw !== 'object' || Array.isArray(raw) || JSON.stringify(raw).length > DRAW_PAGE.settingsMaxBytes) {
        return out;
    }
    const isObject = v => v && typeof v === 'object' && !Array.isArray(v);
    if (isObject(raw.params)) {
        const params = {};
        for (const [key, def] of Object.entries(DRAW_DEFAULTS)) {
            const value = raw.params[key];
            const choices = PARAM_INFO[key] && PARAM_INFO[key][1];
            if (key === 'winners' || key === 'hitsPerPlayer') {
                continue;
            }
            if (typeof def === 'number' && typeof value === 'number' && Number.isFinite(value)) {
                params[key] = value;
            }
            else if (choices && choices.includes(value)) {
                params[key] = value;
            }
        }
        out[DRAW_PAGE.storageKey] = params;
    }
    if (isObject(raw.sounds)) {
        const sounds = {};
        for (const [name, list] of Object.entries(raw.sounds)) {
            if (name in DRAW_SOUND_DEFAULTS && Array.isArray(list) && list.length <= 24
                && list.every(v => v === null || (typeof v === 'number' && Number.isFinite(v)))) {
                sounds[name] = list;
            }
        }
        out[DRAW_PAGE.soundKey] = sounds;
    }
    if (isObject(raw.audio)) {
        const audio = {};
        for (const key of ['volume', 'music']) {
            if (typeof raw.audio[key] === 'number' && raw.audio[key] >= 0 && raw.audio[key] <= 1) {
                audio[key] = raw.audio[key];
            }
        }
        for (const key of ['muted', 'effectsOff', 'musicOff']) {
            if (typeof raw.audio[key] === 'boolean') {
                audio[key] = raw.audio[key];
            }
        }
        out[DRAW_PAGE.audioKey] = audio;
    }
    return out;
}

/**
 * Checks a bossdraw:config (section 3.1).
 *
 * @param {*} c - The config (untrusted).
 * @returns {{ok: boolean, detail?: string, config?: object}}
 */
function checkConfig(c) {
    const isText = (v, max) => typeof v === 'string' && v.length <= max;
    if (!c || typeof c !== 'object' || Array.isArray(c)) {
        return { ok: false, detail: 'config' };
    }
    if (!isText(c.channel, 25) || !/^[a-z0-9_]{1,25}$/.test(c.channel)) {
        return { ok: false, detail: 'channel' };
    }
    if (!isText(c.entry, 25) || !/^!\S/.test(c.entry) || c.entry.trim() !== c.entry) {
        return { ok: false, detail: 'entry' };
    }
    if (!Number.isInteger(c.winners) || c.winners < 1 || c.winners > DRAW_PAGE.maxWinners) {
        return { ok: false, detail: 'winners' };
    }
    if (c.maxWinners !== undefined && !(Number.isInteger(c.maxWinners) && c.maxWinners >= c.winners && c.maxWinners <= DRAW_PAGE.maxWinners)) {
        return { ok: false, detail: 'maxWinners' };
    }
    if (c.lang !== undefined && !drawIsLanguageTag(c.lang)) {
        return { ok: false, detail: 'lang' };
    }
    if (c.prize !== undefined && c.prize !== null && !isText(c.prize, 120)) {
        return { ok: false, detail: 'prize' };
    }
    if (c.giveaway !== undefined && c.giveaway !== null && !isText(c.giveaway, 120)) {
        return { ok: false, detail: 'giveaway' };
    }
    if (c.exclude !== undefined && !(Array.isArray(c.exclude) && c.exclude.length <= 500
        && c.exclude.every(v => typeof v === 'string' && /^[a-z0-9_]{1,25}$/i.test(v)))) {
        return { ok: false, detail: 'exclude' };
    }
    if (c.lock !== undefined && !(Array.isArray(c.lock) && c.lock.every(v => typeof v === 'string'))) {
        return { ok: false, detail: 'lock' };
    }
    if (c.badges !== undefined && !(Array.isArray(c.badges) && c.badges.length <= 20 && c.badges.every(b => b && typeof b === 'object'
        && typeof b.id === 'string' && /^[a-z0-9_-]{1,64}(\/[a-z0-9_-]{1,32})?$/i.test(b.id)
        && (b.title === undefined || (typeof b.title === 'string' && b.title.length <= 60))
        && (b.image === undefined || (typeof b.image === 'string' && b.image.length <= 30000 && /^(data:image\/(png|gif|webp);base64,|\/|https:\/\/)/.test(b.image)))))) {
        return { ok: false, detail: 'badges' };
    }
    if (c.badgeRoles !== undefined && !(Array.isArray(c.badgeRoles) && c.badgeRoles.length <= 3
        && c.badgeRoles.every(r => ['subscriber', 'vip', 'moderator'].includes(r)))) {
        return { ok: false, detail: 'badgeRoles' };
    }
    if (c.options !== undefined && !(c.options && typeof c.options === 'object' && !Array.isArray(c.options))) {
        return { ok: false, detail: 'options' };
    }
    if (c.texts !== undefined && !(c.texts && typeof c.texts === 'object' && !Array.isArray(c.texts)
        && JSON.stringify(c.texts).length <= DRAW_PAGE.textsMaxBytes)) {
        return { ok: false, detail: 'texts' };
    }
    return {
        ok: true,
        config: {
            lang: c.lang || 'en',
            channel: c.channel,
            entry: c.entry,
            prize: c.prize || '',
            winners: c.winners,
            maxWinners: c.maxWinners || c.winners,
            exclude: (c.exclude || []).map(v => v.toLowerCase()),
            lock: (c.lock || []).filter(v => DRAW_PAGE.lockable.includes(v)
                || DRAW_GAME.options.some(o => v === `options.${o.key}`)),
            options: c.options || {},
            badges: (c.badges || []).map((b) => {
                const [name, level] = b.id.toLowerCase().split('/');
                return { id: b.id.toLowerCase(), name, level: level || null, title: b.title || '', image: b.image || '' };
            }),
            badgeRoles: c.badges && c.badges.length ? [...new Set(c.badgeRoles || [])] : [],
            giveaway: c.giveaway || '',
            texts: c.texts ? drawCleanTexts(c.texts) : null,
            settings: cleanSettings(c.settings)
        }
    };
}

/**
 * A config from StreamOrg: switches to embedded mode with its channel,
 * command, prize, winners, language, texts and saved settings.
 *
 * @param {*} raw - The config (untrusted).
 */
function applyConfig(raw) {
    const check = checkConfig(raw);
    if (!check.ok) {
        post({ type: 'bossdraw:error', error: 'config', detail: check.detail });
        return;
    }
    const now = currentPhase();
    if (now === 'running' || now === 'showdown') {
        post({ type: 'bossdraw:error', error: 'config', detail: 'busy' });
        return;
    }
    const c = check.config;
    const before = embedConfig;
    const kept = before ? {
        entry: settings.entry,
        prize: settings.prize,
        winners: Number($('main-winners').value),
        options: optionValues()
    } : null;
    /** A refreshed config keeps the streamer's own change of a field StreamOrg neither changed nor locks. */
    const keep = (lockKey, oldValue, newValue) => kept && !c.lock.includes(lockKey) && JSON.stringify(oldValue) === JSON.stringify(newValue);
    clearInterval(handshakeTimer);
    embedded = true;
    embedConfig = c;
    memory = c.settings;
    excluded = new Set(c.exclude);
    locks = new Set(c.lock);
    Object.assign(settings, {
        channel: c.channel,
        entry: keep('entry', before && before.entry, c.entry) ? kept.entry : c.entry,
        prize: keep('prize', before && before.prize, c.prize) ? kept.prize : c.prize
    });
    textsFromConfig = c.texts;
    setLanguage(c.lang);
    loadAudio();
    sound.setSounds(soundDefs());
    buildSounds();
    buildParams(stored(DRAW_PAGE.storageKey, {}));
    $('main-winners').value = keep('winners', before && before.winners, c.winners) ? Math.min(c.maxWinners, kept.winners) : c.winners;
    const options = Object.assign({}, c.options);
    for (const option of DRAW_GAME.options) {
        if (keep(`options.${option.key}`, before && before.options[option.key], c.options[option.key])) {
            options[option.key] = kept.options[option.key];
        }
    }
    applyOptions(options);
    fillFields();
    $('language-field').hidden = true;
    $('channel-label').hidden = true;
    $('channel-row').hidden = true;
    $('giveaway').textContent = c.giveaway ? drawT('page.giveaway', { name: c.giveaway }) : '';
    $('giveaway').hidden = !c.giveaway;
    $('main-winners').max = String(c.maxWinners);
    const lockable = { channel: ['channel', 'connect'], entry: ['entry-command'], prize: ['prize-text'], winners: ['main-winners'] };
    for (const [key, id] of Object.entries(OPTION_FIELDS)) {
        lockable[`options.${key}`] = [id];
    }
    for (const [key, ids] of Object.entries(lockable)) {
        for (const id of ids) {
            $(id).disabled = locks.has(key);
            $(id).title = locks.has(key) ? drawT('page.locked') : '';
        }
    }
    fillRules();
    chatOpen = false;
    openLobby();
    phase = null;
    post({ type: 'bossdraw:configured' });
    refreshHint();
    connectChat(c.channel);
}

/**
 * Messages from the StreamOrg page around the game (embedded mode).
 *
 * @param {MessageEvent} event
 */
function onMessage(event) {
    if (!framed || event.source !== window.parent) {
        return;
    }
    const data = event.data;
    if (!data || typeof data !== 'object' || typeof data.type !== 'string' || !data.type.startsWith('bossdraw:')
        || data.contract !== DRAW_GAME.contract) {
        return;
    }
    if (data.type === 'bossdraw:config') {
        applyConfig(data.config);
    }
    else if (embedded && data.type === 'bossdraw:saved') {
        onSaved(data);
    }
    else if (embedded && data.type === 'bossdraw:command' && ['open', 'start', 'reset'].includes(data.action)) {
        runAction(data.action);
    }
}

/** Says "ready" to the page around the game until it answers with a config (for ten seconds). */
function startHandshake() {
    if (!framed) {
        return;
    }
    let tries = 0;
    const hello = () => {
        if (embedded || tries++ >= DRAW_PAGE.handshakeTries) {
            clearInterval(handshakeTimer);
            return;
        }
        post({ type: 'bossdraw:ready', version: DRAW_GAME.version });
    };
    hello();
    handshakeTimer = setInterval(hello, DRAW_PAGE.handshakeMs);
}

/** Puts the settings into the panel fields. */
function fillFields() {
    applyOptions(optionValues());
    $('channel').value = settings.channel;
    $('prize-text').value = settings.prize;
    $('entry-command').value = settings.entry === DRAW_PAGE.defaultEntry ? '' : settings.entry;
    $('showdown-on').checked = !!settings.showdown;
    $('showdown-seconds').value = settings.showdownSeconds;
    showPrize();
}

/** Re-draws every text after a language or text change. */
function applyTexts() {
    drawTranslatePage();
    document.title = drawT('page.title');
    placePrize();
    if ($('panel-toggle').classList.contains('has-result')) {
        $('panel-toggle').textContent = drawT('page.panel_result');
    }
    buildParams(readParams());
    fillRules();
    updateFullscreenButton();
    rebuildLobby();
    if (connectedChannel) {
        $('chat-status').textContent = drawT($('chat-dot').classList.contains('on') ? 'stream.connected' : 'stream.connecting', { channel: connectedChannel });
    }
    if (embedConfig && embedConfig.giveaway) {
        $('giveaway').textContent = drawT('page.giveaway', { name: embedConfig.giveaway });
    }
    refreshHint();
}

/**
 * Switches the language: its defaults at once, then the published overrides
 * when they arrive (the config's texts, while embedded, win over them).
 *
 * @param {string} lang
 */
function setLanguage(lang) {
    const fromConfig = textsFromConfig || {};
    drawSetLanguage(lang, fromConfig);
    if ([...$('language').options].some(o => o.value === drawTexts.lang)) {
        $('language').value = drawTexts.lang;
    }
    applyTexts();
    const asked = drawTexts.lang;
    drawFetchOverrides(asked).then((published) => {
        if (drawTexts.lang === asked && Object.keys(published).length) {
            drawSetLanguage(asked, Object.assign({}, published, fromConfig));
            applyTexts();
        }
    });
}

/** Fills the language picker (public mode): shipped languages and those StreamOrg publishes texts for. */
async function buildLanguages() {
    const select = $('language');
    const languages = await drawFetchLanguages();
    if (embedded) {
        return;
    }
    select.textContent = '';
    for (const code of languages) {
        let label = code;
        try {
            label = new Intl.DisplayNames([code], { type: 'language' }).of(code) || code;
        } catch (e) {}
        select.appendChild(new Option(label, code));
    }
    if (!languages.includes(drawTexts.lang)) {
        select.appendChild(new Option(drawTexts.lang, drawTexts.lang));
    }
    select.value = drawTexts.lang;
}

/** Scales the 1280×720 stage to fit the window (bars around it when the shape differs). */
function fitStage() {
    const width = window.innerWidth;
    const height = window.innerHeight;
    const scale = Math.min(width / DRAW_PAGE.stageWidth, height / DRAW_PAGE.stageHeight);
    $('stage').style.setProperty('--stage-scale', String(scale));
    $('too-small').hidden = width >= DRAW_PAGE.minWidth && height >= DRAW_PAGE.minHeight;
    placePrize();
}

/** Whether this page can go fullscreen. */
function canFullscreen() {
    return !!(document.fullscreenEnabled && document.documentElement.requestFullscreen);
}

/** The fullscreen button: shown when possible, labelled enter or leave. */
function updateFullscreenButton() {
    const button = $('fullscreen');
    button.hidden = !canFullscreen();
    button.title = drawT(document.fullscreenElement ? 'page.fullscreen_exit' : 'page.fullscreen');
}

/** Enters or leaves fullscreen; the draw keeps running either way. */
function toggleFullscreen() {
    if (document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
        return;
    }
    if (!canFullscreen()) {
        return;
    }
    document.documentElement.requestFullscreen().catch(() => {
        $('fullscreen').title = drawT('page.fullscreen_failed');
    });
}

const publicLang = query.get('lang') || settings.lang || (navigator.languages && navigator.languages[0]) || navigator.language || 'en';
textsFromConfig = null;
loadAudio();
setLanguage(publicLang);
fillFields();
fitStage();
window.addEventListener('resize', fitStage);
if (document.fonts) {
    document.fonts.ready.then(placePrize);
}
window.addEventListener('message', onMessage);
document.addEventListener('fullscreenchange', updateFullscreenButton);
document.addEventListener('keydown', (e) => {
    const typing = e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName);
    if (!typing && !e.ctrlKey && !e.metaKey && !e.altKey && (e.key === 'f' || e.key === 'F')) {
        e.preventDefault();
        toggleFullscreen();
    }
});

$('fullscreen').addEventListener('click', toggleFullscreen);
$('language').addEventListener('change', () => {
    settings.lang = $('language').value;
    saveSettings();
    setLanguage(settings.lang);
});
$('gate').addEventListener('click', () => {
    sound.unlock();
    $('gate').hidden = true;
});
document.addEventListener('pointerdown', () => {
    if ($('gate').hidden) {
        return;
    }
    sound.unlock();
    setTimeout(() => {
        if (sound.audioRunning()) {
            $('gate').hidden = true;
        }
    }, 200);
});
$('panel-toggle').addEventListener('click', () => {
    $('panel').hidden = !$('panel').hidden;
    sound.unlock();
});
for (const tab of document.querySelectorAll('.tab')) {
    tab.addEventListener('click', () => {
        for (const t of document.querySelectorAll('.tab')) {
            t.setAttribute('aria-selected', String(t === tab));
        }
        for (const body of document.querySelectorAll('.tab-body')) {
            body.hidden = body.dataset.body !== tab.dataset.tab;
        }
    });
}
for (const b of document.querySelectorAll('.speeds .btn')) {
    b.addEventListener('click', () => setSpeed(Number(b.dataset.speed)));
}
$('connect').addEventListener('click', () => connectChat($('channel').value));
$('channel').addEventListener('keydown', (e) => e.key === 'Enter' && connectChat($('channel').value));
$('prize-text').addEventListener('input', () => {
    settings.prize = $('prize-text').value.trim().slice(0, 120);
    if (!embedded) {
        saveSettings();
    }
    showPrize();
});
$('entry-command').addEventListener('input', () => {
    settings.entry = $('entry-command').value.trim() || DRAW_PAGE.defaultEntry;
    if (!embedded) {
        saveSettings();
    }
    refreshHint();
});
$('main-winners').addEventListener('change', saveParams);
$('main-hits').addEventListener('change', () => {
    saveParams();
    rebuildLobby();
});
for (const [action, ids] of Object.entries({ open: ['open', 'bar-open'], start: ['run', 'bar-start'], reopen: ['reopen', 'bar-reopen'], reset: ['reset', 'bar-clear'] })) {
    for (const id of ids) {
        $(id).addEventListener('click', () => {
            sound.unlock();
            runAction(action);
        });
    }
}
$('unkick-all').addEventListener('click', () => {
    if (lobby) {
        lobby.unkick();
        renderKicked();
    }
});
$('rule-extra-regen').addEventListener('change', () => {
    settings.extraRegen = $('rule-extra-regen').checked;
    rulesChanged();
});
$('rule-list-dups').addEventListener('change', () => {
    settings.listDuplicates = $('rule-list-dups').value;
    rulesChanged();
});
for (const [key, id] of [['listMinHearts', 'rule-list-min'], ['listMaxHearts', 'rule-list-max']]) {
    $(id).addEventListener('change', () => {
        settings[key] = Math.min(20, Math.max(1, Math.round(Number($(id).value) || 1)));
        if (settings.listMinHearts > settings.listMaxHearts) {
            settings[key === 'listMinHearts' ? 'listMaxHearts' : 'listMinHearts'] = settings[key];
        }
        rulesChanged();
    });
}
$('boss-custom').addEventListener('change', () => {
    settings.bossCustom = $('boss-custom').checked;
    fillRules();
    saveSettings();
    showBossName();
});
$('boss-name').addEventListener('input', () => {
    settings.bossName = $('boss-name').value.slice(0, 40);
    saveSettings();
    showBossName();
});
$('skip').addEventListener('click', () => playback && playback.skip());
$('back').addEventListener('click', () => {
    openLobby(lockedEntrants);
    chatOpen = false;
    refreshHint();
});
$('rerun').addEventListener('click', () => startDraw(true));
$('redraw').addEventListener('click', () => startDraw(true, true));
$('showdown-on').addEventListener('change', () => {
    settings.showdown = $('showdown-on').checked;
    saveSettings();
});
$('showdown-seconds').addEventListener('change', () => {
    settings.showdownSeconds = Math.min(300, Math.max(10, Math.round(Number($('showdown-seconds').value) || 60)));
    $('showdown-seconds').value = settings.showdownSeconds;
    saveSettings();
});
$('rule-entry-from').addEventListener('change', () => {
    settings.entryFrom = $('rule-entry-from').value;
    rulesChanged();
});
$('rule-sub-bonus').addEventListener('change', () => {
    settings.subBonus = $('rule-sub-bonus').value;
    rulesChanged();
});
$('rule-sub-hearts').addEventListener('change', () => {
    settings.subBonusHearts = Math.min(3, Math.max(1, Math.round(Number($('rule-sub-hearts').value) || 1)));
    rulesChanged();
});
$('reset-params').addEventListener('click', () => {
    store(DRAW_PAGE.storageKey, null);
    buildParams({});
});
$('join').addEventListener('click', () => {
    const name = $('join-name').value.trim();
    if (lobby && name) {
        sound.unlock();
        const status = $('join-tier').value;
        joinByRules({ name, color: $('join-color').value, side: $('join-side').value || null,
            tier: status === 'vip' || status === 'mod' ? 0 : Number(status), vip: status === 'vip', mod: status === 'mod' });
    }
});
$('join-name').addEventListener('keydown', (e) => e.key === 'Enter' && $('join').click());
$('fake').addEventListener('click', () => {
    const n = Math.max(1, Number($('fake-count').value) || 20);
    addMany(Array.from({ length: n }, () => Object.assign({ name: fakeName(), color: fakeColor(), side: fakeSide() }, fakeStatus())));
});
$('simulate').addEventListener('click', toggleChat);
$('add-list').addEventListener('click', () => addMany(readList()));
$('mute').addEventListener('change', () => saveAudio({ muted: $('mute').checked }));
$('volume').addEventListener('input', () => saveAudio({ volume: $('volume').value }));
$('music-volume').addEventListener('input', () => saveAudio({ music: $('music-volume').value }));
$('fx-quick').addEventListener('input', () => saveAudio({ volume: $('fx-quick').value }));
$('music-quick').addEventListener('input', () => saveAudio({ music: $('music-quick').value }));
$('fx-toggle').addEventListener('click', () => {
    sound.unlock();
    saveAudio({ effectsOff: !sound.effectsMuted });
});
$('music-toggle').addEventListener('click', () => {
    sound.unlock();
    saveAudio({ musicOff: !sound.musicMuted });
});
$('volume').addEventListener('change', () => {
    sound.unlock();
    sound.play('coin', { force: true });
});
$('music-test').addEventListener('click', () => {
    sound.unlock();
    if (sound.musicPlaying) {
        sound.stopMusic();
        $('music-test').textContent = drawT('adv.music_play');
    }
    else {
        sound.playMusic();
        $('music-test').textContent = drawT('adv.music_stop');
    }
});
$('reset-sounds').addEventListener('click', () => {
    store(DRAW_PAGE.soundKey, null);
    sound.setSounds(soundDefs());
    buildSounds();
});

buildSounds();
buildParams(stored(DRAW_PAGE.storageKey, {}));
buildLanguages();
setTimeout(() => sound.prepareMusic(), 1500);
openLobby();
startHandshake();
if (settings.channel && !framed) {
    connectChat(settings.channel);
}

/** Frames per second ("Avançado" → tests), to check how the page copes with many entrants. */
(function fpsMeter() {
    let frames = 0;
    let since = performance.now();
    const frame = (now) => {
        frames++;
        if (now - since >= 1000) {
            $('fps').textContent = `${Math.round(frames * 1000 / (now - since))} fps`;
            frames = 0;
            since = now;
        }
        requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
})();
