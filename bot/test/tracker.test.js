/**
 * Viewer statistics: live detection, watch time from the chatter list,
 * message counts, and which token reads the chatter list.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Tracker } from '../src/tracker.js';

function setup({ live = true, chattersStatus = 200 } = {}) {
    const writes = [];
    const closed = [];
    let clock = 1_000_000;

    const store = {
        writes,
        closed,
        openBroadcast: async () => 42,
        liveContent: async () => 7,
        addViewerStats: async (at, rows) => { writes.push({ at, rows }); },
        closeBroadcasts: async (ids) => { closed.push(ids); },
        setChattersOk: async () => {},
        streamerToken: async () => 'streamer-token',
    };

    const helix = {
        calls: [],
        app: async (method, path, { query }) => {
            helix.calls.push({ path, query });
            return { status: 200, data: { data: live ? [{ user_id: '100', type: 'live', id: 's1', started_at: '2026-10-06T20:00:00Z', game_id: '509658', game_name: 'Just Chatting', viewer_count: 30 }] : [] } };
        },
        user: async (token, method, path, { query }) => {
            helix.calls.push({ path, query, token });
            return chattersStatus === 200
                ? { status: 200, data: { data: [{ user_id: 'v1', user_login: 'ana', user_name: 'Ana' }, { user_id: '999', user_login: 'streamorg', user_name: 'StreamOrg' }], pagination: {} } }
                : { status: chattersStatus, data: null };
        },
    };

    const tracker = new Tracker({ store, helix, botToken: async () => 'bot-token', botId: () => '999', intervalMs: 60_000, now: () => clock });
    return { tracker, store, helix, advance: (ms) => { clock += ms; } };
}

const channel = (over = {}) => ({ userId: 5, twitchId: '100', login: 'museu_do_cass', scopes: ['channel:bot', 'moderator:read:chatters'], access: 'permission', ...over });

test('records watch time for chatters (not the bot) and the live content and category', async () => {
    const { tracker, store, advance } = setup();
    tracker.setChannels([channel()]);

    await tracker.tick();
    advance(60_000);
    await tracker.tick();

    assert.equal(store.writes.length, 2);
    const { at, rows } = store.writes[1];
    assert.deepEqual(at, { broadcastId: 42, userId: 5, contentId: 7, categoryId: '509658', categoryName: 'Just Chatting' });
    assert.deepEqual(rows.map((r) => [r.viewer.id, r.seconds]), [['v1', 60]]);
    assert.deepEqual(store.closed.at(-1), [42]);
});

test('counts messages only while live, written with the minute\'s batch', async () => {
    const { tracker, store, advance } = setup();
    tracker.setChannels([channel()]);

    tracker.countMessage('100', { id: 'v2', login: 'bia', name: 'Bia' });
    await tracker.tick();
    tracker.countMessage('100', { id: 'v2', login: 'bia', name: 'Bia' });
    tracker.countMessage('100', { id: 'v2', login: 'bia', name: 'Bia' });
    tracker.countMessage('100', { id: 'v1', login: 'ana', name: 'Ana' });
    advance(60_000);
    await tracker.tick();

    const rows = Object.fromEntries(store.writes[1].rows.map((r) => [r.viewer.id, r]));
    assert.equal(rows.v2.messages, 2);
    assert.equal(rows.v2.seconds, 0);
    assert.equal(rows.v1.messages, 1);
    assert.equal(rows.v1.seconds, 60);
    assert.ok(!store.writes[0].rows.some((r) => r.messages > 0));
});

test('nothing is recorded while the channel is offline', async () => {
    const { tracker, store } = setup({ live: false });
    tracker.setChannels([channel()]);

    tracker.countMessage('100', { id: 'v2', login: 'bia', name: 'Bia' });
    await tracker.tick();

    assert.equal(store.writes.length, 0);
    assert.deepEqual(store.closed, [[]]);
});

test('uses the streamer\'s token when granted, the bot\'s as a moderator, and gives up otherwise', async () => {
    let { tracker, helix } = setup();
    tracker.setChannels([channel()]);
    await tracker.tick();
    assert.equal(helix.calls.find((c) => c.path === 'chat/chatters').token, 'streamer-token');
    assert.equal(helix.calls.find((c) => c.path === 'chat/chatters').query.moderator_id, '100');

    ({ tracker, helix } = setup());
    tracker.setChannels([channel({ scopes: [], access: 'moderator' })]);
    await tracker.tick();
    assert.equal(helix.calls.find((c) => c.path === 'chat/chatters').token, 'bot-token');
    assert.equal(helix.calls.find((c) => c.path === 'chat/chatters').query.moderator_id, '999');

    let store;
    ({ tracker, helix, store } = setup());
    tracker.setChannels([channel({ scopes: ['channel:bot'], access: 'permission' })]);
    await tracker.tick();
    assert.ok(!helix.calls.some((c) => c.path === 'chat/chatters'));
    assert.deepEqual(store.writes[0].rows, []);
});

test('watch time after a gap is capped at two intervals', async () => {
    const { tracker, store, advance } = setup();
    tracker.setChannels([channel()]);

    await tracker.tick();
    advance(10 * 60_000);
    await tracker.tick();

    assert.equal(store.writes[1].rows[0].seconds, 120);
});
