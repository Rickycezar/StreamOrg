/**
 * Chat replies the streamer sets on their StreamOrg overlays (Stream tools
 * → Overlays): a message when someone fires a custom alert with its chat
 * command, when a moderator gives a shoutout with the shoutout overlay's
 * commands, and when a viewer shares a watch streak (one text for usual
 * streaks, one for the big ones). Every alert overlay with a reply
 * answers its own command; for shoutouts and watch streaks each channel
 * uses its oldest switched-on overlay of the type that has a reply; overlays
 * switched off by the administrators (all of them, or the type) send
 * nothing. Read from 045_overlays.sql's overlays table.
 */

/** Overlay roles -> chat badges that hold them (the broadcaster always may). */
const ROLE_BADGES = {
    broadcaster: ['broadcaster'],
    mod: ['moderator'],
    vip: ['vip'],
    subscriber: ['subscriber', 'founder'],
};

/** Whether a chatter with these badges holds one of the roles. */
export function holdsRole(roles, badges) {
    if ('broadcaster' in badges || roles.includes('everyone')) return true;
    return roles.some((role) => (ROLE_BADGES[role] || []).some((badge) => badge in badges));
}

export class OverlayReplies {
    constructor() {
        this.alerts = new Map();
        this.shoutouts = new Map();
        this.streaks = new Map();
    }

    /**
     * @param {{enabled: boolean, typesOff: string[], rows: {userId: number, type: string, settings: object}[]}} data
     *        rows oldest first
     */
    load({ enabled, typesOff, rows }) {
        this.alerts = new Map();
        this.shoutouts = new Map();
        this.streaks = new Map();
        if (!enabled) return;

        for (const { userId, type, settings } of rows) {
            if (typesOff.includes(type)) continue;

            if (type === 'alert' && String(settings.command || '').trim() && String(settings.bot_reply || '').trim()) {
                const list = this.alerts.get(userId) || [];
                list.push({ command: String(settings.command).toLowerCase(), roles: settings.roles || ['broadcaster', 'mod'], reply: String(settings.bot_reply) });
                this.alerts.set(userId, list);
            }

            if (type === 'shoutout' && !this.shoutouts.has(userId) && String(settings.bot_reply || '').trim()) {
                this.shoutouts.set(userId, {
                    commands: (settings.commands || []).map((c) => String(c).toLowerCase()),
                    roles: settings.roles || ['broadcaster', 'mod'],
                    reply: String(settings.bot_reply),
                });
            }

            if (type === 'watch_streak' && !this.streaks.has(userId)
                && (String(settings.bot_reply || '').trim() || String(settings.bot_reply_big || '').trim())) {
                this.streaks.set(userId, {
                    reply: String(settings.bot_reply || ''),
                    replyBig: String(settings.bot_reply_big || ''),
                    big: new Set((settings.big_streaks || []).map(Number)),
                });
            }
        }
    }

    /**
     * The replies of the custom alerts a chat message fires ("!alerta" by
     * someone allowed), one per alert with that command.
     *
     * @returns {{command: string, reply: string}[]}
     */
    alert(userId, text, badges) {
        const match = /^!(\w+)/.exec(String(text || '').trim());
        if (!match) return [];

        const command = match[1].toLowerCase();
        return (this.alerts.get(userId) || []).filter((a) => a.command === command && holdsRole(a.roles, badges));
    }

    /** Whether the channel needs chat notices (watch streaks). */
    wantsNotices(userId) {
        return this.streaks.has(userId);
    }

    /**
     * The shoutout a chat message asks for: "!so @name" by someone allowed.
     *
     * @returns {{target: string, reply: string}|null}
     */
    shoutout(userId, text, badges) {
        const rule = this.shoutouts.get(userId);
        const match = /^!(\w+)\s+@?([A-Za-z0-9_]{1,25})\b/.exec(String(text || '').trim());

        if (!rule || !match || !rule.commands.includes(match[1].toLowerCase()) || !holdsRole(rule.roles, badges)) return null;

        return { target: match[2].toLowerCase(), reply: rule.reply };
    }

    /** The reply for a watch streak, or null when that kind of streak has none. */
    streak(userId, count) {
        const rule = this.streaks.get(userId);
        if (!rule) return null;

        const text = rule.big.has(Number(count)) ? rule.replyBig : rule.reply;
        return text.trim() ? text : null;
    }
}
