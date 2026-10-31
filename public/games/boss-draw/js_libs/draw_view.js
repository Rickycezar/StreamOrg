/**
 * BossDraw view
 *
 * Draws the boss-battle draw with SpriteAnimator (img_sprites.js), the
 * sprite data of draw_sprites_data.js and the texts of draw_lang.js: a tiled arena (stone wall, sand floor,
 * decorations), a column of death toasts on the left, the two sides (ranged
 * and melee, each with its head count), the boss of the current wave with its
 * HP bar, and one hero per entrant — picked at random from the side's pool —
 * with their hearts and their Twitch name colour.
 *
 * DrawLobby is the entry phase: entrants walk in as they join, either on the
 * side they picked or, when they did not pick, on the smaller side at that
 * moment; a player who switches changes outfit and walks to the other group.
 * DrawPlayback plays the timeline of simulateDraw() (draw_sim.js). Neither
 * decides who wins: the lobby only records sides, the playback only shows the
 * events.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/** Pixel scale of the whole arena (tiles, heroes, bosses, HP bar). */
const DRAW_SCALE = DRAW_DATA.scale;

/** Width of a player's slot and height of the box their hero stands in, in screen pixels. */
const DRAW_PLAYER_W = 124;

/** Height of a player's slot (hearts, hero box, name, row gap), and the zooms the armies may use, largest first. */
const DRAW_SLOT_H = 126;
const DRAW_ZOOMS = [1, 0.94, 0.88, 0.82, 0.77, 0.72, 0.67, 0.63, 0.59, 0.55, 0.51, 0.47, 0.43, 0.4, 0.36, 0.33, 0.3];
const DRAW_CLIP_H = 84;

/** How long before a boss attack its target side blinks red, in battle time (ms). */
const DRAW_WARN_MS = 1500;

/** Pixel scale of the winner on the final screen. */
const DRAW_CHAMPION_SCALE = 6;

/**
 * Builds a SpriteAnimator for a character of the sprite data. Each named
 * animation (one sheet row, or several played one after the other) becomes
 * an animation of the animator, with its own frame durations;
 * animator.lastFrame tells the last frame of each (to freeze a death pose).
 *
 * @param {HTMLElement} holder - Element that receives the sprite.
 * @param {object} def - { url, w, h, anims: name → segment or [segments] }.
 * @param {number} [scale] - Pixel scale.
 * @returns {SpriteAnimator}
 */
function drawCharacter(holder, def, scale = DRAW_SCALE) {
    const frames = [];
    const sequences = {};
    for (const [name, anim] of Object.entries(def.anims)) {
        const segments = Array.isArray(anim) ? anim : [anim];
        const sequence = [];
        for (const seg of segments) {
            for (let c = 0; c < seg.count; c++) {
                frames.push({ x: c * def.w, y: seg.row * def.h, w: def.w, h: def.h });
                sequence.push({ frameIndex: frames.length - 1, duration: seg.durations[c] || 100 });
            }
        }
        sequences[name] = { sequence, loop: segments.some(seg => seg.loop) };
    }
    const animator = new SpriteAnimator(holder, def.url, { w: def.w, h: def.h }, frames, scale);
    animator.lastFrame = {};
    for (const [name, { sequence, loop }] of Object.entries(sequences)) {
        animator.defineAnimation(name, sequence, loop);
        animator.lastFrame[name] = sequence[sequence.length - 1].frameIndex;
    }
    return animator;
}

/** Starts loading every hero, boss and arena image as the page opens, so none pops in late on stream. */
(function drawPreload() {
    if (typeof Image === 'undefined') {
        return;
    }
    const urls = new Set([DRAW_DATA.background]);
    for (const group of [DRAW_DATA.heroes, DRAW_DATA.bosses, DRAW_DATA.pieces, DRAW_DATA.effects, DRAW_DATA.showdownFx]) {
        for (const item of Object.values(group)) {
            urls.add(item.url);
        }
    }
    drawPreload.images = [...urls].map((url) => {
        const img = new Image();
        img.src = url;
        return img;
    });
})();

/**
 * A random hero for a side, different from `except` when the pool allows.
 *
 * @param {string} kind - 'melee' or 'ranged'.
 * @param {string} [except] - Hero to avoid (the one being taken off).
 * @returns {string} Hero id.
 */
function drawPickHero(kind, except) {
    const pool = DRAW_DATA.pools[kind] || DRAW_DATA.pools.melee;
    const choices = pool.length > 1 ? pool.filter(h => h !== except) : pool;
    return choices[Math.floor(Math.random() * choices.length)];
}

/**
 * The boss of a wave: the bosses take turns, and each round of turns after
 * the first is tinted so a returning boss looks like a stronger variant.
 *
 * @param {number} cycle - Wave number, from 1.
 * @returns {{id: string, tint: number}}
 */
function drawBossFor(cycle) {
    const order = DRAW_DATA.bossCycle;
    return { id: order[(cycle - 1) % order.length], tint: Math.floor((cycle - 1) / order.length) };
}

/**
 * A boss's name: its own, or the streamer's custom name numbered by wave
 * ("Name (1)", "Name (2)"…).
 *
 * @param {string} id - Boss id.
 * @param {number} cycle - Wave number.
 * @param {string} [custom] - Custom name.
 * @returns {string}
 */
function drawBossName(id, cycle, custom) {
    return custom ? `${custom} (${cycle})` : drawT(`boss.${id}`);
}

/**
 * The boss label over the HP bar.
 *
 * @param {string} id - Boss id.
 * @param {number} cycle - Wave number.
 * @param {string} [custom] - Custom name.
 * @returns {string}
 */
function drawBossLabel(id, cycle, custom) {
    return custom ? drawBossName(id, cycle, custom) : drawT('boss.label', { n: cycle, name: drawBossName(id, cycle) });
}

/** Most hearts drawn one by one; above it a player shows one heart and "hp/max". */
const DRAW_HEART_ICONS = 5;

/** Side labels, in the order the sides are drawn (left to right). */
const DRAW_SIDES = [['ranged'], ['melee']];

/**
 * How much an element is scaled on screen (the stage is scaled to fit its
 * frame): its on-screen width over its layout width.
 *
 * @param {HTMLElement} el
 * @returns {number}
 */
function drawScreenScale(el) {
    const width = el.offsetWidth;
    return width ? el.getBoundingClientRect().width / width : 1;
}

/** Twitch's colours for users who never chose one. */
const DRAW_TWITCH_COLORS = ['#FF0000', '#0000FF', '#008000', '#B22222', '#FF7F50', '#9ACD32', '#FF4500',
    '#2E8B57', '#DAA520', '#D2691E', '#5F9EA0', '#1E90FF', '#FF69B4', '#8A2BE2', '#00FF7F'];

/**
 * A Twitch name colour that reads well on the dark arena: the user's colour
 * (or a default one picked from the name, as Twitch does for users without
 * one) mixed with white until it is light enough.
 *
 * @param {string} name - Display name.
 * @param {string|null} color - Twitch colour (#RRGGBB), if any.
 * @returns {string} CSS colour.
 */
