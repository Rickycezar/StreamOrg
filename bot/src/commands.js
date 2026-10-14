/**
 * The commands the bot answers. Each command is a built-in behaviour
 * (CODES; the simple ones fill their reply from BUILTINS, the others are
 * in builtins.js) with a trigger, a reply template and further messages
 * for other outcomes. The defaults exist per language: every channel uses
 * the defaults in its streamer's language (or English) unless its
 * streamer made their own version, which then replaces it in their
 * channel only. Streamers also write custom commands (code "custom"): a
 * trigger and a reply; a custom command keeps its trigger when a new
 * built-in comes with the same one.
 */

/** Every built-in behaviour the bot knows. */
export const CODES = ['heartbeat', 'giveaway', 'join', 'prizes', 'today', 'week', 'watchtime'];

/** The language whose defaults a channel falls back to. */
export const FALLBACK_LOCALE = 'en';

/** Twitch refuses chat messages longer than this. */
export const MESSAGE_MAX = 500;

/** What each built-in fills its reply's {placeholders} with. */
export const BUILTINS = {
    heartbeat: (ctx) => ({
        user: ctx.user.displayName,
        channel: ctx.channel,
        uptime: formatUptime(ctx.uptimeMs),
    }),
    custom: (ctx) => ({
        user: ctx.user.displayName,
        channel: ctx.channel,
        target: targetOf(ctx.text, ctx.user.displayName),
    }),
};

/** "!so @Hoku_xx great stream" -> "Hoku_xx"; no word after the command -> whoever typed it. */
export function targetOf(text, fallback) {
    const word = String(text || '').trim().split(/\s+/)[1] || '';
    const clean = word.replace(/^@+/, '').replace(/[^\p{L}\p{N}_]/gu, '').slice(0, 25);

    return clean || fallback;
}

const RANKS = { everyone: 0, subscriber: 1, vip: 2, moderator: 3, broadcaster: 4 };

/** How far a chatter's badges reach: broadcaster > moderator > vip > subscriber > everyone. */
export function rankOf(badges) {
    if ('broadcaster' in badges) return RANKS.broadcaster;
    if ('moderator' in badges) return RANKS.moderator;
    if ('vip' in badges) return RANKS.vip;
    if ('subscriber' in badges || 'founder' in badges) return RANKS.subscriber;
    return RANKS.everyone;
}

export function allowed(permission, badges) {
    return rankOf(badges) >= (RANKS[permission] ?? RANKS.broadcaster);
}

/** Fills {name} placeholders it knows; unknown ones stay as typed. */
export function render(template, values) {
    return String(template)
        .replace(/\{([a-z_]+)\}/g, (whole, name) => (name in values ? String(values[name]) : whole))
        .replace(/\s{2,}/g, ' ')
        .trim();
}

/**
 * A reply whose {list} may be too long for one chat message: the first
 * message is the template with as many items as fit, the next ones carry
 * the rest ("… a · b"), up to maxParts messages; what still does not fit
 * is left out with "…".
 *
 * @param {string} template with {list}
 * @param {object} values the other placeholders
 * @param {string[]} items
 * @returns {string[]}
 */
export function splitList(template, values, items, { max = MESSAGE_MAX - 10, maxParts = 4, glue = ' · ' } = {}) {
    const marker = '\u0000LIST\u0000';
    const head = render(template, { ...values, list: marker });
    const [before, after = ''] = head.split(marker);
    const parts = [];
    let current = [];
    let room = max - before.length - after.length;

    const close = () => {
        const text = current.join(glue);
        parts.push(parts.length === 0 ? before + text + after : '… ' + text);
        current = [];
        room = max - 2;
    };

    for (const raw of items) {
        const item = raw.length > max - 20 ? raw.slice(0, max - 21) + '…' : raw;
        const need = (current.length ? glue.length : 0) + item.length;

        if (need > room && current.length) {
            if (parts.length + 1 >= maxParts) {
                current.push('…');
                break;
            }
            close();
        }

        current.push(item);
        room -= (current.length > 1 ? glue.length : 0) + item.length;
    }

    if (current.length || parts.length === 0) close();

    return parts;
}

/** 3_725_000 -> "1h 2m" */
export function formatUptime(ms) {
    const minutes = Math.floor(ms / 60_000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const mins = minutes % 60;

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${mins}m`;
    return `${mins}m`;
}

/** Who answers to what in each channel, rebuilt whenever commands change. */
export class CommandBook {
    constructor() {
        this.byUser = new Map();
        this.defaults = new Map();
    }

    /**
     * @param {Array<{userId: ?number, code: string, trigger: string, response: string, enabled: boolean, permission: string, cooldown: number}>} rows
     * @param {Array<object>} custom the streamers' own commands, same shape with code "custom"
     */
    load(rows, custom = []) {
        this.defaults = new Map();
        const personal = new Map();

        for (const row of rows) {
            if (!CODES.includes(row.code)) continue;

            if (row.userId === null) {
                const locale = row.locale || FALLBACK_LOCALE;
                if (!this.defaults.has(locale)) this.defaults.set(locale, new Map());
                this.defaults.get(locale).set(row.code, row);
            } else {
                if (!personal.has(row.userId)) personal.set(row.userId, new Map());
                personal.get(row.userId).set(row.code, row);
            }
        }

        this.personal = personal;
        this.custom = new Map();

        for (const command of custom) {
            if (!this.custom.has(command.userId)) this.custom.set(command.userId, []);
            this.custom.get(command.userId).push(command);
        }

        this.byUser = new Map();
    }

    /** The default of each built-in in a language, English filling the gaps. */
    defaultsFor(locale) {
        const merged = new Map(this.defaults.get(FALLBACK_LOCALE) || []);
        for (const [code, command] of this.defaults.get(locale) || []) merged.set(code, command);
        return merged;
    }

    /** Each built-in as one streamer's channel uses it (on or off), by code. */
    builtinsFor(userId, locale = FALLBACK_LOCALE) {
        const own = this.personal?.get(userId) || new Map();
        const builtins = new Map();

        for (const [code, command] of this.defaultsFor(locale)) builtins.set(code, own.get(code) || command);

        return builtins;
    }

    /** The commands in force in one streamer's channel, by trigger. */
    forUser(userId, locale = FALLBACK_LOCALE) {
        const cacheKey = `${userId}:${locale}`;

        if (!this.byUser.has(cacheKey)) {
            const triggers = new Map();

            for (const command of this.builtinsFor(userId, locale).values()) {
                if (command.enabled) triggers.set(command.trigger, command);
            }

            for (const command of this.custom?.get(userId) || []) {
                if (command.enabled) triggers.set(command.trigger, command);
            }

            this.byUser.set(cacheKey, triggers);
        }

        return this.byUser.get(cacheKey);
    }

    /** The command a chat message calls, or null: "!heartbeat now" -> heartbeat. */
    match(userId, prefix, text, locale = FALLBACK_LOCALE) {
        if (!text.startsWith(prefix)) return null;

        const trigger = text.slice(prefix.length).trim().split(/\s+/)[0].toLowerCase();

        return trigger === '' ? null : (this.forUser(userId, locale).get(trigger) || null);
    }
}

/** Remembers when each command last ran in each channel. */
export class Cooldowns {
    constructor() {
        this.last = new Map();
    }

    /** True (and starts the cooldown) when the command may run now. */
    take(key, seconds, now = Date.now()) {
        if (seconds > 0 && this.last.has(key) && now - this.last.get(key) < seconds * 1000) return false;

        this.last.set(key, now);
        return true;
    }
}
