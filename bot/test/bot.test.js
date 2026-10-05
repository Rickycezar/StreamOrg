/**
 * The bot's answering path, with a fake store and chat: the reply, the
 * cooldown, moderators skipping it, and nothing about viewers in the log.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { Bot } from '../src/bot.js';

class FakeChat extends EventEmitter {
    constructor() { super(); this.said = []; this.joinedChannels = new Set(); this.stopped = true; this.connected = false; }
    start() { this.stopped = false; this.connected = true; }
    stop() { this.stopped = true; this.connected = false; }
    restart() {}
    setChannels(logins) { this.channels = [...logins]; }
    say(channel, text, replyTo) { this.said.push({ channel, text, replyTo }); return true; }
}

function fakeStore(overrides = {}) {
    const logs = [];
    return {
        logs,
        ready: async () => true,
        account: async () => ({ enabled: true, prefix: '!', login: 'streamorg', accessToken: 't', refreshToken: 'r', expiresAt: Date.now() + 3_600_000 }),
        twitchApp: async () => ({ clientId: 'id', clientSecret: 'secret' }),
        commands: async () => [
            { userId: null, code: 'heartbeat', trigger: 'heartbeat', response: '💓 @{user} in {channel}', enabled: true, permission: 'everyone', cooldown: 30 },
            { userId: 9, code: 'heartbeat', trigger: 'ping', response: 'pong {user}', enabled: true, permission: 'moderator', cooldown: 0 },
        ],
        channels: async () => [{ userId: 5, login: 'museu_do_cass' }, { userId: 9, login: 'hoku_xx' }],
        report: async () => {},
        log: async (userId, level, message) => { logs.push({ userId, level, message }); },
        joined: async () => {},
        channelError: async () => {},
        prune: async () => {},
        ...overrides,
    };
}

const offline = async () => ({ ok: true, status: 200, json: async () => ({ login: 'streamorg', user_id: '1', expires_in: 3600 }) });

const message = (channel, text, badges = {}, name = 'Viewer') => ({ channel, id: 'm-' + Math.random(), text, user: { login: name.toLowerCase(), displayName: name, badges } });

test('answers the default command, then waits out its cooldown', async () => {
    const store = fakeStore();
    const bot = new Bot({ store, config: { pollSeconds: 60, version: 'test' }, ChatImpl: FakeChat, fetchImpl: offline });
    await bot.load();

    assert.deepEqual(bot.chat.channels, ['museu_do_cass', 'hoku_xx']);

    await bot.answer(message('museu_do_cass', '!heartbeat'));
    await bot.answer(message('museu_do_cass', '!heartbeat', {}, 'Other'));
    await bot.answer(message('museu_do_cass', '!heartbeat', { moderator: '1' }, 'Mod'));

    assert.deepEqual(bot.chat.said.map((s) => s.text), ['💓 @Viewer in museu_do_cass', '💓 @Mod in museu_do_cass']);
    assert.ok(bot.chat.said[0].replyTo);
    assert.ok(store.logs.every((l) => !/Viewer|Mod|Other/.test(l.message)));
    assert.equal(store.logs.filter((l) => l.message === 'Answered !heartbeat').length, 2);
});

test('a streamer\'s own version applies in their channel only', async () => {
    const bot = new Bot({ store: fakeStore(), config: { pollSeconds: 60, version: 'test' }, ChatImpl: FakeChat, fetchImpl: offline });
    await bot.load();

    await bot.answer(message('hoku_xx', '!heartbeat'));
    await bot.answer(message('hoku_xx', '!ping'));
    await bot.answer(message('hoku_xx', '!ping', { broadcaster: '1' }, 'Hoku'));
    await bot.answer(message('elsewhere', '!heartbeat'));

    assert.deepEqual(bot.chat.said.map((s) => s.text), ['pong Hoku']);
});

test('stays out of chat while switched off', async () => {
    const store = fakeStore({ account: async () => ({ enabled: false, prefix: '!', login: 'streamorg', accessToken: 't' }) });
    const bot = new Bot({ store, config: { pollSeconds: 60, version: 'test' }, ChatImpl: FakeChat, fetchImpl: offline });
    await bot.load();

    assert.equal(bot.state, 'idle');
    assert.equal(bot.detail, 'Switched off');
    assert.ok(bot.chat.stopped);
});
