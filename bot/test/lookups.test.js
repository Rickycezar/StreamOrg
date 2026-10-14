/**
 * The giveaway, schedule and watch-time commands, long lists split into
 * several messages, the send queue, what Twitch events become, and the
 * daily follower check.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runLookup, chatTitle, fold, takesEntries } from '../src/builtins.js';
import { splitList } from '../src/commands.js';
import { ChatQueue } from '../src/queue.js';
import { fromChat, fromFollow, fromNotice } from '../src/events.js';
import { FollowerCheck } from '../src/followers.js';

const future = new Date(Date.now() + 86_400_000);

function store(extra = {}) {
    const entries = new Set();
    return {
        entries,
        giveaways: async () => [
            { id: 1, title: 'Natal de Chaves', status: 'open', keyword: 'natal', entryMethod: 'chat', startsAt: null, endsAt: future, publicCode: 'abc123abc123' },
            { id: 2, title: 'Aniversário', status: 'planned', keyword: 'niver', entryMethod: 'chat', startsAt: null, endsAt: null, publicCode: 'def456def456' },
        ],
        enterGiveaway: async (id, viewer) => {
            const key = `${id}:${viewer.id}`;
            if (entries.has(key)) return false;
            entries.add(key);
            return true;
        },
        schedule: async () => [],
        viewerByLogin: async (login) => (login === 'fan' ? { id: 'v2', name: 'Fan' } : null),
        watchtime: async (userId, viewerId) => (viewerId === 'v2' ? { seconds: 3 * 3600 + 120, since: '2026-09-01T12:00:00Z' } : null),
        ...extra,
    };
}

const command = (code, response, messages = {}, trigger = code) => ({ code, trigger, response, messages, enabled: true });

const builtins = new Map([
    ['join', command('join', '@{user} entrou em {giveaway}', {}, 'participar')],
    ['prizes', command('prizes', '', {}, 'premios')],
]);

const ctx = (code, text, cmd, extra = {}) => ({
    store: store(),
    channel: { userId: 5, locale: 'pt-BR', timezone: 'America/Sao_Paulo' },
    user: { id: 'v1', login: 'viewer', displayName: 'Viewer' },
    text,
    command: cmd,
    prefix: '!',
    builtins,
    prizeUrl: 'https://streamorg.com.br/giveaway',
    ...extra,
});

test('the giveaway command lists the open ones with how to enter, and points to the prizes command', async () => {
    const out = await runLookup('giveaway', ctx('giveaway', '!sorteio', command('giveaway', '🎁 Sorteios: {list}. Prêmios: {prizes} <nome>', { none: 'Nenhum.' })));

    assert.deepEqual(out, ['🎁 Sorteios: Natal de Chaves (!participar natal). Prêmios: !premios <nome>']);

    const none = await runLookup('giveaway', ctx('giveaway', '!sorteio', command('giveaway', '{list}', { none: 'Nenhum sorteio aberto.' }), { store: store({ giveaways: async () => [] }) }));
    assert.deepEqual(none, ['Nenhum sorteio aberto.']);
});

test('joining: by keyword or the only open one, once; unknown keywords list the open ones', async () => {
    const join = command('join', '@{user} entrou em {giveaway}', { already: '', unknown: '@{user} não existe {keyword}: {list}', which: 'Qual? {list}', none: 'Nada aberto.' }, 'participar');
    const shared = store();
    const c = (text) => ctx('join', text, join, { store: shared });

    assert.deepEqual(await runLookup('join', c('!participar natal')), ['@Viewer entrou em Natal de Chaves']);
    assert.deepEqual(await runLookup('join', c('!participar NATAL')), [], 'An empty message says nothing.');
    assert.deepEqual(await runLookup('join', c('!participar')), [], 'The only open giveaway is the one.');
    assert.deepEqual(await runLookup('join', c('!participar pascoa')), ['@Viewer não existe pascoa: !participar natal']);
    assert.equal(shared.entries.size, 1);
});

test('prizes: the link of the named giveaway; without a name it asks which, and names the ones not closed', async () => {
    const prizes = command('prizes', '🎁 Prêmios de {giveaway}: {link}', { ask: 'Qual sorteio? {command} <nome>: {list}', unknown: 'Não achei "{name}". Tente: {list}', none: 'Nenhum.' }, 'premios');

    assert.deepEqual(await runLookup('prizes', ctx('prizes', '!premios', prizes)), ['Qual sorteio? !premios <nome>: Natal de Chaves · Aniversário']);
    assert.deepEqual(await runLookup('prizes', ctx('prizes', '!premios aniversario', prizes)), ['🎁 Prêmios de Aniversário: https://streamorg.com.br/giveaway?g=def456def456']);
    assert.deepEqual(await runLookup('prizes', ctx('prizes', '!premios natal', prizes)), ['🎁 Prêmios de Natal de Chaves: https://streamorg.com.br/giveaway?g=abc123abc123']);
    assert.deepEqual(await runLookup('prizes', ctx('prizes', '!premios pascoa', prizes)), ['Não achei "pascoa". Tente: Natal de Chaves · Aniversário']);

    const one = store({ giveaways: async () => [{ id: 1, title: 'Só um', status: 'open', keyword: 'um', entryMethod: 'manual', publicCode: 'aaa' }] });
    assert.deepEqual(await runLookup('prizes', ctx('prizes', '!premios', prizes, { store: one })), ['🎁 Prêmios de Só um: https://streamorg.com.br/giveaway?g=aaa']);
});

test('the schedule: times in the streamer\'s timezone, the games (or the category), live marked', async () => {
    const rows = [
        { title: '[Steam Deck] Vamos jogar #keymailer', games: ['Hades', 'Celeste'], category: 'Games + Demos', start: '2026-10-14T22:00:00Z', status: 'planned' },
        { title: 'Papo com o chat', games: [], category: 'Just Chatting', start: '2026-10-14T18:00:00Z', status: 'live' },
        { title: 'Surpresa #segredo', games: [], category: null, start: '2026-10-14T23:30:00Z', status: 'planned' },
    ];
    const today = await runLookup('today', ctx('today', '!hoje', command('today', '📅 Hoje: {list}', { none: 'Nada.' }), { store: store({ schedule: async () => rows }) }));

    assert.deepEqual(today, ['📅 Hoje: 19:00 Hades + Celeste · 🔴 15:00 Just Chatting · 20:30 Surpresa']);

    const week = await runLookup('week', ctx('week', '!semana', command('week', '{list}', { none: 'Nada.' }), { store: store({ schedule: async () => rows.slice(0, 1) }) }));
    assert.match(week[0], /^qua\.?,? 14\/10,? 19:00 Hades \+ Celeste$/);
});

test('watch time: their own, or someone else\'s by name; nothing recorded says so', async () => {
    const watch = command('watchtime', '@{user} {target} assistiu {time} desde {since}.', { none: '@{user} sem tempo de {target}.' });

    assert.deepEqual(await runLookup('watchtime', ctx('watchtime', '!tempo @fan', watch)), ['@Viewer Fan assistiu 3h 2m desde 01/09/2026.']);
    assert.deepEqual(await runLookup('watchtime', ctx('watchtime', '!tempo', watch)), ['@Viewer sem tempo de Viewer.']);
});

test('long lists go in more messages, each under the chat limit', () => {
    const items = Array.from({ length: 40 }, (_, i) => `seg 14/10 19:00 Uma live com um título comprido número ${i + 1}`);
    const parts = splitList('📅 Semana: {list}', {}, items, { maxParts: 10 });

    assert.ok(parts.length > 1);
    assert.ok(parts.every((p) => p.length <= 490));
    assert.match(parts[0], /^📅 Semana: seg/);
    assert.match(parts[1], /^… seg/);
    assert.equal(parts.join(' ').match(/número/g).length, 40);

    const capped = splitList('{list}', {}, items, { maxParts: 2 });
    assert.equal(capped.length, 2);
    assert.match(capped[1], /…$/);
});

test('small helpers: titles for chat, folding, entry windows', () => {
    assert.equal(chatTitle('Jogo novo #tag #Outra fim'), 'Jogo novo fim');
    assert.equal(fold(' Aniversário '), 'aniversario');
    assert.equal(takesEntries({ status: 'open', entryMethod: 'chat', keyword: 'x', endsAt: new Date(Date.now() - 1000) }), false);
    assert.equal(takesEntries({ status: 'open', entryMethod: 'chat', keyword: 'x' }), true);
});

test('the queue paces messages, drops a repeat still waiting and gives up on stale ones', async () => {
    let clock = 0;
    const sent = [];
    const drops = [];
    const queue = new ChatQueue({
        send: async (channel, text) => { sent.push([clock, text]); return true; },
        onDrop: (channel, reason) => drops.push(reason),
        gapMs: 1000,
        limit: 3,
        windowMs: 10_000,
        maxPending: 3,
        now: () => clock,
        sleep: async (ms) => { clock += ms; },
    });
    const channel = { twitchId: '1' };

    const a = queue.push(channel, { texts: ['a'], key: 'k' });
    const b = queue.push(channel, { texts: ['b'], key: 'k' });
    const c = queue.push(channel, { texts: ['c1', 'c2'] });
    const d = queue.push(channel, { texts: ['d'] });
    const e = queue.push(channel, { texts: ['e'] });

    assert.deepEqual(await Promise.all([a, b, c, d, e]), [true, false, true, true, false]);
    assert.deepEqual(sent.map(([, t]) => t), ['a', 'c1', 'c2', 'd']);
    assert.deepEqual(sent.map(([at]) => at), [0, 1000, 2000, 10_000], 'One a second, and 3 per 10 seconds.');
    assert.deepEqual(drops, ['duplicate', 'full']);

    clock = 50_000;
    const late = queue.push(channel, { texts: ['late'], ttlMs: 0 });
    clock += 1;
    assert.equal(await late, true, 'Sent at once when nothing waits.');
});

test('events: subscriptions, gifts (not the ones of a community gift), raids, cheers and follows', () => {
    const base = { chatter_user_id: '9', chatter_user_login: 'fan', chatter_user_name: 'Fan' };

    assert.deepEqual(fromNotice({ ...base, notice_type: 'resub', message: { text: 'oi' }, resub: { cumulative_months: 14, sub_tier: '2000', streak_months: 3 } }),
        { type: 'resub', actorId: '9', actorLogin: 'fan', actorName: 'Fan', tier: '2', amount: 14, data: { streak: 3, gift: false, gifter: null } }, 'Never the text of the message.');
    assert.equal(fromNotice({ ...base, notice_type: 'sub_gift', sub_gift: { community_gift_id: 'x' } }), null);
    assert.equal(fromNotice({ ...base, notice_type: 'community_sub_gift', community_sub_gift: { total: 5, sub_tier: '1000' } }).amount, 5);
    assert.equal(fromNotice({ ...base, notice_type: 'raid', raid: { user_id: '7', user_login: 'raider', user_name: 'Raider', viewer_count: 42 } }).actorName, 'Raider');
    assert.equal(fromNotice({ ...base, notice_type: 'sub', sub: { is_prime: true } }).tier, 'prime');
    assert.equal(fromNotice({ ...base, notice_type: 'announcement' }), null);
    assert.deepEqual(fromChat({ ...base, cheer: { bits: 100 }, message: { text: 'Cheer100 valeu!' } }), { type: 'cheer', actorId: '9', actorLogin: 'fan', actorName: 'Fan', amount: 100 });
    assert.equal(fromChat({ ...base, message: { text: 'oi' } }), null);
    assert.equal(fromFollow({ user_id: '3', user_login: 'new', user_name: 'New', followed_at: '2026-10-14T10:00:00Z' }).type, 'follow');
});

test('the daily follower check reads every page with an allowed token, and says why when it cannot', async () => {
    const applied = [];
    const failed = [];
    const pages = {
        undefined: { data: [{ user_id: '1', user_login: 'a', user_name: 'A', followed_at: '2026-01-01T00:00:00Z' }], pagination: { cursor: 'p2' }, total: 2 },
        p2: { data: [{ user_id: '2', user_login: 'b', user_name: 'B', followed_at: '2026-02-01T00:00:00Z' }], pagination: {}, total: 2 },
    };
    const check = new FollowerCheck({
        store: {
            followersDue: async () => [5, 6],
            streamerToken: async () => 'streamer-token',
            applyFollowers: async (userId, list, total) => { applied.push({ userId, ids: list.map((f) => f.id), total }); return { first: true, gone: 0, added: 0 }; },
            followersFailed: async (userId, reason) => { failed.push({ userId, reason }); },
            log: async () => {},
        },
        helix: { user: async (token, method, path, { query }) => ({ status: 200, data: pages[query.after] }) },
        botToken: async () => 'bot-token',
        botId: () => '999',
        botScopes: () => [],
    });

    await check.tick([
        { userId: 5, twitchId: '100', scopes: ['moderator:read:followers'], access: 'permission' },
        { userId: 6, twitchId: '200', scopes: [], access: 'moderator' },
    ]);

    assert.deepEqual(applied, [{ userId: 5, ids: ['1', '2'], total: 2 }]);
    assert.equal(failed.length, 1);
    assert.match(failed[0].reason, /no permission/);
});
