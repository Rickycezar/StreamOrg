/**
 * Which command a message calls in which channel, who may use it, and
 * what the reply says.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { CommandBook, Cooldowns, allowed, render, formatUptime, targetOf } from '../src/commands.js';

const heartbeat = { userId: null, code: 'heartbeat', trigger: 'heartbeat', response: 'Here, @{user}! {uptime}', enabled: true, permission: 'everyone', cooldown: 10 };

test('channels use the default unless their streamer made their own', () => {
    const book = new CommandBook();
    book.load([heartbeat, { ...heartbeat, userId: 7, trigger: 'ping', response: 'pong {user}' }, { ...heartbeat, code: 'unknown', trigger: 'x' }]);

    assert.equal(book.match(1, '!', '!heartbeat')?.response, 'Here, @{user}! {uptime}');
    assert.equal(book.match(1, '!', '!ping'), null);
    assert.equal(book.match(7, '!', '!PING extra words')?.response, 'pong {user}');
    assert.equal(book.match(7, '!', '!heartbeat'), null);
    assert.equal(book.match(1, '!', 'heartbeat'), null);
    assert.equal(book.match(1, '!', '!x'), null);
    assert.equal(book.match(1, '?', '?heartbeat')?.code, 'heartbeat');
});

test('a streamer can switch a command off in their channel', () => {
    const book = new CommandBook();
    book.load([heartbeat, { ...heartbeat, userId: 7, enabled: false }]);

    assert.equal(book.match(7, '!', '!heartbeat'), null);
    assert.ok(book.match(8, '!', '!heartbeat'));
});

test('permissions follow chat badges', () => {
    assert.ok(allowed('everyone', {}));
    assert.ok(!allowed('subscriber', {}));
    assert.ok(allowed('subscriber', { founder: '0' }));
    assert.ok(allowed('vip', { moderator: '1' }));
    assert.ok(!allowed('moderator', { vip: '1' }));
    assert.ok(allowed('broadcaster', { broadcaster: '1' }));
    assert.ok(!allowed('nonsense', { moderator: '1' }));
});

test('replies fill known placeholders and keep the rest', () => {
    assert.equal(render('Hi {user} in {channel} {nope}', { user: 'Ana', channel: 'x' }), 'Hi Ana in x {nope}');
    assert.equal(formatUptime(3_725_000), '1h 2m');
    assert.equal(formatUptime(90_000_000), '1d 1h');
    assert.equal(formatUptime(30_000), '0m');
});

test('cooldowns are per key', () => {
    const cooldowns = new Cooldowns();
    assert.ok(cooldowns.take('1:heartbeat', 10, 1000));
    assert.ok(!cooldowns.take('1:heartbeat', 10, 5000));
    assert.ok(cooldowns.take('2:heartbeat', 10, 5000));
    assert.ok(cooldowns.take('1:heartbeat', 10, 11_001));
    assert.ok(cooldowns.take('1:heartbeat', 0, 11_002));
});

test('custom commands sit next to the built-in ones, per channel', () => {
    const book = new CommandBook();
    book.load([heartbeat], [{ userId: 7, code: 'custom', trigger: 'discord', response: 'x', enabled: true, permission: 'everyone', cooldown: 0 }]);

    assert.equal(book.match(7, '!', '!discord')?.code, 'custom');
    assert.equal(book.match(7, '!', '!heartbeat')?.code, 'heartbeat');
    assert.equal(book.match(1, '!', '!discord'), null);
});

test('{target} is the first word after the command, without @, or the user', () => {
    assert.equal(targetOf('!so @Hoku_xx great stream', 'Ana'), 'Hoku_xx');
    assert.equal(targetOf('!hug', 'Ana'), 'Ana');
    assert.equal(targetOf('!hug @@<script>', 'Ana'), 'script');
});
