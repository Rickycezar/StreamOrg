/**
 * The EventSub socket: welcome, notifications, duplicates, and Twitch's
 * reconnect hand-over.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventSubSocket } from '../src/eventsub.js';
import { queryString } from '../src/helix.js';

class FakeSocket {
    static all = [];
    constructor(url) { this.url = url; this.readyState = 1; this.listeners = {}; this.closed = false; FakeSocket.all.push(this); }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    fire(type, event = {}) { (this.listeners[type] || []).forEach((fn) => fn(event)); }
    close() { if (this.closed) return; this.closed = true; this.readyState = 3; this.fire('close'); }
    receive(message) { this.fire('message', { data: JSON.stringify(message) }); }
}

const welcome = (id) => ({ metadata: { message_id: 'w-' + id, message_type: 'session_welcome' }, payload: { session: { id, keepalive_timeout_seconds: 10 } } });

test('welcome, notifications and duplicate messages', () => {
    FakeSocket.all = [];
    const socket = new EventSubSocket({ WebSocketImpl: FakeSocket });
    const sessions = [];
    const events = [];
    socket.on('welcome', (id) => sessions.push(id));
    socket.on('notification', (n) => events.push(n));

    socket.start();
    const ws = FakeSocket.all[0];
    ws.receive(welcome('s1'));

    const note = { metadata: { message_id: 'n1', message_type: 'notification' }, payload: { subscription: { type: 'channel.chat.message' }, event: { message: { text: 'hi' } } } };
    ws.receive(note);
    ws.receive(note);

    assert.deepEqual(sessions, ['s1']);
    assert.equal(socket.connected, true);
    assert.equal(events.length, 1);
    assert.equal(events[0].event.message.text, 'hi');
    socket.stop();
});

test('a reconnect message hands over to the new socket after its welcome', () => {
    FakeSocket.all = [];
    const socket = new EventSubSocket({ WebSocketImpl: FakeSocket });
    let closedEvents = 0;
    socket.on('closed', () => closedEvents++);

    socket.start();
    const first = FakeSocket.all[0];
    first.receive(welcome('s1'));
    first.receive({ metadata: { message_id: 'r1', message_type: 'session_reconnect' }, payload: { session: { reconnect_url: 'wss://next' } } });

    const second = FakeSocket.all[1];
    assert.equal(second.url, 'wss://next');
    assert.equal(first.closed, false);

    second.receive(welcome('s1'));

    assert.equal(first.closed, true);
    assert.equal(socket.socket, second);
    assert.equal(closedEvents, 0);
    socket.stop();
});

test('Helix repeats keys for lists and drops empty values', () => {
    assert.equal(queryString({ user_id: ['1', '2'], first: 100, after: undefined }), 'user_id=1&user_id=2&first=100');
});
