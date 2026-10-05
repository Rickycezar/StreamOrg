/**
 * The PHP app's credential encryption (src/Crypto.php), so tokens stored by
 * one side read on the other: AES-256-GCM with a PBKDF2-SHA256 key derived
 * from APP_KEY and a fixed salt, stored as "enc:v1:" + base64(iv, tag, data).
 */
import { createCipheriv, createDecipheriv, pbkdf2Sync, randomBytes } from 'node:crypto';

const PREFIX = 'enc:v1:';
const SALT = 'iforgotthekeys';
const ITERATIONS = 120000;

/** @returns {{encrypt: (plain: ?string) => ?string, decrypt: (blob: ?string) => ?string}} */
export function makeCrypto(appKey) {
    const key = pbkdf2Sync(appKey, SALT, ITERATIONS, 32, 'sha256');

    return {
        encrypt(plain) {
            if (plain === null || plain === undefined || plain === '') return plain ?? null;

            const iv = randomBytes(12);
            const cipher = createCipheriv('aes-256-gcm', key, iv);
            const data = Buffer.concat([cipher.update(String(plain), 'utf8'), cipher.final()]);

            return PREFIX + Buffer.concat([iv, cipher.getAuthTag(), data]).toString('base64');
        },

        decrypt(blob) {
            if (blob === null || blob === undefined || blob === '' || !String(blob).startsWith(PREFIX)) return blob ?? null;

            const raw = Buffer.from(String(blob).slice(PREFIX.length), 'base64');
            if (raw.length < 29) return null;

            try {
                const decipher = createDecipheriv('aes-256-gcm', key, raw.subarray(0, 12));
                decipher.setAuthTag(raw.subarray(12, 28));

                return Buffer.concat([decipher.update(raw.subarray(28)), decipher.final()]).toString('utf8');
            } catch {
                return null;
            }
        },
    };
}
