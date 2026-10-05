/**
 * The commands the bot answers. Each command is a built-in behaviour
 * (BUILTINS, keyed by code) with a trigger and a reply template; every
 * channel uses the default version of each command unless its streamer
 * made their own, which then replaces it in their channel only.
 */

/** What each built-in fills its reply's {placeholders} with. */
export const BUILTINS = {
    heartbeat: (ctx) => ({
        user: ctx.user.displayName,
        channel: ctx.channel,
        uptime: formatUptime(ctx.uptimeMs),
    }),
};

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
    return String(template).replace(/\{([a-z_]+)\}/g, (whole, name) => (name in values ? String(values[name]) : whole));
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

    /** @param {Array<{userId: ?number, code: string, trigger: string, response: string, enabled: boolean, permission: string, cooldown: number}>} rows */
    load(rows) {
        this.defaults = new Map();
        const personal = new Map();

        for (const row of rows) {
            if (!(row.code in BUILTINS)) continue;

            if (row.userId === null) {
                this.defaults.set(row.code, row);
            } else {
                if (!personal.has(row.userId)) personal.set(row.userId, new Map());
                personal.get(row.userId).set(row.code, row);
            }
        }

        this.personal = personal;
        this.byUser = new Map();
    }

    /** The commands in force in one streamer's channel, by trigger. */
    forUser(userId) {
        if (!this.byUser.has(userId)) {
            const own = this.personal?.get(userId) || new Map();
            const triggers = new Map();

            for (const [code, command] of this.defaults) {
                const effective = own.get(code) || command;
                if (effective.enabled) triggers.set(effective.trigger, effective);
            }

            this.byUser.set(userId, triggers);
        }

        return this.byUser.get(userId);
    }

    /** The command a chat message calls, or null: "!heartbeat now" -> heartbeat. */
    match(userId, prefix, text) {
        if (!text.startsWith(prefix)) return null;

        const trigger = text.slice(prefix.length).trim().split(/\s+/)[0].toLowerCase();

        return trigger === '' ? null : (this.forUser(userId).get(trigger) || null);
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
