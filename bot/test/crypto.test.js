/**
 * Tokens encrypted by the PHP app must read here, and the other way round.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { makeCrypto } from '../src/crypto.js';

const crypto = makeCrypto('test-key-for-fixture');

test('reads what the PHP app encrypted', () => {
    assert.equal(crypto.decrypt('enc:v1:Z5b1U53Vw7C7xjXp66LqiCbiuPdOmSLepRo4ffDkveMSjtsaq1VzGuQMag=='), 'oauth-token-123');
});

test('round-trips its own values and passes plain or empty ones through', () => {
    assert.equal(crypto.decrypt(crypto.encrypt('abc')), 'abc');
    assert.equal(crypto.decrypt('plain'), 'plain');
    assert.equal(crypto.encrypt(''), '');
    assert.equal(crypto.decrypt(null), null);
});

test('a wrong key or tampered value reads as null', () => {
    const blob = crypto.encrypt('secret');
    assert.equal(makeCrypto('another-key').decrypt(blob), null);
    assert.equal(crypto.decrypt(blob.slice(0, -4) + 'AAAA'), null);
});
