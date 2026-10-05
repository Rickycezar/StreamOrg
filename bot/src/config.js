/**
 * Settings from the environment: the same variables the PHP app reads
 * (APP_KEY, DATABASE_URL or DB_*), so both Coolify apps share them.
 */
import { readFileSync } from 'node:fs';

const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));

/** @returns {{appKey: string, database: object, healthPort: number, pollSeconds: number, version: string}} */
export function loadConfig(env = process.env) {
    const appKey = String(env.APP_KEY || '').trim();

    if (appKey === '' || appKey.startsWith('CHANGE_ME')) {
        throw new Error('APP_KEY is not set: use the same value as the StreamOrg app.');
    }

    return {
        appKey,
        database: databaseConfig(env),
        healthPort: parseInt(env.BOT_HEALTH_PORT || '8080', 10),
        pollSeconds: parseInt(env.BOT_POLL_SECONDS || '60', 10),
        version: pkg.version,
    };
}

/** node-postgres settings from DATABASE_URL, or from DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSLMODE. */
export function databaseConfig(env) {
    const ssl = (mode) => (mode === 'require' || mode === 'verify-ca' || mode === 'verify-full')
        ? { rejectUnauthorized: mode !== 'require' }
        : false;

    if (env.DATABASE_URL) {
        const url = new URL(env.DATABASE_URL);
        const mode = url.searchParams.get('sslmode') || env.DB_SSLMODE || 'prefer';
        url.searchParams.delete('sslmode');

        return { connectionString: url.toString(), ssl: ssl(mode), max: 4 };
    }

    return {
        host: env.DB_HOST || '127.0.0.1',
        port: parseInt(env.DB_PORT || '5432', 10),
        database: env.DB_NAME || 'streamorg',
        user: env.DB_USER || 'streamorg',
        password: env.DB_PASSWORD || '',
        ssl: ssl(env.DB_SSLMODE || 'prefer'),
        max: 4,
    };
}
