/**
 * BossDraw simulation
 *
 * Runs a whole boss-battle giveaway draw from a list of entrants, a set of
 * parameters and a seed, and returns the timeline of events plus the result.
 * It touches no DOM, so the same file runs on the draw page and in Node (to
 * replay or verify a result from its seed).
 *
 * Rules: every player has a few hits of life and is either melee or ranged.
 * Every tick each living player chips the boss's HP. Every few seconds the
 * boss attacks one side (melee or ranged, preferring the bigger one), hitting part of its living players
 * for one hit each. An attack never leaves fewer than `winners` players
 * untouched. With minWipesSide 'no', the minimum number of targets never hits
 * every member of a side while the other side still has players (the ratio
 * alone still can). When the boss dies the survivors heal to full and a
 * stronger boss spawns (extra hearts from the rules come back only with
 * extraRegen; otherwise survivors heal up to the normal hearts). The last `winners` players standing win, ranked by
 * the hearts they kept (ties drawn): the first gets the first prize.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/** Default parameters; every one of them can be overridden per draw. */
const DRAW_DEFAULTS = {
    winners: 1,
    hitsPerPlayer: 2,
    rangedShare: 0.5,
    tickMs: 1000,
    playerDamageMin: 8,
    playerDamageMax: 12,
    bossHp: 3000,
    bossHpFrom: 'fixed',
    bossHpPerEntrant: 100,
    bossHpGrowth: 1.2,
    firstAttackMs: 3000,
    attackMinMs: 2000,
    attackMaxMs: 4500,
    bossFocus: 1,
    targetRatio: 0.5,
    targetBase: 'side',
    minTargets: 3,
    minWipesSide: 'yes',
    respawnMs: 3000,
    maxDurationMs: 900000,
    /** Whether extra hearts (bonus over hitsPerPlayer) come back when a boss falls. */
    extraRegen: false
};

/**
 * Small seeded random generator (mulberry32): the same seed always gives the
 * same sequence, which is what makes a draw replayable.
 *
 * @param {number} seed - Unsigned 32-bit seed.
 * @returns {function(): number} Returns floats in [0, 1).
 */
