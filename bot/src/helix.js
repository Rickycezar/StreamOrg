/**
 * Twitch's Helix API for the bot: calls with the app access token (from
 * the client id and secret in API settings, renewed when it lapses) and
 * calls with a user token handed in by the caller.
 */
const API = 'https://api.twitch.tv/helix/';
const TOKEN = 'https://id.twitch.tv/oauth2/token';

/** {user_id: ['1', '2'], first: 100} -> "user_id=1&user_id=2&first=100": Helix repeats keys for lists. */
export function queryString(query = {}) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null) continue;
        for (const item of Array.isArray(value) ? value : [value]) params.append(key, String(item));
    }

    return params.toString();
}

export class Helix {
    /** @param {{app: () => Promise<{clientId: string, clientSecret: string}|null>, fetchImpl?: typeof fetch}} options */
    constructor({ app, fetchImpl = fetch }) {
        this.appCredentials = app;
        this.fetch = fetchImpl;
        this.token = null;
        this.clientId = null;
    }

    /** The app access token, fetched again when it is about to expire or was refused. */
    async appToken(force = false) {
        if (!force && this.token && this.token.expiresAt - Date.now() > 60_000) return this.token.value;

        const app = await this.appCredentials();
        if (!app) throw new Error('Twitch is not configured in API settings');

        const response = await this.fetch(TOKEN, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ client_id: app.clientId, client_secret: app.clientSecret, grant_type: 'client_credentials' }),
        });

        if (!response.ok) throw new Error(`Twitch refused the app credentials (${response.status})`);

        const data = await response.json();
        this.clientId = app.clientId;
        this.token = { value: data.access_token, expiresAt: Date.now() + Number(data.expires_in || 3600) * 1000 };

        return this.token.value;
    }

    /** @returns {Promise<{status: number, data: any}>} a call with the app token, retried once with a new token on 401 */
    async app(method, path, { query, body } = {}) {
        for (const force of [false, true]) {
            const token = await this.appToken(force);
            const result = await this.call(method, path, token, query, body);
            if (result.status !== 401) return result;
        }

        return { status: 401, data: null };
    }

    /** @returns {Promise<{status: number, data: any}>} a call with a user token */
    async user(token, method, path, { query, body } = {}) {
        if (!this.clientId) await this.appToken();
        return this.call(method, path, token, query, body);
    }

    async call(method, path, token, query, body) {
        const qs = queryString(query);
        let response;

        try {
            response = await this.fetch(API + path + (qs ? `?${qs}` : ''), {
                method,
                headers: {
                    'Client-Id': this.clientId,
                    Authorization: `Bearer ${token}`,
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            return { status: 0, data: { message: e.message } };
        }

        const text = await response.text();
        let data = null;

        try { data = text ? JSON.parse(text) : null; } catch { data = { message: text }; }

        return { status: response.status, data };
    }
}