function drawNameColor(name, color) {
    let hex = /^#?[0-9a-f]{6}$/i.test(color || '') ? color.replace('#', '') : null;
    if (!hex) {
        let hash = 0;
        for (const ch of name.toLowerCase()) {
            hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
        }
        hex = DRAW_TWITCH_COLORS[hash % DRAW_TWITCH_COLORS.length].slice(1);
    }
    let rgb = [0, 2, 4].map(i => parseInt(hex.slice(i, i + 2), 16));
    const luminance = (c) => {
        const [r, g, b] = c.map(v => {
            const s = v / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    for (let i = 0; i < 10 && luminance(rgb) < 0.2; i++) {
        rgb = rgb.map(v => Math.round(v + (255 - v) * 0.2));
    }
    return `rgb(${rgb.join(',')})`;
}

/**
 * Turns a pixel map into an SVG data URL (one rect per filled pixel).
 *
 * @param {string[]} rows - Pixel rows; any character other than '.' is a key of `colors`.
 * @param {Object<string, string>} colors - Colour of each character.
 * @returns {string}
 */
function drawPixelIcon(rows, colors) {
    const w = rows[0].length;
    const h = rows.length;
    let rects = '';
    rows.forEach((row, y) => {
        [...row].forEach((ch, x) => {
            if (ch !== '.') {
                rects += `<rect x="${x}" y="${y}" width="1" height="1" fill="${colors[ch]}"/>`;
            }
        });
    });
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" shape-rendering="crispEdges">${rects}</svg>`;
    return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
}

/** Pixel icons drawn in code until real sprites arrive (see SPRITES_NEEDED.md). */
const DRAW_ICONS = {
    heart: drawPixelIcon(['.##.##.', '#######', '#######', '.#####.', '..###..', '...#...'], { '#': '#e52521' }),
    heartEmpty: drawPixelIcon(['.##.##.', '#..#..#', '#.....#', '.#...#.', '..#.#..', '...#...'], { '#': '#6b2a2e' }),
    skull: drawPixelIcon(['.#####.', '#######', '#..#..#', '#######', '.##.##.', '.#####.', '.#.#.#.'], { '#': '#f1ede4' })
};

/**
 * Builds a DOM element.
 *
 * @param {string} tag - Tag name.
 * @param {string} [className] - Classes.
 * @param {string} [text] - Text content (never HTML).
 * @returns {HTMLElement}
 */
function drawEl(tag, className, text) {
    const el = document.createElement(tag);
    if (className) {
        el.className = className;
    }
    if (text !== undefined) {
        el.textContent = text;
    }
    return el;
}

/**
 * The arena shared by the lobby and the playback: toasts, sides, boss, banner.
 */
class DrawArena {
    /**
     * @param {HTMLElement} root - Element that receives the arena (emptied first).
     */
    constructor(root) {
        this.root = root;
        this.animators = [];
        root.textContent = '';
        this.el = drawEl('div', 'draw-arena');
        this.el.style.setProperty('--icon-heart', DRAW_ICONS.heart);
        this.el.style.setProperty('--icon-heart-empty', DRAW_ICONS.heartEmpty);
        this.el.style.setProperty('--icon-skull', DRAW_ICONS.skull);
        this._buildScenery();
        this.toasts = drawEl('div', 'draw-toasts');
        this.army = drawEl('div', 'draw-army');
        this.sides = {};
        for (const [kind] of DRAW_SIDES) {
            const side = drawEl('div', `draw-side draw-side-${kind}`);
            const head = drawEl('div', 'draw-side-head');
            const name = drawEl('span', 'draw-side-name', drawT(`side.${kind}`));
            const count = drawEl('span', 'draw-side-count', '0');
            head.append(name, count);
            const body = drawEl('div', 'draw-side-body');
            side.append(head, body);
            this.army.appendChild(side);
            this.sides[kind] = { el: side, body, count };
        }

        this.bossZone = drawEl('div', 'draw-boss-zone');
        this.bossLabel = drawEl('div', 'draw-boss-label');
        const bar = this._buildHpBar();
        this.bossBody = drawEl('div', 'draw-boss');
        this.bossHolder = drawEl('div', 'draw-sprite');
        this.bossBody.appendChild(this.bossHolder);
        this.bossZone.append(this.bossLabel, bar, this.bossBody);
        this.boss = null;
        this.setBoss(drawBossFor(1));

        this.banner = drawEl('div', 'draw-banner');
        this.el.append(this.toasts, this.army, this.bossZone, this.banner);
        root.appendChild(this.el);
        this.zoom = 1;
    }

    /**
     * Creates a player's hero and puts it at the end of its side.
     *
     * @param {{id: string, name: string, color?: string, kind: string, hero?: string, bonus?: number}} p - Player; a hero is
     *     picked for the side when missing; bonus adds hearts (subscriber rules).
     * @param {number} hitsPerPlayer - Hearts everyone has.
     * @returns {object} Player state: el, clip, hearts, animator, hero, kind, hp, dead.
     */
    addPlayer(p, hitsPerPlayer) {
        const el = drawEl('div', `draw-player draw-${p.kind}`);
        const clip = drawEl('div', 'draw-clip');
        const holder = drawEl('div', 'draw-sprite');
        clip.appendChild(holder);
        const hearts = drawEl('div', 'draw-hearts');
        const name = drawEl('div', 'draw-name', p.name);
        const color = drawNameColor(p.name, p.color);
        name.style.color = color;
        el.append(hearts, clip, name);
        this.sides[p.kind].body.appendChild(el);

        const max = Math.max(1, hitsPerPlayer + Math.floor(Number(p.bonus) || 0));
        const state = { id: p.id, name: p.name, color, el, clip, holder, hearts, animator: null, kind: p.kind,
            hp: max, max, bonus: max - hitsPerPlayer, dead: false };
        this.dressPlayer(state, DRAW_DATA.heroes[p.hero] ? p.hero : drawPickHero(p.kind));
        this.renderHearts(state);
        return state;
    }

    /**
     * Puts a hero on a player: the sprite stands with the feet of its idle
     * pose at the bottom centre of the player's box, mirrored when the art
     * faces left (players face the boss, on the right).
     *
     * @param {object} state - Player state.
     * @param {string} heroId - Hero of DRAW_DATA.heroes.
     */
    dressPlayer(state, heroId) {
        const def = DRAW_DATA.heroes[heroId];
        if (state.animator) {
            state.animator.destroy();
            this.animators = this.animators.filter(a => a !== state.animator);
        }
        state.holder.textContent = '';
        const [bx, by, bw, bh] = def.anims.idle.box || [0, 0, def.w, def.h];
        const flip = def.faces === 'left';
        const centre = flip ? def.w - (bx + bw / 2) : bx + bw / 2;
        Object.assign(state.holder.style, {
            left: `${Math.round(DRAW_PLAYER_W / 2 - centre * DRAW_SCALE)}px`,
            top: `${Math.round(DRAW_CLIP_H - (by + bh) * DRAW_SCALE)}px`,
            width: `${def.w * DRAW_SCALE}px`,
            height: `${def.h * DRAW_SCALE}px`
        });
        state.holder.classList.toggle('draw-flip', flip);
        state.animator = drawCharacter(state.holder, def);
        state.animator.playAnimation('idle');
        this.animators.push(state.animator);
        state.hero = heroId;
        state.def = def;
    }

    /**
     * Shows the boss of a wave (swapping the sprite when the boss changes).
     *
     * @param {{id: string, tint: number}} which - From drawBossFor().
     */
    setBoss(which) {
        const def = DRAW_DATA.bosses[which.id];
        if (this.bossId !== which.id) {
            if (this.boss) {
                this.boss.destroy();
                this.animators = this.animators.filter(a => a !== this.boss);
            }
            this.bossHolder.textContent = '';
            const idle = Array.isArray(def.anims.idle) ? def.anims.idle[0] : def.anims.idle;
            const [bx, by, bw, bh] = idle.box || [0, 0, def.w, def.h];
            Object.assign(this.bossBody.style, { width: `${bw * DRAW_SCALE}px`, height: `${bh * DRAW_SCALE}px` });
            Object.assign(this.bossHolder.style, {
                left: `${-bx * DRAW_SCALE}px`,
                top: `${-by * DRAW_SCALE}px`,
                width: `${def.w * DRAW_SCALE}px`,
                height: `${def.h * DRAW_SCALE}px`
            });
            this.bossBody.classList.toggle('draw-flip', def.faces === 'right');
            this.boss = drawCharacter(this.bossHolder, def);
            this.boss.playAnimation('idle');
            this.animators.push(this.boss);
            this.bossId = which.id;
            this.bossDef = def;
        }
        this.bossBody.style.filter = which.tint > 0 ? `hue-rotate(${which.tint * 70}deg) saturate(${1 + which.tint * 0.15})` : '';
    }

    /**
     * An element showing an arena piece (a cut image of imagens/arena/) at the arena's scale.
     *
     * @param {string} name - Piece name.
     * @param {string} className - Classes.
     * @param {object} [style] - Extra inline styles.
     * @returns {HTMLElement}
     */
    piece(name, className, style = {}) {
        const p = DRAW_DATA.pieces[name];
        const el = drawEl('div', className);
        Object.assign(el.style, {
            backgroundImage: `url("${p.url}")`,
            backgroundSize: `${p.w * DRAW_SCALE}px ${p.h * DRAW_SCALE}px`,
            width: `${p.w * DRAW_SCALE}px`,
            height: `${p.h * DRAW_SCALE}px`
        }, style);
        return el;
    }

    /** The tiled arena behind everything: stone wall with lanterns, sand floor, gargoyles, rocks and bushes. */
    _buildScenery() {
        const scenery = drawEl('div', 'draw-scenery');
        const wall = this.piece('wall', 'draw-wall', { width: '' });
        const floor = this.piece('sand', 'draw-floor', { width: '', height: '' });
        scenery.append(wall, floor);
        for (const left of ['6%', '34%', '62%', '90%']) {
            scenery.appendChild(this.piece('lantern', 'draw-deco draw-deco-lantern', { left }));
        }
        scenery.appendChild(this.piece('gargoyle', 'draw-deco draw-deco-gargoyle', { left: '1%' }));
        scenery.appendChild(this.piece('gargoyle', 'draw-deco draw-deco-gargoyle draw-flip', { right: '1%' }));
        scenery.appendChild(this.piece('bush_big', 'draw-deco draw-deco-bush', { left: '18%' }));
        scenery.appendChild(this.piece('bush_low', 'draw-deco draw-deco-bush', { left: '74%' }));
        scenery.appendChild(this.piece('rock_big', 'draw-deco draw-deco-rock', { left: '2%' }));
        scenery.appendChild(this.piece('rock_small', 'draw-deco draw-deco-rock', { right: '3%' }));
        this.el.appendChild(scenery);
    }

    /** The boss HP bar from the UI art: gold frame, red fill inside it, horned-skull emblem, HP text. */
    _buildHpBar() {
        const frame = DRAW_DATA.pieces.hp_frame;
        const fill = DRAW_DATA.pieces.hp_fill;
        const bar = this.piece('hp_frame', 'draw-hp');
        const track = drawEl('div', 'draw-hp-track');
        Object.assign(track.style, {
            left: `${((frame.w - fill.w) / 2) * DRAW_SCALE}px`,
            top: `${((frame.h - fill.h) / 2) * DRAW_SCALE}px`,
            width: `${fill.w * DRAW_SCALE}px`,
            height: `${fill.h * DRAW_SCALE}px`
        });
        this.bossFill = this.piece('hp_fill', 'draw-hp-fill', { width: '100%' });
        track.appendChild(this.bossFill);
        const emblem = this.piece('hp_emblem', 'draw-hp-emblem');
        this.bossHpText = drawEl('div', 'draw-hp-text');
        bar.append(track, emblem, this.bossHpText);
        return bar;
    }

    /**
     * Draws a player's hearts as pixel icons.
     *
     * @param {object} state - Player state.
     */
    renderHearts(state) {
        state.hearts.textContent = '';
        const base = state.max - Math.max(0, state.bonus || 0);
        if (state.hp > DRAW_HEART_ICONS) {
            const heart = drawEl('span', 'draw-heart');
            heart.classList.toggle('draw-heart-bonus', state.bonus > 0);
            state.hearts.append(heart, drawEl('span', 'draw-heart-count', `${state.hp}/${state.max}`));
            return;
        }
        for (let i = 0; i < Math.min(state.max, DRAW_HEART_ICONS); i++) {
            const heart = drawEl('span', i < state.hp ? 'draw-heart' : 'draw-heart draw-heart-empty');
            heart.classList.toggle('draw-heart-bonus', i >= base);
            state.hearts.appendChild(heart);
        }
    }

    /**
     * Shows the head count of each side.
     *
     * @param {{melee: number, ranged: number}} counts - Players per side.
     */
    setCounts(counts) {
        for (const [kind] of DRAW_SIDES) {
            this.sides[kind].count.textContent = String(counts[kind] || 0);
        }
    }

    /**
     * Sizes the armies to the sand: each side gets width in proportion to its
     * head count, and the zoom is the largest of DRAW_ZOOMS at which both
     * sides' rows fit the space below the wall.
     *
     * @param {{melee: number, ranged: number}|number} counts - Players per side (or a total, split evenly).
     */
    fitArmy(counts) {
        const n = typeof counts === 'number' ? { melee: Math.ceil(counts / 2), ranged: Math.floor(counts / 2) } : counts;
        for (const [kind] of DRAW_SIDES) {
            this.sides[kind].el.style.flex = `${Math.max(1, n[kind] || 0)} 1 0`;
        }
        const scale = drawScreenScale(this.el);
        const arena = this.el.getBoundingClientRect();
        const armyBox = this.army.getBoundingClientRect();
        const width = armyBox.width / scale;
        const height = (arena.bottom - armyBox.top) / scale - 16;
        const slotH = DRAW_SLOT_H;
        const total = (n.melee || 0) + (n.ranged || 0);
        let zoom = DRAW_ZOOMS[DRAW_ZOOMS.length - 1];
        for (const z of DRAW_ZOOMS) {
            const fits = DRAW_SIDES.every(([kind]) => {
                const count = n[kind] || 0;
                if (!count || !width || !height) {
                    return true;
                }
                const sideW = (width - 24) * (Math.max(1, count) / Math.max(1, total)) / z;
                const perRow = Math.max(1, Math.floor(sideW / DRAW_PLAYER_W));
                return (Math.ceil(count / perRow) * slotH + 22) * z <= height;
            });
            if (fits) {
                zoom = z;
                break;
            }
        }
        this.zoom = zoom;
        this.army.style.zoom = String(zoom);
        this.army.style.setProperty('--army-zoom', String(zoom));
    }

    /**
     * Shows a "💀 name" toast in the left column, fading out after a while.
     *
     * @param {object} state - Player state (name and colour).
     * @param {number} [ms] - How long it stays, in real time.
     */
    deathToast(state, ms = 3500) {
        const toast = drawEl('div', 'draw-toast');
        toast.appendChild(drawEl('span', 'draw-skull'));
        const name = drawEl('span', 'draw-toast-name', state.name);
        name.style.color = state.color;
        toast.appendChild(name);
        toast.style.setProperty('--toast-ms', `${ms}ms`);
        this.toasts.prepend(toast);
        while (this.toasts.children.length > 8) {
            this.toasts.lastElementChild.remove();
        }
        setTimeout(() => toast.remove(), ms);
    }

    /**
     * Shows a short banner over the arena.
     *
     * @param {string} text - Banner text.
     * @param {number} [ms] - How long, in real time.
     */
    showBanner(text, ms = 2200) {
        this.banner.textContent = text;
        this.banner.classList.remove('draw-banner-show');
        void this.banner.offsetWidth;
        this.banner.classList.add('draw-banner-show');
        clearTimeout(this._bannerTimer);
        this._bannerTimer = setTimeout(() => this.banner.classList.remove('draw-banner-show'), ms);
    }

    /** Frees every sprite and empties the root. */
    destroy() {
        clearTimeout(this._bannerTimer);
        for (const a of this.animators) {
            a.destroy();
        }
        this.animators = [];
        this.root.textContent = '';
    }
}

/**
 * The entry phase: entrants join (with or without a side), may switch, and
 * the streamer locks it to start the draw.
 *
 * Players stand on a layer over the army and walk, frame by frame, towards a
 * slot computed like the battle's layout (rows of 80 px, centred), so any
 * number of joins and switches at once just retarget the walkers instead of
 * making them jump.
 */
class DrawLobby {
    /**
     * @param {HTMLElement} root - Element that receives the arena.
     * @param {object} [options]
     * @param {number} [options.hitsPerPlayer] - Hearts to draw.
     * @param {DrawSound} [options.sound] - Sound player; silent without it.
     * @param {Function} [options.onChange] - Called with the counts after every change.
     */
    constructor(root, options = {}) {
        this.arena = new DrawArena(root);
        this.arena.el.classList.add('draw-lobby');
        this.layer = drawEl('div', 'draw-layer');
        this.arena.army.appendChild(this.layer);
        this.hitsPerPlayer = options.hitsPerPlayer || 2;
        this.sound = options.sound || null;
        this.onChange = options.onChange || null;
        this.players = new Map();
        this.kicked = new Set();
        this.kickedNames = new Map();
        this.order = { melee: [], ranged: [] };
        /** Join counter: each new entrant's place in line (kept when they switch sides). */
        this.joinSeq = 0;
        this.walkPxPerSecond = 240;
        this.slotWidth = DRAW_PLAYER_W;
        this.slotHeight = null;
        this._rafId = null;
        this._onResize = () => {
            this.arena.fitArmy(this.counts());
            this._layout();
        };
        window.addEventListener('resize', this._onResize);
        this.arena.bossLabel.textContent = drawBossLabel(this.arena.bossId, 1, options.bossName);
        this.arena.bossHpText.textContent = drawT('boss.waiting');
        this._changed();
    }

    /** Players per side. */
    counts() {
        return { melee: this.order.melee.length, ranged: this.order.ranged.length };
    }

    /**
     * An entrant joins, or, when already in, picks a side.
     *
     * @param {{name: string, login?: string, color?: string, side?: string|null, tier?: number, vip?: boolean, bonus?: number}} entry -
     *     side is 'melee', 'ranged' or empty for no pick; tier (0–3) and vip come from the chat badges; bonus adds hearts.
     * @param {object} [options]
     * @param {boolean} [options.instant] - No walking or sound (bulk adds).
     * @returns {string} 'joined', 'switched', 'same' or 'kicked' (removed this round, cannot rejoin).
     */
    join(entry, options = {}) {
        const id = (entry.login || entry.name).trim().toLowerCase();
        if (this.kicked.has(id)) {
            return 'kicked';
        }
        const side = entry.side === 'melee' || entry.side === 'ranged' ? entry.side : null;
        const existing = this.players.get(id);
        if (existing) {
            if (entry.color) {
                existing.color = drawNameColor(existing.name, entry.color);
                existing.el.querySelector('.draw-name').style.color = existing.color;
                existing.rawColor = entry.color;
            }
            if (!side) {
                return 'same';
            }
            existing.picked = true;
            if (side === existing.kind) {
                return 'same';
            }
            this._switch(existing, side, options.instant);
            return 'switched';
        }

        let kind = side;
        if (!kind) {
            const c = this.counts();
            kind = c.melee === c.ranged ? (Math.random() < 0.5 ? 'melee' : 'ranged') : (c.melee < c.ranged ? 'melee' : 'ranged');
        }
        const state = this.arena.addPlayer({ id, name: entry.name.trim(), color: entry.color, kind, hero: entry.hero, bonus: entry.bonus },
            this.hitsPerPlayer);
        state.picked = !!side;
        state.joined = Number.isFinite(entry.joined) ? entry.joined : ++this.joinSeq;
        this.joinSeq = Math.max(this.joinSeq, state.joined);
        state.tier = Math.max(0, Math.min(3, Math.floor(Number(entry.tier) || 0)));
        state.vip = !!entry.vip;
        state.mod = !!entry.mod;
        state.badges = Object.assign({}, entry.badges || {});
        state.extra = entry.extra || 0;
        state.fromList = !!entry.fromList;
        state.rawColor = entry.color || null;
        state.x = null;
        state.y = null;
        state.moving = false;
        this.layer.appendChild(state.el);
        state.el.addEventListener('click', () => this._clickPlayer(state));
        this.players.set(id, state);
        this.order[kind].push(state);
        this.arena.fitArmy(this.counts());
        this._layout();
        if (options.instant) {
            state.x = state.tx;
            state.y = state.ty;
        }
        else {
            state.x = -this.slotWidth - 20;
            state.y = state.ty;
            if (this.sound) {
                this.sound.play('join');
            }
        }
        this._place(state);
        this._wake();
        this._changed();
        return 'joined';
    }

    /**
     * The entrants in the order they stand on the field, ready for simulateDraw().
     *
     * @returns {Array<{id: string, name: string, color: string|null, kind: string, picked: boolean, hero: string, tier: number,
     *     vip: boolean, bonus: number, joined: number}>}
     */
    entrants() {
        const out = [];
        for (const [kind] of DRAW_SIDES) {
            for (const p of this.order[kind]) {
                out.push({ id: p.id, name: p.name, color: p.rawColor, kind: p.kind, picked: p.picked, hero: p.hero,
                    tier: p.tier, vip: p.vip, bonus: p.bonus, extra: p.extra, fromList: p.fromList, joined: p.joined, badges: p.badges, mod: p.mod });
            }
        }
        return out;
    }

    /**
     * A click on a player opens the kick confirmation over them (Remove,
     * and Cancel under it); clicking the player again closes it.
     *
     * @param {object} state - Player state.
     */
    _clickPlayer(state) {
        if (this.kickOpen === state) {
            this._closeKick();
            return;
        }
        this._closeKick();
        const menu = drawEl('div', 'draw-kick-menu');
        const remove = drawEl('button', 'draw-kick-yes', drawT('player.kick'));
        const cancel = drawEl('button', 'draw-kick-no', drawT('player.kick_cancel'));
        remove.type = 'button';
        cancel.type = 'button';
        remove.addEventListener('click', (e) => {
            e.stopPropagation();
            this._closeKick();
            this.kick(state.id);
        });
        cancel.addEventListener('click', (e) => {
            e.stopPropagation();
            this._closeKick();
        });
        menu.append(remove, cancel);
        state.el.appendChild(menu);
        state.el.classList.add('draw-kick-armed');
        this.kickOpen = state;
        this.kickMenu = menu;
    }

    /** Closes the kick confirmation, if one is open. */
    _closeKick() {
        if (!this.kickOpen) {
            return;
        }
        this.kickMenu.remove();
        this.kickOpen.el.classList.remove('draw-kick-armed');
        this.kickOpen = null;
        this.kickMenu = null;
    }

    /**
     * Lets kicked players join again.
     *
     * @param {string[]} [ids] - Who (everyone kicked when missing).
     */
    unkick(ids) {
        for (const id of ids || [...this.kicked]) {
            this.kicked.delete(id);
            this.kickedNames.delete(id);
        }
        this._changed();
    }

    /**
     * Kicked players, to show and undo.
     *
     * @returns {Array<{id: string, name: string}>}
     */
    kickedList() {
        return [...this.kicked].map(id => ({ id, name: this.kickedNames.get(id) || id }));
    }

    /**
     * Removes a player (in a puff) and keeps them out until the next entry phase.
     *
     * @param {string} id - Player id (lowercase name).
     */
    kick(id) {
        const state = this.players.get(id);
        if (!state) {
            return;
        }
        this.kicked.add(id);
        this.kickedNames.set(id, state.name);
        this.players.delete(id);
        clearTimeout(state.switchTimer);
        this.order[state.kind] = this.order[state.kind].filter(p => p !== state);
        state.el.classList.remove('draw-kick-armed');
        state.el.appendChild(drawEl('div', 'draw-poof'));
        if (this.sound) {
            this.sound.play('switch');
        }
        setTimeout(() => {
            state.animator.destroy();
            this.arena.animators = this.arena.animators.filter(a => a !== state.animator);
            state.el.remove();
        }, 250);
        this.arena.fitArmy(this.counts());
        this._layout();
        this._wake();
        this._changed();
    }

    /** Removes everyone. */
    clear() {
        for (const p of this.players.values()) {
            clearTimeout(p.switchTimer);
            p.animator.destroy();
            p.el.remove();
        }
        this.arena.animators = this.arena.animators.filter(a => a === this.arena.boss);
        this.players.clear();
        this.order = { melee: [], ranged: [] };
        this.arena.fitArmy(0);
        this._layout();
        this._changed();
    }

    /** Frees everything. */
    destroy() {
        cancelAnimationFrame(this._rafId);
        this._rafId = null;
        window.removeEventListener('resize', this._onResize);
        for (const p of this.players.values()) {
            clearTimeout(p.switchTimer);
        }
        this.arena.destroy();
        this.players.clear();
    }

    /** Updates the head counts and tells the page. */
    _changed() {
        const counts = this.counts();
        this.arena.setCounts(counts);
        if (this.onChange) {
            this.onChange(counts);
        }
    }

    /**
     * Changes a player's outfit, then walks them to the other group.
     *
     * @param {object} state - Player state.
     * @param {string} kind - New side.
     * @param {boolean} [instant] - Skip the animation.
     */
    _switch(state, kind, instant) {
        const move = () => {
            this.order[state.kind] = this.order[state.kind].filter(p => p !== state);
            state.kind = kind;
            this.order[kind].push(state);
            this.arena.fitArmy(this.counts());
            this._layout();
            this._wake();
            this._changed();
        };
        const dress = () => {
            state.el.classList.remove('draw-melee', 'draw-ranged');
            state.el.classList.add(`draw-${kind}`);
            this.arena.dressPlayer(state, drawPickHero(kind, state.hero));
            if (state.moving) {
                state.animator.stop().playAnimation('walk');
            }
        };
        clearTimeout(state.switchTimer);
        if (instant) {
            dress();
            move();
            state.x = state.tx;
            state.y = state.ty;
            this._place(state);
            return;
        }
        const poof = drawEl('div', 'draw-poof');
        state.el.appendChild(poof);
        if (this.sound) {
            this.sound.play('switch');
        }
        setTimeout(dress, 180);
        setTimeout(() => poof.remove(), 500);
        state.switchTimer = setTimeout(() => {
            if (this.players.get(state.id) === state && state.kind !== kind) {
                move();
            }
        }, 420);
    }

    /**
     * Works out every player's slot. Each side's body gets the height of its
     * rows, so the sides centre as they do in the battle, then each player's
     * target is its slot measured in the army's own (unzoomed) pixels.
     */
    _layout() {
        if (!this.slotHeight) {
            const sample = this.players.values().next().value;
            if (!sample) {
                for (const [kind] of DRAW_SIDES) {
                    this.arena.sides[kind].body.style.height = '';
                }
                return;
            }
            this.slotHeight = sample.el.offsetHeight + 2;
        }
        this.layer.style.setProperty('--slot-w', `${this.slotWidth}px`);
        this.layer.style.setProperty('--slot-h', `${this.slotHeight}px`);
        const zoom = this.arena.zoom || 1;
        const shapes = {};
        for (const [kind] of DRAW_SIDES) {
            const body = this.arena.sides[kind].body;
            const perRow = Math.max(1, Math.floor(body.clientWidth / this.slotWidth));
            const rows = Math.ceil(this.order[kind].length / perRow);
            body.style.height = `${rows * this.slotHeight}px`;
            shapes[kind] = { body, perRow };
        }
        const armyRect = this.arena.army.getBoundingClientRect();
        const scale = drawScreenScale(this.arena.el);
        for (const [kind] of DRAW_SIDES) {
            const { body, perRow } = shapes[kind];
            const rect = body.getBoundingClientRect();
            const ox = (rect.left - armyRect.left) / scale / zoom;
            const oy = (rect.top - armyRect.top) / scale / zoom;
            const width = body.clientWidth;
            const list = this.order[kind];
            list.forEach((p, i) => {
                const row = Math.floor(i / perRow);
                const inRow = Math.min(perRow, list.length - row * perRow);
                p.tx = ox + (width - inRow * this.slotWidth) / 2 + (i % perRow) * this.slotWidth;
                p.ty = oy + row * this.slotHeight;
                if (p.x === null) {
                    p.x = p.tx;
                    p.y = p.ty;
                }
            });
        }
    }

    /**
     * Draws a player at its current position.
     *
     * @param {object} state - Player state.
     */
    _place(state) {
        state.el.style.transform = `translate(${Math.round(state.x)}px, ${Math.round(state.y)}px)`;
    }

    /** Starts the movement loop if it is not running. */
    _wake() {
        if (this._rafId) {
            return;
        }
        let last = performance.now();
        const frame = (now) => {
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            const step = this.walkPxPerSecond * dt;
            let anyMoving = false;
            for (const p of this.players.values()) {
                const dx = p.tx - p.x;
                const dy = p.ty - p.y;
                const dist = Math.hypot(dx, dy);
                if (dist <= step || dist < 0.5) {
                    if (p.moving) {
                        p.moving = false;
                        p.animator.stop().playAnimation('idle');
                    }
                    p.el.classList.remove('draw-walking', 'draw-facing-left');
                    if (p.x !== p.tx || p.y !== p.ty) {
                        p.x = p.tx;
                        p.y = p.ty;
                        this._place(p);
                    }
                    continue;
                }
                anyMoving = true;
                p.x += dx / dist * step;
                p.y += dy / dist * step;
                this._place(p);
                if (!p.moving) {
                    p.moving = true;
                    p.el.classList.add('draw-walking');
                    p.animator.stop().playAnimation('walk');
                }
                if (Math.abs(dx) > 1) {
                    p.el.classList.toggle('draw-facing-left', dx < 0);
                }
            }
            this._rafId = anyMoving ? requestAnimationFrame(frame) : null;
        };
        this._rafId = requestAnimationFrame(frame);
    }
}

/**
 * Plays one draw result inside a root element.
 */
class DrawPlayback {
    /**
     * @param {HTMLElement} root - Element that receives the arena.
     * @param {object} result - Value returned by simulateDraw().
     * @param {object} [options]
     * @param {number} [options.speed] - Playback speed multiplier.
     * @param {Function} [options.onEnd] - Called with the result when the winners are shown.
     * @param {DrawSound} [options.sound] - Sound player (draw_sound.js); silent without it.
     * @param {{seconds: number}|null} [options.showdown] - Give the winners this long to speak in chat before they get the prize.
     * @param {Function} [options.onShowdown] - Called with the winners' ids when the showdown starts.
     */
    constructor(root, result, options = {}) {
        this.result = result;
        this.speed = options.speed || 1;
        this.onEnd = options.onEnd || null;
        this.sound = options.sound || null;
        this.showdown = options.showdown || null;
        this.bossName = options.bossName || '';
        this.onShowdown = options.onShowdown || null;
        this.inShowdown = false;
        this.clock = 0;
        this.index = 0;
        this.cued = new Set();
        this.warned = new Set();
        this.timers = new Set();
        this.players = new Map();
        this.finished = false;
        this._rafId = null;

        this.arena = new DrawArena(root);
        this.arena.fitArmy({
            melee: result.players.filter(p => p.kind === 'melee').length,
            ranged: result.players.filter(p => p.kind === 'ranged').length
        });
        const counts = { melee: 0, ranged: 0 };
        for (const p of result.players) {
            this.players.set(p.id, this.arena.addPlayer(p, result.params.hitsPerPlayer));
            counts[p.kind]++;
        }
        this.arena.setCounts(counts);
        this.bossBody = this.arena.bossBody;
    }

    /** The current boss's animator (it changes when the next wave brings another boss). */
    get boss() {
        return this.arena.boss;
    }

    /** Starts (or resumes) the playback, with the battle music. */
    play() {
        if (this.sound) {
            this.sound.playMusic();
        }
        let last = performance.now();
        const frame = (now) => {
            if (!document.hidden) {
                this.clock += (now - last) * this.speed;
            }
            last = now;
            this._advance();
            if (!this.finished) {
                this._rafId = requestAnimationFrame(frame);
            }
        };
        this._rafId = requestAnimationFrame(frame);
    }

    /**
     * Changes the playback speed, animations included.
     *
     * @param {number} speed - Multiplier (1 = real time).
     */
    setSpeed(speed) {
        this.speed = speed;
        for (const a of this.arena.animators) {
            a.setPlaybackRate(speed);
        }
    }

    /** Jumps straight to the final state and shows the winners. */
    skip() {
        if (this.finished || this.inShowdown) {
            return;
        }
        this._clearTimers();
        if (this.sound) {
            this.sound.stop();
            this.sound.stopMusic(300);
        }
        const events = this.result.events;
        while (this.index < events.length) {
            this._apply(events[this.index++], true);
        }
    }

    /** Stops everything and frees the sprites. */
    destroy() {
        clearInterval(this._celebrate);
        clearInterval(this._showdownTick);
        clearInterval(this._rumbleTimer);
        this.arena.root.style.transform = '';
        this.arena.el.style.transform = '';
        this.inShowdown = false;
        this.finished = true;
        cancelAnimationFrame(this._rafId);
        this._clearTimers();
        if (this.sound) {
            this.sound.stop();
            this.sound.stopMusic(0);
        }
        this.arena.destroy();
    }

    /**
     * Fires what comes ahead of each boss attack — the target side starts
     * blinking DRAW_WARN_MS before it, the boss winds up just before the blow
     * — then every event that is due.
     */
    _advance() {
        const events = this.result.events;
        for (let i = this.index; i < events.length; i++) {
            const e = events[i];
            if (e.type !== 'attack') {
                continue;
            }
            if (e.t - DRAW_WARN_MS > this.clock) {
                break;
            }
            if (!this.warned.has(i)) {
                this.warned.add(i);
                this._warn(e.kind);
            }
            if (!this.cued.has(i) && e.t - this.arena.bossDef.windupMs[e.kind] <= this.clock) {
                this.cued.add(i);
                this._windup(e.kind);
            }
        }
        while (this.index < events.length && events[this.index].t <= this.clock) {
            this._apply(events[this.index++], false);
        }
    }

    /**
     * Schedules a callback on the playback clock (scaled by the speed).
     *
     * @param {Function} fn - Callback.
     * @param {number} ms - Delay at speed 1.
     */
    _later(fn, ms) {
        const id = setTimeout(() => {
            this.timers.delete(id);
            fn();
        }, ms / this.speed);
        this.timers.add(id);
    }

    /**
     * Plays a sound effect, when there is a sound player.
     *
     * @param {string} name - Sound name (see DRAW_SOUND_DEFAULTS).
     * @param {object} [options] - Passed to DrawSound.play().
     * @param {number} [delay] - Wait before playing, in ms at speed 1.
     */
    _sfx(name, options, delay = 0) {
        if (!this.sound) {
            return;
        }
        if (delay > 0) {
            this._later(() => this.sound.play(name, options), delay);
        }
        else {
            this.sound.play(name, options);
        }
    }

    /** Cancels every pending delayed callback. */
    _clearTimers() {
        for (const id of this.timers) {
            clearTimeout(id);
        }
        this.timers.clear();
    }

    /**
     * Starts the boss's attack animation so the blow lands on the event time.
     *
     * @param {string} kind - 'melee' or 'ranged'.
     */
    _windup(kind) {
        this.boss.stop().playAnimation(kind).playAnimation('idle');
        this._sfx(`windup_${kind}`);
    }

    /**
     * Makes the side about to be hit blink red until the blow lands.
     *
     * @param {string} kind - 'melee' or 'ranged'.
     */
    _warn(kind) {
        for (const [k] of DRAW_SIDES) {
            this.arena.sides[k].el.classList.toggle('draw-warn', k === kind);
        }
    }

    /**
     * Shows one event.
     *
     * @param {object} e - Timeline event.
     * @param {boolean} instant - True when skipping: state only, no effects.
     */
    _apply(e, instant) {
        switch (e.type) {
            case 'start':
            case 'bossSpawn':
                this._setBoss(e.cycle, e.bossHp, e.bossMaxHp);
                if (e.type === 'bossSpawn' && !instant) {
                    this.bossBody.classList.remove('draw-boss-dead');
                    this.boss.stop().playAnimation('spawn').playAnimation('idle');
                    this.arena.showBanner(this.bossName
                        ? drawT('boss.custom_appears', { name: drawBossName(this.arena.bossId, e.cycle, this.bossName) })
                        : drawT('boss.appears', { n: e.cycle, name: drawBossName(this.arena.bossId, e.cycle) }), 2200 / this.speed);
                    this._sfx('spawn');
                }
                break;
            case 'chip':
                this._setBossHp(e.bossHp);
                this._updateMood(instant);
                if (!instant) {
                    this._float(this.arena.bossZone, `-${e.damage}`, 'draw-float-damage');
                    this._chipAnimations();
                    this._chipSounds();
                }
                break;
            case 'attack':
                this._applyAttack(e, instant);
                break;
            case 'bossDown':
                for (const id of e.healed) {
                    const state = this.players.get(id);
                    state.hp = this.result.params.extraRegen ? state.max
                        : Math.max(state.hp, Math.min(state.max, this.result.params.hitsPerPlayer));
                    this.arena.renderHearts(state);
                    if (!instant) {
                        this._flash(state.el, 'draw-healed');
                    }
                }
                if (!instant) {
                    this.boss.stop().playAnimation('hurt').playAnimation('die');
                    this.bossBody.classList.add('draw-boss-dead');
                    this.arena.showBanner(drawT('boss.defeated', { n: e.cycle }), 2200 / this.speed);
                    this._sfx('boss_down');
                    this._sfx('victory', {}, 400);
                    this._sfx('heal', {}, 1100);
                    if (this.sound) {
                        this.sound.cueMusic();
                    }
                }
                this._updateMood(instant);
                break;
            case 'end':
                this._end(e, instant);
                break;
        }
    }

    /**
     * Shows a boss attack landing.
     *
     * @param {object} e - Attack event.
     * @param {boolean} instant - True when skipping.
     */
    _applyAttack(e, instant) {
        for (const [k] of DRAW_SIDES) {
            this.arena.sides[k].el.classList.remove('draw-warn');
        }
        if (!instant && e.kind === 'melee') {
            this._flash(this.arena.el, 'draw-shake');
        }
        if (!instant && e.hits.length) {
            this._sfx(`hit_${e.kind}`);
        }
        if (!instant && e.hits.some(h => h.hp === 0)) {
            this._sfx('death', {}, 120);
        }
        if (!instant && e.spared.length) {
            this._sfx('miss', {}, 200);
        }
        for (const hit of e.hits) {
            const state = this.players.get(hit.id);
            state.hp = hit.hp;
            this.arena.renderHearts(state);
            if (hit.hp === 0) {
                state.dead = true;
                state.el.classList.add('draw-dead');
                this._countAlive();
                this._updateMood(instant);
                if (!instant) {
                    state.animator.stop().playAnimation('death');
                    this.arena.deathToast(state);
                }
                else {
                    state.animator.stop();
                    state.animator.setFrame(state.animator.lastFrame.death);
                }
            }
            else if (!instant) {
                if (state.def.anims.hurt) {
                    state.animator.stop().playAnimation('hurt').playAnimation('idle');
                }
                this._flash(state.el, 'draw-hit');
            }
        }
        if (!instant) {
            for (const id of e.spared) {
                this._float(this.players.get(id).el, drawT('player.miss'), 'draw-float-miss');
            }
        }
    }

    /**
     * Turns the music epic when the fight gets tense: the boss is low on HP
     * or only a few players are left; calm again with a fresh boss.
     *
     * @param {boolean} instant - True when skipping (no music changes).
     */
    _updateMood(instant) {
        if (instant || !this.sound) {
            return;
        }
        let alive = 0;
        for (const state of this.players.values()) {
            alive += state.dead ? 0 : 1;
        }
        const lastStand = alive <= Math.max(3, Math.ceil(this.players.size * 0.15));
        const bossLow = this.bossMaxHp > 0 && this.arena.bossFill.style.width && parseFloat(this.arena.bossFill.style.width) <= 35;
        this.sound.setMusicMood(lastStand || bossLow ? 'epic' : 'calm');
    }

    /** Shows how many players are still alive on each side. */
    _countAlive() {
        const counts = { melee: 0, ranged: 0 };
        for (const state of this.players.values()) {
            counts[state.kind] += state.dead ? 0 : 1;
        }
        this.arena.setCounts(counts);
    }

    /** A few quick blips per tick, more with a bigger army, never one per player. */
    _chipSounds() {
        let alive = 0;
        for (const state of this.players.values()) {
            alive += state.dead ? 0 : 1;
        }
        const count = Math.min(3, Math.ceil(alive / 15));
        for (let i = 0; i < count; i++) {
            this._sfx('chip', { pitch: 0.85 + Math.random() * 0.4, volume: 0.7, force: true }, i * 170 + Math.random() * 60);
        }
    }

    /** Makes some living heroes attack (one of their attacks for their side), just for show. */
    _chipAnimations() {
        for (const state of this.players.values()) {
            if (state.dead || Math.random() > 0.4) {
                continue;
            }
            this._later(() => {
                if (!state.dead && !this.finished) {
                    const attacks = state.def.attacks[state.kind] || Object.values(state.def.attacks)[0];
                    state.animator.stop().playAnimation(attacks[Math.floor(Math.random() * attacks.length)]).playAnimation('idle');
                }
            }, Math.random() * 600);
        }
    }

    /**
     * Sets a new boss.
     *
     * @param {number} cycle - Boss number, from 1.
     * @param {number} hp - Current HP.
     * @param {number} maxHp - Max HP.
     */
    _setBoss(cycle, hp, maxHp) {
        this.bossMaxHp = maxHp;
        this.arena.setBoss(drawBossFor(cycle));
        this.arena.bossLabel.textContent = drawBossLabel(this.arena.bossId, cycle, this.bossName);
        this._setBossHp(hp);
    }

    /**
     * Updates the boss's HP bar.
     *
     * @param {number} hp - Current HP.
     */
    _setBossHp(hp) {
        this.arena.bossFill.style.width = `${(hp / this.bossMaxHp) * 100}%`;
        this.arena.bossHpText.textContent = `${hp.toLocaleString('pt-BR')} / ${this.bossMaxHp.toLocaleString('pt-BR')}`;
    }

    /**
     * Adds a class for a while (restarting its CSS animation).
     *
     * @param {HTMLElement} el - Element.
     * @param {string} className - Class to add.
     * @param {number} [ms] - How long, at speed 1.
     */
    _flash(el, className, ms = 450) {
        el.classList.remove(className);
        void el.offsetWidth;
        el.classList.add(className);
        this._later(() => el.classList.remove(className), ms);
    }

    /**
     * Floats a short text up from an element.
     *
     * @param {HTMLElement} anchor - Where it starts.
     * @param {string} text - Text.
     * @param {string} className - Style class.
     */
    _float(anchor, text, className) {
        const el = drawEl('div', `draw-float ${className}`, text);
        el.style.left = `${30 + Math.random() * 40}%`;
        el.style.animationDuration = `${900 / this.speed}ms`;
        anchor.appendChild(el);
        this._later(() => el.remove(), 900);
    }

    /**
     * One winner on the final screen: their hero, big, over a spinning golden
     * ray burst and a pulsing glow, with gold sparkle sprites floating around.
     *
     * @param {object} state - Winner's player state.
     * @param {number} scale - Pixel scale of the hero.
     * @param {number} [size] - Side of the square the hero stands in.
     * @returns {HTMLElement}
     */
    _champion(state, scale, size = 320) {
        const card = drawEl('div', 'draw-champion');
        const stage = drawEl('div', 'draw-champion-stage');
        Object.assign(stage.style, { width: `${size}px`, height: `${size}px` });
        stage.append(drawEl('div', 'draw-rays'), drawEl('div', 'draw-glow'));
        const def = state.def;
        const [bx, by, bw, bh] = def.anims.idle.box || [0, 0, def.w, def.h];
        const holder = drawEl('div', 'draw-sprite');
        Object.assign(holder.style, {
            left: `${Math.round(size / 2 - (bx + bw / 2) * scale)}px`,
            top: `${Math.round(size * 0.82 - (by + bh) * scale)}px`,
            width: `${def.w * scale}px`,
            height: `${def.h * scale}px`
        });
        stage.appendChild(holder);
        const animator = drawCharacter(holder, def, scale);
        const attacks = def.attacks[state.kind] || Object.values(def.attacks)[0];
        animator.playAnimation(attacks[0]).playAnimation('idle');
        this.arena.animators.push(animator);
        this.championAnimators = (this.championAnimators || []).concat({ animator, attack: attacks[0] });

        const kinds = Object.keys(DRAW_DATA.effects);
        for (let i = 0; i < 12; i++) {
            const fx = DRAW_DATA.effects[kinds[i % kinds.length]];
            const angle = (i / 12) * Math.PI * 2 + Math.random() * 0.4;
            const radius = (95 + Math.random() * 60) * size / 320;
            const particle = drawEl('div', 'draw-particle');
            Object.assign(particle.style, {
                left: `${Math.round(size / 2 + Math.cos(angle) * radius - fx.w * 1.5)}px`,
                top: `${Math.round(size / 2 + Math.sin(angle) * radius * 0.8 - fx.h * 1.5)}px`,
                width: `${fx.w * 3}px`,
                height: `${fx.h * 3}px`,
                animationDelay: `${(Math.random() * 2.4).toFixed(2)}s`,
                animationDuration: `${(2.2 + Math.random() * 1.2).toFixed(2)}s`
            });
            const sparkle = drawCharacter(particle, fx, 3);
            sparkle.playAnimation('play');
            this.arena.animators.push(sparkle);
            stage.appendChild(particle);
        }
        card.appendChild(stage);
        const name = drawEl('div', 'draw-champion-name', state.name);
        name.style.color = state.color;
        card.appendChild(name);
        return card;
    }

    /**
     * Shows the winners.
     *
     * @param {object} e - End event.
     * @param {boolean} instant - True when skipping.
     */
    _end(e, instant) {
        const next = () => {
            if (this.finished || this.inShowdown) {
                return;
            }
            cancelAnimationFrame(this._rafId);
            for (const [k] of DRAW_SIDES) {
                this.arena.sides[k].el.classList.remove('draw-warn');
            }
            if (this.showdown && e.winners.length) {
                this._startShowdown(e.winners);
            }
            else {
                this._finale(e.winners, [], instant);
            }
        };
        if (instant) {
            next();
        }
        else {
            this._later(next, 1500);
        }
    }

    /**
     * Runs a callback after a delay in real time (the showdown ignores the
     * battle speed); cancelled with the playback.
     *
     * @param {Function} fn
     * @param {number} ms
     */
    _after(fn, ms) {
        const id = setTimeout(() => {
            this.timers.delete(id);
            fn();
        }, ms);
        this.timers.add(id);
    }

    /**
     * A sprite standing on the showdown lane: its feet (from the idle pose) at
     * the bottom of the returned box, its centre at the box's left edge.
     *
     * @param {object} def - Hero or boss definition.
     * @param {number} scale - Pixel scale.
     * @param {boolean} flip - Mirror it.
     * @returns {{box: HTMLElement, animator: SpriteAnimator}}
     */
    _laneSprite(def, scale, flip) {
        const idle = Array.isArray(def.anims.idle) ? def.anims.idle[0] : def.anims.idle;
        const [bx, by, bw, bh] = idle.box || [0, 0, def.w, def.h];
        const centre = flip ? def.w - (bx + bw / 2) : bx + bw / 2;
        const box = drawEl('div', 'draw-lane-sprite');
        const holder = drawEl('div', 'draw-sprite');
        Object.assign(holder.style, {
            left: `${Math.round(-centre * scale)}px`,
            top: `${Math.round(-(by + bh) * scale)}px`,
            width: `${def.w * scale}px`,
            height: `${def.h * scale}px`
        });
        holder.classList.toggle('draw-flip', flip);
        box.appendChild(holder);
        const animator = drawCharacter(holder, def, scale);
        animator.playAnimation('idle');
        this.arena.animators.push(animator);
        return { box, animator };
    }

    /**
     * The winners' showdown: the battlefield darkens, the winners stand under
     * a countdown, and the boss walks towards them while the hurry music
     * plays. A winner who says anything in chat (winnerSpoke) flares up and is
     * safe; when everyone spoke the boss falls; when time runs out the boss
     * executes whoever stayed silent, and they lose the prize.
     *
     * @param {string[]} ids - Winners' ids.
     */
    _startShowdown(ids) {
        this.inShowdown = true;
        this.showdownIds = ids.slice();
        this.showdownLeft = new Set(ids);
        this.confirmed = [];
        if (this.sound) {
            this.sound.playMusic(500, 'hurry');
        }
        const scene = drawEl('div', ids.length > 3 ? 'draw-showdown draw-showdown-many' : 'draw-showdown');
        this.countdown = drawEl('div', 'draw-countdown');
        const names = ids.map(id => this.players.get(id).name).join(', ');
        scene.append(this.countdown, drawEl('div', 'draw-showdown-prompt', drawT('showdown.prompt', { names })));
        const lane = drawEl('div', 'draw-showdown-lane');
        scene.appendChild(lane);
        this.showdownLane = lane;
        this.arena.el.appendChild(scene);
        this.arena.el.classList.add('draw-in-showdown');
        this.showdownScene = scene;

        const width = lane.offsetWidth || 1280;
        const slots = this._showdownSlots(ids.length, width);
        this.showdownHeroes = new Map();
        ids.forEach((id, i) => {
            const state = this.players.get(id);
            const slot = slots[i];
            const { box, animator } = this._laneSprite(state.def, slot.scale, false);
            Object.assign(box.style, { left: `${slot.x}px`, top: `${slot.y}px`, zIndex: String(slot.y ? 0 : 1) });
            const name = drawEl('div', 'draw-lane-name', state.name);
            name.style.color = state.color;
            box.appendChild(name);
            lane.appendChild(box);
            this.showdownHeroes.set(id, { box, animator, state, scale: slot.scale, y: slot.y });
        });

        const bossDef = this.arena.bossDef;
        const boss = this._laneSprite(bossDef, DRAW_SCALE, bossDef.faces === 'right');
        boss.box.classList.add('draw-lane-boss');
        boss.box.style.filter = this.bossBody.style.filter;
        boss.box.style.left = `${Math.round(width - 160)}px`;
        lane.appendChild(boss.box);
        this.showdownBoss = boss;
        const reach = Math.max(...slots.map(s => s.x)) + 150;
        const seconds = Math.max(5, Number(this.showdown.seconds) || 60);
        this._after(() => {
            boss.animator.stop().playAnimation('walk');
            boss.box.style.transition = `left ${seconds}s linear`;
            boss.box.style.left = `${reach}px`;
        }, 60);

        const start = performance.now();
        this.showdownEnd = start + seconds * 1000;
        this.showdownReach = reach;
        let lastShown = null;
        this._showdownTick = setInterval(() => {
            const left = Math.max(0, seconds - (performance.now() - start) / 1000);
            const shown = Math.ceil(left);
            if (shown !== lastShown) {
                lastShown = shown;
                this.countdown.textContent = String(shown);
                this.countdown.classList.toggle('draw-countdown-hurry', shown <= 10);
                if (shown <= 10 && shown > 0) {
                    this._sfx('tick', { force: true });
                }
            }
            if (left <= 0) {
                this._execute();
            }
        }, 100);
        if (this.onShowdown) {
            this.onShowdown(ids.slice());
        }
    }

    /**
     * Where the showdown's winners stand, best first: one or up to three side
     * by side; four or more in two staggered rows (the best in front).
     *
     * @param {number} n - Number of winners (1–10).
     * @param {number} width - Lane width.
     * @returns {Array<{x: number, y: number, scale: number}>}
     */
    _showdownSlots(n, width) {
        if (n <= 3) {
            const scale = n === 1 ? 5 : 4;
            return Array.from({ length: n }, (_, i) => ({ x: Math.round(width * 0.16 + i * 230), y: 0, scale }));
        }
        const front = Math.ceil(n / 2);
        return Array.from({ length: n }, (_, i) => {
            const back = i >= front;
            const col = back ? i - front : i;
            return { x: Math.round(width * 0.12 + col * 120 + (back ? 60 : 0)), y: back ? -130 : 0, scale: 3 };
        });
    }

    /**
     * A winner spoke in chat (or the streamer marked them present): their
     * hero flares up and they keep the prize.
     *
     * @param {string} id - Player id (lowercase name).
     * @returns {boolean} True when it was a pending winner.
     */
    winnerSpoke(id) {
        if (!this.inShowdown || !this.showdownLeft.has(id)) {
            return false;
        }
        this.showdownLeft.delete(id);
        this.confirmed.push(id);
        const hero = this.showdownHeroes.get(id);
        const last = !this.showdownLeft.size;
        if (last) {
            clearInterval(this._showdownTick);
            this.countdown.textContent = '';
            this._charge(hero);
            return true;
        }
        hero.box.classList.add('draw-flare');
        const attacks = hero.state.def.attacks[hero.state.kind] || Object.values(hero.state.def.attacks)[0];
        hero.animator.stop().playAnimation(attacks[0]).playAnimation('idle');
        const kinds = Object.keys(DRAW_DATA.effects);
        for (let i = 0; i < 6; i++) {
            const fx = DRAW_DATA.effects[kinds[i % kinds.length]];
            const spark = drawEl('div', 'draw-particle draw-flare-spark');
            Object.assign(spark.style, {
                left: `${Math.round(Math.cos(i) * 70 - fx.w * 1.5)}px`,
                top: `${Math.round(-110 + Math.sin(i * 1.7) * 60 - fx.h * 1.5)}px`,
                width: `${fx.w * 3}px`,
                height: `${fx.h * 3}px`,
                animationDelay: `${(i * 0.12).toFixed(2)}s`
            });
            const anim = drawCharacter(spark, fx, 3);
            anim.playAnimation('play');
            this.arena.animators.push(anim);
            hero.box.appendChild(spark);
        }
        this._sfx('flare', { force: true });
        this._after(() => this._bolt(hero, false), 280);
        return true;
    }

    /**
     * The finishing blow, charged: the boss freezes, the hero swells and
     * glows while sparks rush into them, the camera slowly zooms and tilts
     * towards the hero as the ground shakes harder and harder, then the
     * camera snaps back and the bolt flies.
     *
     * @param {object} hero - Showdown hero ({box, animator, state, scale, y}).
     */
    _charge(hero) {
        const chargeMs = 1400;
        const boss = this.showdownBoss;
        boss.box.style.left = getComputedStyle(boss.box).left;
        boss.box.style.transition = 'none';
        boss.animator.stop().playAnimation('idle');
        hero.box.classList.add('draw-flare', 'draw-charging');
        hero.box.style.zIndex = '3';
        this._sfx('charge', { force: true });

        const kinds = Object.keys(DRAW_DATA.effects);
        const centreY = -this._idleBox(hero.state.def)[3] * hero.scale * 0.55;
        for (let i = 0; i < 14; i++) {
            const fx = DRAW_DATA.effects[kinds[i % kinds.length]];
            const angle = (i / 14) * Math.PI * 2;
            const spark = drawEl('div', 'draw-converge');
            Object.assign(spark.style, {
                left: `${Math.round(-fx.w * 1.5)}px`,
                top: `${Math.round(centreY - fx.h * 1.5)}px`,
                width: `${fx.w * 3}px`,
                height: `${fx.h * 3}px`,
                animationDelay: `${((i % 7) * 0.1).toFixed(2)}s`
            });
            spark.style.setProperty('--dx', `${Math.round(Math.cos(angle) * 170)}px`);
            spark.style.setProperty('--dy', `${Math.round(Math.sin(angle) * 130)}px`);
            const anim = drawCharacter(spark, fx, 3);
            anim.playAnimation('play');
            this.arena.animators.push(anim);
            hero.box.appendChild(spark);
        }

        const lane = this.showdownLane;
        const el = this.arena.el;
        const x = parseFloat(hero.box.style.left);
        const y = lane.offsetTop + hero.y + centreY;
        el.style.transformOrigin = `${Math.round(x)}px ${Math.round(y)}px`;
        el.style.transition = `transform ${chargeMs}ms cubic-bezier(.35, 0, .25, 1)`;
        void el.offsetWidth;
        el.style.transform = 'scale(1.16) rotate(-2.5deg)';
        const root = this.arena.root;
        const started = performance.now();
        this._rumbleTimer = setInterval(() => {
            const amp = 0.5 + 6 * Math.min(1, (performance.now() - started) / chargeMs);
            root.style.transform = `translate(${((Math.random() * 2 - 1) * amp).toFixed(1)}px, ${((Math.random() * 2 - 1) * amp * 0.7).toFixed(1)}px)`;
        }, 40);

        this._after(() => {
            clearInterval(this._rumbleTimer);
            root.style.transform = '';
            el.style.transition = 'transform 160ms cubic-bezier(.2, .8, .3, 1)';
            el.style.transform = '';
            for (const spark of hero.box.querySelectorAll('.draw-converge')) {
                spark.remove();
            }
            hero.box.classList.remove('draw-charging');
            hero.box.classList.add('draw-release');
            const attacks = hero.state.def.attacks[hero.state.kind] || Object.values(hero.state.def.attacks)[0];
            hero.animator.stop().playAnimation(attacks[0]).playAnimation('idle');
            this._sfx('flare', { force: true });
            this._flash(this.arena.root, 'draw-shake', 350);
            this._after(() => {
                el.style.transition = '';
                el.style.transformOrigin = '';
                this._bolt(hero, true);
            }, 200);
        }, chargeMs);
    }

    /**
     * The flare's light bolt: it flies from the hero to the boss and
     * explodes on it, knocking the boss back (the last one strikes it down).
     *
     * @param {object} hero - Showdown hero ({box, state}).
     * @param {boolean} last - The last winner spoke: this bolt kills the boss.
     */
    _bolt(hero, last) {
        if (!this.inShowdown) {
            return;
        }
        const fx = DRAW_DATA.showdownFx;
        const boss = this.showdownBoss;
        const bossBox = this._idleBox(this.arena.bossDef);
        const heroBox = this._idleBox(hero.state.def);
        const x0 = parseFloat(hero.box.style.left) + 30;
        const y0 = hero.y - heroBox[3] * hero.scale * 0.55;
        const flight = 420;
        const target = () => parseFloat(getComputedStyle(boss.box).left) - 10;
        const y1 = -bossBox[3] * DRAW_SCALE * 0.5;

        const bolt = drawEl('div', 'draw-bolt');
        const holder = drawEl('div', 'draw-sprite');
        const scale = 3;
        Object.assign(holder.style, {
            left: `${-fx.bolt.w * scale / 2}px`,
            top: `${-fx.bolt.h * scale / 2}px`,
            width: `${fx.bolt.w * scale}px`,
            height: `${fx.bolt.h * scale}px`
        });
        bolt.appendChild(holder);
        const anim = drawCharacter(holder, fx.bolt, scale);
        anim.playAnimation('play');
        this.arena.animators.push(anim);
        this.showdownLane.appendChild(bolt);
        const x1 = target() + (last ? 0 : -20);
        bolt.animate([
            { transform: `translate(${x0}px, ${y0}px) scale(.6)` },
            { transform: `translate(${x1}px, ${y1}px) scale(1.15)` }
        ], { duration: flight, easing: 'cubic-bezier(.5, 0, .9, .6)', fill: 'forwards' });

        this._after(() => {
            bolt.remove();
            const here = target();
            const blast = drawEl('div', 'draw-blast');
            const blastHolder = drawEl('div', 'draw-sprite');
            const blastScale = last ? 6 : 4;
            Object.assign(blast.style, { left: `${here}px`, top: `${y1}px` });
            Object.assign(blastHolder.style, {
                left: `${-fx.blast.w * blastScale / 2}px`,
                top: `${-fx.blast.h * blastScale / 2}px`,
                width: `${fx.blast.w * blastScale}px`,
                height: `${fx.blast.h * blastScale}px`
            });
            blast.append(drawEl('div', 'draw-blast-flash'), blastHolder);
            const blastAnim = drawCharacter(blastHolder, fx.blast, blastScale);
            blastAnim.playAnimation('play');
            this.arena.animators.push(blastAnim);
            this.showdownLane.appendChild(blast);
            this._after(() => blast.remove(), fx.blast.anims.play.durations.reduce((a, b) => a + b, 0) + 200);
            this._sfx('blast', { force: true });
            this._flash(this.arena.el, 'draw-shake');
            this._flash(boss.box, 'draw-lane-hit', 300);
            if (last) {
                this._bossFalls(true);
                this._whiteout(blast);
                return;
            }
            boss.animator.stop().playAnimation('hurt').playAnimation('walk');
            const left = Math.max(0, (this.showdownEnd - performance.now()) / 1000);
            boss.box.style.transition = 'none';
            boss.box.style.left = `${here + 70}px`;
            void boss.box.offsetWidth;
            boss.box.style.transition = `left ${left.toFixed(2)}s linear`;
            boss.box.style.left = `${this.showdownReach}px`;
        }, flight);
    }

    /**
     * The content box of a sprite's idle pose.
     *
     * @param {object} def - Hero or boss definition.
     * @returns {number[]} [x, y, w, h]
     */
    _idleBox(def) {
        const idle = Array.isArray(def.anims.idle) ? def.anims.idle[0] : def.anims.idle;
        return idle.box || [0, 0, def.w, def.h];
    }

    /**
     * The final blast's light floods the screen from the impact point; the
     * winner screen is put in place while it is all white, then the light
     * fades out over it.
     *
     * @param {HTMLElement} blast - The explosion element (the light's origin).
     */
    _whiteout(blast) {
        const scale = drawScreenScale(this.arena.el);
        const arena = this.arena.el.getBoundingClientRect();
        const at = blast.getBoundingClientRect();
        const light = drawEl('div', 'draw-whiteout');
        light.appendChild(drawEl('div', 'draw-whiteout-glow'));
        light.style.setProperty('--x', `${Math.round((at.left - arena.left) / scale)}px`);
        light.style.setProperty('--y', `${Math.round((at.top - arena.top) / scale)}px`);
        this.arena.el.appendChild(light);
        this._after(() => {
            this._closeShowdown();
            light.classList.add('draw-whiteout-fade');
            this._after(() => light.remove(), 1800);
        }, 800);
    }

    /**
     * Everyone spoke: the boss stops, is struck down, and the winners are celebrated.
     *
     * @param {boolean} [flash] - The screen flash takes over the transition to the winner screen.
     */
    _bossFalls(flash) {
        clearInterval(this._showdownTick);
        const boss = this.showdownBoss;
        boss.box.style.left = getComputedStyle(boss.box).left;
        boss.box.style.transition = 'none';
        boss.animator.stop().playAnimation('hurt').playAnimation('die');
        boss.box.classList.add('draw-lane-dead');
        this.countdown.textContent = '';
        this._sfx('boss_down', { force: true }, 150);
        if (!flash) {
            this._after(() => this._closeShowdown(), 2200);
        }
    }

    /** Time is up: the boss strikes whoever stayed silent, who loses the prize. */
    _execute() {
        clearInterval(this._showdownTick);
        if (!this.inShowdown || !this.showdownLeft.size) {
            return;
        }
        const boss = this.showdownBoss;
        boss.animator.stop().playAnimation('melee').playAnimation('idle');
        this.countdown.textContent = '0';
        this._after(() => {
            this._sfx('execute', { force: true });
            this._flash(this.arena.el, 'draw-shake');
            for (const id of this.showdownLeft) {
                const hero = this.showdownHeroes.get(id);
                hero.animator.stop().playAnimation('death');
                hero.box.classList.add('draw-executed');
                hero.box.appendChild(drawEl('div', 'draw-stamp', drawT('showdown.executed')));
            }
            this._after(() => this._closeShowdown(), 2600);
        }, this.arena.bossDef.windupMs.melee);
    }

    /** Ends the showdown and shows who kept the prize (and who lost it). */
    _closeShowdown() {
        if (!this.inShowdown) {
            return;
        }
        this.inShowdown = false;
        const kept = this.showdownIds.filter(id => !this.showdownLeft.has(id));
        const forfeited = this.showdownIds.filter(id => this.showdownLeft.has(id));
        this.showdownScene.remove();
        if (this.sound) {
            this.sound.stopMusic(400);
        }
        this._finale(kept, forfeited, false);
    }

    /**
     * The final screen: the winners who kept the prize over the golden rays,
     * or, when nobody did, who lost it.
     *
     * @param {string[]} winners - Winners keeping the prize.
     * @param {string[]} forfeited - Winners who lost it in the showdown.
     * @param {boolean} instant - True when skipping.
     */
    _finale(winners, forfeited, instant) {
        if (this.finished) {
            return;
        }
        this.finished = true;
        this.result.confirmed = winners.slice();
        this.result.forfeited = forfeited.slice();
        for (const id of winners) {
            this.players.get(id).el.classList.add('draw-winner');
        }
        const panel = drawEl('div', 'draw-result');
        const lost = !winners.length && forfeited.length;
        panel.appendChild(drawEl('div', 'draw-result-title',
            drawT(lost ? 'showdown.lost_title' : winners.length > 1 ? 'result.winners' : 'result.winner')));
        const many = winners.length > 3;
        const champions = drawEl('div', many ? 'draw-champions draw-champions-many' : 'draw-champions');
        for (const id of winners) {
            const state = this.players.get(id);
            champions.appendChild(many ? this._champion(state, 3, 190)
                : this._champion(state, winners.length > 1 ? 4 : DRAW_CHAMPION_SCALE));
        }
        if (lost) {
            const names = drawEl('div', 'draw-champion-name draw-lost-name', forfeited.map(id => this.players.get(id).name).join(', '));
            champions.appendChild(names);
        }
        else if (!winners.length) {
            champions.appendChild(drawEl('div', 'draw-champion-name', drawT('result.nobody')));
        }
        panel.appendChild(champions);
        if (forfeited.length && winners.length) {
            panel.appendChild(drawEl('div', 'draw-result-meta draw-lost-line',
                drawT('showdown.lost_line', { names: forfeited.map(id => this.players.get(id).name).join(', ') })));
        }
        panel.appendChild(drawEl('div', 'draw-result-meta',
            drawT('result.meta', { bosses: this.result.cycles, seconds: Math.round(this.result.durationMs / 1000) })));
        this.arena.el.appendChild(panel);

        if (this.sound) {
            this.sound.stopMusic(instant ? 300 : 700);
        }
        if (!instant && winners.length) {
            this._sfx('fanfare');
            this._sfx('coin', {}, 1000);
        }
        this._celebrate = setInterval(() => {
            for (const anim of this.championAnimators || []) {
                anim.animator.stop().playAnimation(anim.attack).playAnimation('idle');
            }
        }, 3500);
        if (this.onEnd) {
            this.onEnd(this.result);
        }
    }
}
