/**
 * StreamOrg chat bot: starts the bot, a dedicated database connection that
 * LISTENs for the app's changes, and a tiny health endpoint for the
 * container (GET /health on BOT_HEALTH_PORT).
 */
import { createServer } from 'node:http';
import pg from 'pg';
import { loadConfig } from './config.js';
import { makeCrypto } from './crypto.js';
import { Store } from './store.js';
import { Bot } from './bot.js';

const config = loadConfig();
const pool = new pg.Pool(config.database);
const store = new Store(pool, makeCrypto(config.appKey));

pool.on('error', (e) => console.error('database:', e.message));

/** Keeps a connection LISTENing on streamorg_bot, reconnecting when it drops. */
async function listen(onChange) {
    let timer = null;
    const debounced = () => {
        clearTimeout(timer);
        timer = setTimeout(onChange, 300);
    };

    const connect = async () => {
        const client = new pg.Client(config.database);

        client.on('notification', debounced);
        client.on('error', () => {});
        client.on('end', () => setTimeout(() => connect().catch(() => {}), 5000));

        await client.connect();
        await client.query('LISTEN streamorg_bot');
    };

    await connect();
}

const bot = new Bot({ store, config, listen });

const health = createServer((req, res) => {
    const ok = req.url === '/health' && bot.healthy();
    res.writeHead(ok ? 200 : 503, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok, state: bot.state, detail: bot.detail, version: config.version }));
});

health.listen(config.healthPort, () => console.log(`StreamOrg bot ${config.version}: health on :${config.healthPort}`));

await bot.start();
console.log(`StreamOrg bot: ${bot.state} — ${bot.detail}`);

const shutdown = async (signal) => {
    console.log(`StreamOrg bot: ${signal}, stopping`);
    health.close();
    await bot.stop().catch(() => {});
    await pool.end().catch(() => {});
    process.exit(0);
};

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
