/**
 * The built-in commands that look things up in StreamOrg: the channel's
 * giveaways (list them, enter one by chat, link a giveaway's prize page),
 * its planned content (today, the next 7 days) and someone's watch time.
 *
 * Each returns the messages to post: none when the command's message for
 * that outcome is empty, more than one when a list is too long for one.
 * Dates and times are in the streamer's language and timezone.
 */
import { render, splitList, formatUptime, targetOf } from './commands.js';

/** Codes answered here; the others fill their reply in commands.js. */
export const LOOKUPS = ['giveaway', 'join', 'prizes', 'today', 'week', 'watchtime'];

/**
 * @param {string} code
 * @param {{store: object, channel: {userId: number, locale: string, timezone: string}, user: {id: string, login: string, displayName: string},
 *          text: string, command: object, prefix: string, builtins: Map<string, object>, prizeUrl: ?string, now?: Date}} ctx
 * @returns {Promise<string[]>}
 */
export async function runLookup(code, ctx) {
    const run = { giveaway, join, prizes, today: (c) => schedule(c, 1), week: (c) => schedule(c, 7), watchtime }[code];
    return run ? run({ now: new Date(), ...ctx }) : [];
}

function message(command, key) {
    return String(command.messages?.[key] ?? '');
}

/** One message, or none when its template is empty. */
function say(template, values) {
    const text = render(template, values);
    return text === '' ? [] : [text];
}

/** The rest of a chat message after the command: "!premios Natal de Chaves" -> "Natal de Chaves". */
function argument(text) {
    return String(text || '').trim().split(/\s+/).slice(1).join(' ').trim();
}

/** "!participar natal" as typed in this channel, or '' when the join command is off. */
function joinCommand(ctx, keyword) {
    const join = ctx.builtins.get('join');
    return join && join.enabled ? `${ctx.prefix}${join.trigger} ${keyword}` : '';
}

/** Whether a giveaway takes entries by chat right now. */
export function takesEntries(g, now = new Date()) {
    return g.status === 'open' && g.entryMethod === 'chat' && Boolean(g.keyword)
        && (!g.startsAt || new Date(g.startsAt) <= now) && (!g.endsAt || new Date(g.endsAt) > now);
}

/** Lower case, without accents, for matching what someone typed against names. */
export function fold(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

async function giveaway(ctx) {
    const list = (await ctx.store.giveaways(ctx.channel.userId)).filter((g) => g.status === 'open');
    if (list.length === 0) return say(message(ctx.command, 'none'), { user: ctx.user.displayName });

    const prizes = ctx.builtins.get('prizes');
    const items = list.map((g) => {
        const command = takesEntries(g, ctx.now) ? joinCommand(ctx, g.keyword) : '';
        return command ? `${g.title} (${command})` : g.title;
    });

    return splitList(ctx.command.response, {
        user: ctx.user.displayName,
        prizes: prizes && prizes.enabled ? `${ctx.prefix}${prizes.trigger}` : '',
    }, items);
}

async function join(ctx) {
    const open = (await ctx.store.giveaways(ctx.channel.userId)).filter((g) => takesEntries(g, ctx.now));
    const user = ctx.user.displayName;

    if (open.length === 0) return say(message(ctx.command, 'none'), { user });

    const keyword = fold(argument(ctx.text).split(/\s+/)[0] || '');
    const choices = open.map((g) => joinCommand(ctx, g.keyword)).join(' · ');
    let chosen = null;

    if (keyword) {
        chosen = open.find((g) => fold(g.keyword) === keyword) || null;
        if (!chosen) return say(message(ctx.command, 'unknown'), { user, keyword, list: choices });
    } else if (open.length === 1) {
        chosen = open[0];
    } else {
        return say(message(ctx.command, 'which'), { user, list: choices });
    }

    const entered = await ctx.store.enterGiveaway(chosen.id, { id: ctx.user.id, login: ctx.user.login, name: ctx.user.displayName });

    return say(entered ? ctx.command.response : message(ctx.command, 'already'), { user, giveaway: chosen.title });
}

async function prizes(ctx) {
    const list = (await ctx.store.giveaways(ctx.channel.userId)).filter((g) => g.status === 'open' || g.status === 'planned');
    const user = ctx.user.displayName;

    if (list.length === 0) return say(message(ctx.command, 'none'), { user });

    const name = argument(ctx.text);
    const names = list.map((g) => g.title).join(' · ');
    let chosen = null;

    if (name === '') {
        if (list.length > 1) return say(message(ctx.command, 'ask'), { user, command: `${ctx.prefix}${ctx.command.trigger}`, list: names });
        chosen = list[0];
    } else {
        const wanted = fold(name);
        chosen = list.find((g) => fold(g.keyword) === wanted || fold(g.title) === wanted)
            || list.find((g) => fold(g.title).includes(wanted) || (g.keyword && fold(g.keyword).startsWith(wanted)))
            || null;

        if (!chosen) return say(message(ctx.command, 'unknown'), { user, name, list: names });
    }

    if (!ctx.prizeUrl) return [];

    return say(ctx.command.response, { user, giveaway: chosen.title, link: `${ctx.prizeUrl}?g=${chosen.publicCode}` });
}

/** A content title for chat: without its hashtags. */
export function chatTitle(title) {
    return String(title || '').replace(/(^|\s)#[^\s#]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
}

async function schedule(ctx, days) {
    const { locale, timezone } = ctx.channel;
    const rows = await ctx.store.schedule(ctx.channel.userId, timezone, days);

    if (rows.length === 0) return say(message(ctx.command, 'none'), { user: ctx.user.displayName });

    const time = new Intl.DateTimeFormat(locale, { timeZone: timezone, hour: '2-digit', minute: '2-digit' });
    const day = new Intl.DateTimeFormat(locale, { timeZone: timezone, weekday: 'short', day: '2-digit', month: '2-digit' });

    const items = rows.map((row) => {
        const at = new Date(row.start);
        const when = days === 1 ? time.format(at) : `${day.format(at)} ${time.format(at)}`;
        return `${row.status === 'live' ? '🔴 ' : ''}${when} ${chatTitle(row.title)}`;
    });

    return splitList(ctx.command.response, { user: ctx.user.displayName }, items);
}

async function watchtime(ctx) {
    const user = ctx.user.displayName;
    const target = targetOf(ctx.text, user);
    const self = target === user || target.toLowerCase() === String(ctx.user.login).toLowerCase();
    const viewer = self ? { id: ctx.user.id, name: user } : await ctx.store.viewerByLogin(target.toLowerCase());
    const stats = viewer ? await ctx.store.watchtime(ctx.channel.userId, viewer.id) : null;
    const shown = viewer?.name || target;

    if (!stats || stats.seconds < 60) return say(message(ctx.command, 'none'), { user, target: shown });

    const since = new Intl.DateTimeFormat(ctx.channel.locale, { timeZone: ctx.channel.timezone, day: '2-digit', month: '2-digit', year: 'numeric' })
        .format(new Date(stats.since));

    return say(ctx.command.response, { user, target: shown, time: formatUptime(stats.seconds * 1000), since });
}
