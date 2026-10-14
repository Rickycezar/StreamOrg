/**
 * What a channel event from Twitch becomes in channel_events: chat
 * notifications (subscriptions, resubs, gifts, upgrades, raids, bits
 * badges, charity, watch streaks), cheers in chat messages, and follows.
 * Gifts that are part of a community gift are left out (the community
 * gift itself says how many); anything else returns null. The text of a
 * message (a resub's, a cheer's) is never kept, as the privacy policy
 * promises.
 */

/** "1000" -> "1"; Prime stays "prime". */
function tier(value, prime = false) {
    if (prime) return 'prime';
    const n = Number(value);
    return Number.isFinite(n) && n >= 1000 ? String(Math.round(n / 1000)) : (value ? String(value) : null);
}

function actor(event) {
    if (event.chatter_is_anonymous) return { actorId: null, actorLogin: null, actorName: null };
    return { actorId: event.chatter_user_id || null, actorLogin: event.chatter_user_login || null, actorName: event.chatter_user_name || null };
}

/** @returns {?object} the event to record for a channel.chat.notification, or null */
export function fromNotice(event) {
    const type = String(event.notice_type || '').replace(/^shared_chat_/, '');
    const who = actor(event);

    switch (type) {
        case 'sub': {
            const sub = event.sub || {};
            return { type: 'sub', ...who, tier: tier(sub.sub_tier, sub.is_prime), amount: sub.duration_months || null };
        }
        case 'resub': {
            const sub = event.resub || {};
            return {
                type: 'resub', ...who, tier: tier(sub.sub_tier, sub.is_prime), amount: sub.cumulative_months || null,
                data: { streak: sub.streak_months || null, gift: Boolean(sub.is_gift), gifter: sub.gifter_user_name || null },
            };
        }
        case 'sub_gift': {
            const gift = event.sub_gift || {};
            if (gift.community_gift_id) return null;
            return {
                type: 'gift_sub', ...who, tier: tier(gift.sub_tier), amount: gift.duration_months || 1,
                data: { recipient: gift.recipient_user_name || gift.recipient_user_login || null, total: gift.cumulative_total || null },
            };
        }
        case 'community_sub_gift': {
            const gift = event.community_sub_gift || {};
            return { type: 'community_gift', ...who, tier: tier(gift.sub_tier), amount: gift.total || null, data: { total: gift.cumulative_total || null } };
        }
        case 'gift_paid_upgrade':
            return { type: 'gift_upgrade', ...who, data: { gifter: event.gift_paid_upgrade?.gifter_user_name || null } };
        case 'prime_paid_upgrade':
            return { type: 'prime_upgrade', ...who, tier: tier(event.prime_paid_upgrade?.sub_tier) };
        case 'raid':
            return {
                type: 'raid',
                actorId: event.raid?.user_id || who.actorId,
                actorLogin: event.raid?.user_login || who.actorLogin,
                actorName: event.raid?.user_name || who.actorName,
                amount: event.raid?.viewer_count ?? null,
            };
        case 'bits_badge_tier':
            return { type: 'bits_badge', ...who, amount: event.bits_badge_tier?.tier ?? null };
        case 'charity_donation': {
            const amount = event.charity_donation?.amount || {};
            const value = Number(amount.value) / 10 ** (Number(amount.decimal_places) || 0);
            return { type: 'charity', ...who, amount: Number.isFinite(value) ? Math.round(value) : null, data: { currency: amount.currency || null, charity: event.charity_donation?.charity_name || null } };
        }
        case 'watch_streak':
            return { type: 'watch_streak', ...who, amount: Number(event.watch_streak?.streak_count) || null };
        default:
            return null;
    }
}

/** @returns {?object} a cheer in a chat message (the bits, not the text), or null */
export function fromChat(event) {
    const bits = Number(event.cheer?.bits) || 0;
    if (bits <= 0) return null;

    return { type: 'cheer', ...actor(event), amount: bits };
}

/** @returns {object} a channel.follow event */
export function fromFollow(event) {
    return {
        type: 'follow',
        actorId: event.user_id || null,
        actorLogin: event.user_login || null,
        actorName: event.user_name || null,
        at: event.followed_at || null,
    };
}
