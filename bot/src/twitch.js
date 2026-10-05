/**
 * Twitch's OAuth endpoints for the bot account's user token: refreshing it
 * before it expires, and the hourly validation Twitch asks of every app
 * that keeps a user token.
 */
const ID = 'https://id.twitch.tv/oauth2';

/**
 * @returns {Promise<{accessToken: string, refreshToken: string, expiresIn: number}|{invalid: true}|null>}
 *          invalid when Twitch rejected the refresh token for good, null on a passing failure
 */
export async function refreshToken(app, token, fetchImpl = fetch) {
    let response;

    try {
        response = await fetchImpl(`${ID}/token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                grant_type: 'refresh_token',
                refresh_token: token,
                client_id: app.clientId,
                client_secret: app.clientSecret,
            }),
        });
    } catch {
        return null;
    }

    if (response.status === 400 || response.status === 401) return { invalid: true };
    if (!response.ok) return null;

    const data = await response.json();

    return {
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        expiresIn: Number(data.expires_in || 3600),
    };
}

/** @returns {Promise<{login: string, userId: string, expiresIn: number}|false|null>} false when the token is no longer valid, null when Twitch could not be asked */
export async function validateToken(token, fetchImpl = fetch) {
    let response;

    try {
        response = await fetchImpl(`${ID}/validate`, { headers: { Authorization: `OAuth ${token}` } });
    } catch {
        return null;
    }

    if (response.status === 401) return false;
    if (!response.ok) return null;

    const data = await response.json();

    return { login: data.login, userId: data.user_id, expiresIn: Number(data.expires_in || 0) };
}
