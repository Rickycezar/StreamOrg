/**
 * The bot against a fake Twitch: subscribing per channel and telling the
 * kinds of access apart, answering through the chat API, cooldowns, and
 * nothing about viewers in the activity log.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { Bot, badgesOf } from '../src/bot.js';

class FakeSocket extends EventEmitter {
    constructor() { super(); this.stopped = true; this.connected = false; }
    start() { this.stopped = false; this.connected = true; }
    stop() { this.stopped = true; this.connected = false; }
}

/** Answers Helix calls from a table; records every call. */
function fakeHelix(overrides = {}) {
    const calls = [];
    const routes = {
        'GET eventsub/conduits': () => ({ status: 200, data: { data: [] } }),
        'POST eventsub/conduits': () => ({ status: 200, data: { data: [{ id: 'conduit-1', shard_count: 1 }] } }),
        'PATCH eventsub/conduits/shards': () => ({ status: 202, data: { data: [{ id: '0' }], errors: [] } }),
        'GET eventsub/subscriptions': () => ({ status: 200, data: { data: [], pagination: {} } }),
        'POST eventsub/subscriptions': ({ body }) => body.condition.broadcaster_user_id === '300'
            ? { status: 403, data: { message: 'subscription missing proper authorization' } }
            : { status: 202, data: { data: [{ id: 'sub-' + body.condition.broadcaster_user_id }] } },
        'DELETE eventsub/subscriptions': () => ({ status: 204, data: null }),
        'POST chat/messages': () => ({ status: 200, data: { data: [{ message_id: 'x', is_sent: true }] } }),
        'GET streams': () => ({ status: 200, data: { data: [] } }),
        ...overrides,
    };

    return {
        calls,
        app: async (method, path, options = {}) => {
            calls.push({ method, path, ...options });
            return (routes[`${method} ${path}`] || (() => ({ status: 404, data: null })))(options);
        },
        user: async () => ({ status: 403, data: null }),
    };
}

function fakeStore() {
    const logs = [];
    const access = {};
    return {
        logs,
        access,
        ready: async () => true,
        account: async () => ({ enabled: true, prefix: '!', login: 'streamorg', userId: '999', accessToken: 't', refreshToken: 'r', expiresAt: Date.now() + 3_600_000, conduitId: null }),
        saveConduit: async () => {},
        twitchApp: async () => ({ clientId: 'id', clientSecret: 'secret' }),
        commands: async () => [
            { userId: null, code: 'heartbeat', trigger: 'heartbeat', response: '💓 @{user} in {channel}', enabled: true, permission: 'everyone', cooldown: 30 },
            { userId: 9, code: 'heartbeat', trigger: 'ping', response: 'pong {user}', enabled: true, permission: 'moderator', cooldown: 0 },
        ],
        channels: async () => [
            { userId: 5, login: 'museu_do_cass', twitchId: '100', scopes: ['channel:bot'], access: 'unknown', checkedAt: 0 },
            { userId: 9, login: 'hoku_xx', twitchId: '200', scopes: [], access: 'unknown', checkedAt: 0 },
            { userId: 7, login: 'nobody', twitchId: '300', scopes: [], access: 'unknown', checkedAt: 0 },
        ],
        setAccess: async (userId, value) => { access[userId] = value; },
        report: async () => {},
        log: async (userId, level, message) => { logs.push({ userId, level, message }); },
        prune: async () => {},
    };
}

const offline = async () => ({ ok: true, status: 200, json: async () => ({ login: 'streamorg', user_id: '999', expires_in: 3600 }) });

async function started(overrides) {
    const store = fakeStore();
    const helix = fakeHelix(overrides);
    const bot = new Bot({ store, config: { pollSeconds: 60, version: 'test' }, SocketImpl: FakeSocket, helix, fetchImpl: offline });
    await bot.load();
    await bot.attach('session-1');
    return { bot, store, helix };
}

