/**
 * Twitch IRC parsing and the chat connection, against a fake socket.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseLine, parseBadges, TwitchChat } from '../src/chat.js';

test('parses a chat message with tags', () => {
    const msg = parseLine('@badges=moderator/1,subscriber/6;display-name=Ricky_Cezar;id=abc-1;msg\\sx=a\\:b :ricky_cezar!ricky_cezar@ricky_cezar.tmi.twitch.tv PRIVMSG #museu_do_cass :!heartbeat now');

    assert.equal(msg.command, 'PRIVMSG');
    assert.deepEqual(msg.params, ['#museu_do_cass', '!heartbeat now']);
    assert.equal(msg.tags['display-name'], 'Ricky_Cezar');
    assert.equal(msg.tags['msg\\sx'], 'a;b');
    assert.equal(msg.prefix.split('!')[0], 'ricky_cezar');
    assert.deepEqual(parseBadges(msg.tags.badges), { moderator: '1', subscriber: '6' });
});

test('parses PING and numeric replies', () => {
    assert.deepEqual(parseLine('PING :tmi.twitch.tv'), { tags: {}, prefix: null, command: 'PING', params: ['tmi.twitch.tv'] });
    assert.equal(parseLine(':tmi.twitch.tv 001 streamorg :Welcome, GLHF!').command, '001');
    assert.equal(parseLine(''), null);
});

class FakeSocket {
    static last = null;
    constructor(url) {
        this.url = url;
        this.readyState = 0;
        this.sent = [];
        this.listeners = {};
        FakeSocket.last = this;
        setImmediate(() => { this.readyState = 1; this.fire('open'); });
    }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    fire(type, event = {}) { (this.listeners[type] || []).forEach((fn) => fn(event)); }
    send(line) { this.sent.push(line.trim()); }
    close() { this.readyState = 3; this.fire('close'); }
    receive(...lines) { this.fire('message', { data: lines.join('\r\n') + '\r\n' }); }
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('logs in, joins channels, answers PING and hands messages over', async () => {
    const chat = new TwitchChat({ credentials: async () => ({ login: 'streamorg', token: 'tok' }), WebSocketImpl: FakeSocket });
    const messages = [];
    const joined = [];
    chat.on('message', (m) => messages.push(m));
    chat.on('joined', (c) => joined.push(c));

    chat.setChannels(['Museu_Do_Cass']);
    chat.start();
    await tick(); await tick();

    const socket = FakeSocket.last;
    assert.deepEqual(socket.sent.slice(0, 3), ['CAP REQ :twitch.tv/tags twitch.tv/commands', 'PASS oauth:tok', 'NICK streamorg']);

    socket.receive(':tmi.twitch.tv 001 streamorg :Welcome, GLHF!');
    assert.ok(socket.sent.includes('JOIN #museu_do_cass'));

    socket.receive(':streamorg!streamorg@streamorg.tmi.twitch.tv JOIN #museu_do_cass', 'PING :tmi.twitch.tv');
    assert.deepEqual(joined, ['museu_do_cass']);
    assert.ok(socket.sent.includes('PONG :tmi.twitch.tv'));

    socket.receive('@badges=;display-name=Viewer;id=m1 :viewer!viewer@viewer.tmi.twitch.tv PRIVMSG #museu_do_cass :!heartbeat');
    socket.receive('@badges=;display-name=streamorg;id=m2 :streamorg!streamorg@streamorg.tmi.twitch.tv PRIVMSG #museu_do_cass :my own words');
    assert.equal(messages.length, 1);
    assert.equal(messages[0].user.displayName, 'Viewer');

    chat.say('museu_do_cass', 'hello\nthere', 'm1');
    assert.ok(socket.sent.includes('@reply-parent-msg-id=m1 PRIVMSG #museu_do_cass :hello there'));

    chat.setChannels([]);
    assert.ok(socket.sent.includes('PART #museu_do_cass'));

    chat.stop();
});

test('reports a refused token', async () => {
    const chat = new TwitchChat({ credentials: async () => ({ login: 'streamorg', token: 'bad' }), WebSocketImpl: FakeSocket });
    let failed = false;
    chat.on('authFailed', () => { failed = true; });

    chat.start();
    await tick(); await tick();
    FakeSocket.last.receive(':tmi.twitch.tv NOTICE * :Login authentication failed');

    assert.ok(failed);
    chat.stop();
});