function drawRng(seed) {
    let a = seed >>> 0;
    return function () {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * Picks `count` random items from a list without repeating any.
 *
 * @param {Array} list - Items to pick from (left untouched).
 * @param {number} count - How many to pick.
 * @param {function(): number} rng - Random generator.
 * @returns {Array} The picked items.
 */
function drawPick(list, count, rng) {
    const pool = list.slice();
    for (let i = pool.length - 1; i > 0; i--) {
        const j = Math.floor(rng() * (i + 1));
        [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    return pool.slice(0, Math.max(0, count));
}

/**
 * Chooses which side the boss attacks, preferring the side with more players
 * alive: each side's chance is its living count raised to `focus`. 0 = every
 * side with someone alive is equally likely, 1 = in proportion to the head
 * count, higher = the bigger side even more often. An empty side is never
 * chosen.
 *
 * @param {Array<{kind: string}>} alive - Living players.
 * @param {number} focus - How strongly the boss prefers the bigger side.
 * @param {function(): number} rng - Random generator.
 * @returns {string} The chosen side's kind.
 */
function drawPickSide(alive, focus, rng) {
    const counts = {};
    for (const p of alive) {
        counts[p.kind] = (counts[p.kind] || 0) + 1;
    }
    const kinds = Object.keys(counts).sort();
    const weights = kinds.map(k => Math.pow(counts[k], Math.max(0, focus)));
    let roll = rng() * weights.reduce((a, b) => a + b, 0);
    for (let i = 0; i < kinds.length; i++) {
        roll -= weights[i];
        if (roll < 0) {
            return kinds[i];
        }
    }
    return kinds[kinds.length - 1];
}

/**
 * Runs a draw.
 *
 * Event types in the timeline (each has `t`, ms since start):
 * - start: { cycle, bossHp, bossMaxHp }
 * - chip: { damage, bossHp }
 * - attack: { kind, hits: [{ id, hp }], spared: [id], deaths: [id] }
 * - bossDown: { cycle, healed: [id] }
 * - bossSpawn: { cycle, bossHp, bossMaxHp }
 * - end: { winners: [id], reason }
 *
 * @param {Array<{id: string, name: string, color?: string, kind?: string, picked?: boolean}>} entrants -
 *     Who entered the giveaway. `kind` ('melee' or 'ranged') fixes a side, as
 *     the entry phase does; entrants without one are split at random by
 *     rangedShare. `color` (Twitch name colour) and `picked` (chose the side
 *     themselves) and `hero` (the sprite shown) are only passed through to
 *     the result.
 * @param {object} [overrides] - Parameters to change from DRAW_DEFAULTS.
 * @param {number} [seed] - Seed; a random one is chosen when missing.
 * @returns {object} { seed, params, players, events, winners, eliminated, cycles, durationMs }
 */
function simulateDraw(entrants, overrides = {}, seed) {
    const params = Object.assign({}, DRAW_DEFAULTS, overrides);
    if (seed === undefined || seed === null || seed === '') {
        seed = Math.floor(Math.random() * 4294967296);
    }
    seed = Number(seed) >>> 0;
    const rng = drawRng(seed);
    const between = (min, max) => min + rng() * (max - min);
    const intBetween = (min, max) => Math.floor(between(min, max + 1));

    const hasSide = (e) => e.kind === 'melee' || e.kind === 'ranged';
    const free = entrants.filter(e => !hasSide(e));
    const fixedRanged = entrants.filter(e => e.kind === 'ranged').length;
    const rangedCount = Math.min(free.length, Math.max(0, Math.round(entrants.length * params.rangedShare) - fixedRanged));
    const rangedIds = new Set(drawPick(free, rangedCount, rng).map(e => e.id));
    const players = entrants.map(e => ({
        id: e.id,
        name: e.name,
        color: e.color || null,
        picked: !!e.picked,
        hero: e.hero || null,
        kind: hasSide(e) ? e.kind : (rangedIds.has(e.id) ? 'ranged' : 'melee'),
        bonus: Math.max(1 - params.hitsPerPlayer, Math.floor(Number(e.bonus) || 0)),
        hp: Math.max(1, params.hitsPerPlayer + Math.floor(Number(e.bonus) || 0))
    }));

    const events = [];
    const eliminated = [];
    const living = () => players.filter(p => p.hp > 0);

    let cycle = 1;
    const bossHpFor = (n) => {
        const first = params.bossHpFrom === 'alive' ? living().length * params.bossHpPerEntrant
            : params.bossHpFrom === 'entrants' ? players.length * params.bossHpPerEntrant
            : params.bossHp;
        return Math.max(1, Math.round(first * Math.pow(params.bossHpGrowth, n - 1)));
    };
    let bossMaxHp = bossHpFor(cycle);
    let bossHp = bossMaxHp;
    let t = 0;
    events.push({ t, type: 'start', cycle, bossHp, bossMaxHp });

    const finish = (reason) => {
        const winners = living()
            .map(p => ({ p, roll: rng() }))
            .sort((a, b) => b.p.hp - a.p.hp || a.roll - b.roll)
            .map(x => x.p.id);
        events.push({ t, type: 'end', winners, reason });
        return {
            seed,
            params,
            players: players.map(p => ({ id: p.id, name: p.name, color: p.color, picked: p.picked, hero: p.hero, kind: p.kind, bonus: p.bonus })),
            events,
            winners,
            eliminated,
            cycles: cycle,
            durationMs: t
        };
    };

    if (living().length <= params.winners) {
        return finish('few-entrants');
    }

    let nextTick = params.tickMs;
    let nextAttack = params.firstAttackMs;

    while (true) {
        t = Math.min(nextTick, nextAttack);
        if (t > params.maxDurationMs) {
            t = params.maxDurationMs;
            return finish('time-limit');
        }

        if (nextTick <= nextAttack) {
            nextTick += params.tickMs;
            let damage = 0;
            for (const p of living()) {
                damage += intBetween(params.playerDamageMin, params.playerDamageMax);
            }
            bossHp = Math.max(0, bossHp - damage);
            events.push({ t, type: 'chip', damage, bossHp });

            if (bossHp === 0) {
                const healed = living().map(p => p.id);
                for (const p of living()) {
                    const full = params.hitsPerPlayer + p.bonus;
                    p.hp = params.extraRegen ? full : Math.max(p.hp, Math.min(params.hitsPerPlayer, full));
                }
                events.push({ t, type: 'bossDown', cycle, healed });
                cycle++;
                const spawnAt = t + params.respawnMs;
                bossMaxHp = bossHpFor(cycle);
                bossHp = bossMaxHp;
                events.push({ t: spawnAt, type: 'bossSpawn', cycle, bossHp, bossMaxHp });
                nextTick = spawnAt + params.tickMs;
                nextAttack = spawnAt + params.firstAttackMs;
            }
            continue;
        }

        nextAttack = t + between(params.attackMinMs, params.attackMaxMs);
        const kind = drawPickSide(living(), params.bossFocus, rng);
        const side = living().filter(p => p.kind === kind);

        const alive = living();
        const base = params.targetBase === 'alive' ? alive.length
            : params.targetBase === 'entrants' ? players.length
            : side.length;
        let count = Math.max(params.minTargets, Math.floor(base * params.targetRatio));
        count = Math.min(count, side.length);
        if (params.minWipesSide === 'no' && side.length > 1 && side.length < alive.length) {
            count = Math.min(count, Math.max(Math.floor(base * params.targetRatio), side.length - 1));
        }
        let targets = drawPick(side, count, rng);

        const spared = [];
        const untouched = alive.length - targets.length;
        if (untouched < params.winners) {
            const spare = drawPick(targets, params.winners - untouched, rng);
            for (const p of spare) {
                spared.push(p.id);
            }
            targets = targets.filter(p => !spared.includes(p.id));
        }

        const hits = [];
        const deaths = [];
        for (const p of targets) {
            p.hp--;
            hits.push({ id: p.id, hp: p.hp });
            if (p.hp === 0) {
                deaths.push(p.id);
                eliminated.push({ id: p.id, t, cycle });
            }
        }
        events.push({ t, type: 'attack', kind, hits, spared, deaths });

        if (living().length <= params.winners) {
            return finish('last-standing');
        }
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { DRAW_DEFAULTS, simulateDraw, drawRng };
}