const chat = (broadcaster, text, badges = [], name = 'Viewer', id = 'u1') => ({
    broadcaster_user_id: broadcaster,
    chatter_user_id: id,
    chatter_user_login: name.toLowerCase(),
    chatter_user_name: name,
    message_id: 'm-' + Math.random(),
    message: { text },
    badges,
});

test('subscribes per channel and tells permission, moderator and none apart', async () => {
    const { bot, store, helix } = await started();

    const shard = helix.calls.find((c) => c.path === 'eventsub/conduits/shards');
    assert.equal(shard.body.shards[0].transport.session_id, 'session-1');
    assert.deepEqual(store.access, { 5: 'permission', 9: 'moderator', 7: 'none' });
    assert.equal(bot.state, 'connected');
    assert.match(bot.detail, /reading 2 of 3/);
    assert.ok(store.logs.some((l) => l.userId === 7 && l.level === 'warn'));
});

test('answers the default command through the chat API, then waits out its cooldown', async () => {
    const { bot, store, helix } = await started();

    await bot.onChat(chat('100', '!heartbeat'));
    await bot.onChat(chat('100', '!heartbeat', [], 'Other', 'u2'));
    await bot.onChat(chat('100', '!heartbeat', [{ set_id: 'moderator', id: '1' }], 'Mod', 'u3'));
    await bot.onChat(chat('100', '!heartbeat', [], 'streamorg', '999'));

    const sent = helix.calls.filter((c) => c.path === 'chat/messages').map((c) => c.body);
    assert.deepEqual(sent.map((b) => b.message), ['💓 @Viewer in museu_do_cass', '💓 @Mod in museu_do_cass']);
    assert.equal(sent[0].sender_id, '999');
    assert.equal(sent[0].broadcaster_id, '100');
    assert.ok(sent[0].reply_parent_message_id);
    assert.ok(store.logs.every((l) => !/Viewer|Mod|Other/.test(l.message)));
});

test('a streamer\'s own version applies in their channel only', async () => {
    const { bot, helix } = await started();

    await bot.onChat(chat('200', '!heartbeat'));
    await bot.onChat(chat('200', '!ping'));
    await bot.onChat(chat('200', '!ping', [{ set_id: 'broadcaster', id: '1' }], 'Hoku'));

    assert.deepEqual(helix.calls.filter((c) => c.path === 'chat/messages').map((c) => c.body.message), ['pong Hoku']);
});

test('a refused reply is logged, not retried', async () => {
    const { bot, store } = await started({
        'POST chat/messages': () => ({ status: 200, data: { data: [{ is_sent: false, drop_reason: { message: 'slow mode' } }] } }),
    });

    await bot.onChat(chat('100', '!heartbeat'));

    assert.ok(store.logs.some((l) => /slow mode/.test(l.message)));
});

test('a revoked subscription marks the channel as without access', async () => {
    const { bot, store } = await started();

    bot.socket.emit('revocation', { status: 'authorization_revoked', condition: { broadcaster_user_id: '100' } });
    await new Promise((r) => setImmediate(r));

    assert.equal(store.access[5], 'none');
    assert.ok(!bot.subscriptions.has('100'));
});

test('stays out of chat while switched off', async () => {
    const store = { ...fakeStore(), account: async () => ({ enabled: false, prefix: '!', login: 'streamorg', userId: '999', accessToken: 't' }) };
    const bot = new Bot({ store, config: { pollSeconds: 60, version: 'test' }, SocketImpl: FakeSocket, helix: fakeHelix(), fetchImpl: offline });
    await bot.load();

    assert.equal(bot.state, 'idle');
    assert.equal(bot.detail, 'Switched off');
    assert.ok(bot.socket.stopped);
});

test('badges from EventSub become a lookup', () => {
    assert.deepEqual(badgesOf({ badges: [{ set_id: 'subscriber', id: '12' }, { set_id: 'vip', id: '1' }] }), { subscriber: '12', vip: '1' });
});
