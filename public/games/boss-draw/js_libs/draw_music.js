/**
 * BossDraw music
 *
 * Plays a song made of sections (js_libs/music/battle_theme.js) as one
 * endless piece. Sections are rendered with ZzFX in 4-bar chunks, scheduled
 * back to back on the audio clock, and each chunk keeps its notes' tails so
 * they ring into the next one.
 *
 * Every pass is a little different: notes split into quick pairs, the
 * arpeggio changes direction, the bass sometimes plays root and fifth, drums
 * get ghost notes and a rotating fill. A section's `variation` sets how much
 * (the theme varies lightly and plays exactly as written the first time).
 *
 * The calm mood walks the song's cycle; the epic mood plays the build-up, then
 * the epic sections (with their key change) until the battle calms down, then
 * rejoins the cycle. A cue (a boss defeat) plays one section next.
 *
 * @author Henrique Cezar
 * @license MIT
 */
import { ZZFX } from './vendor/zzfx/zzfx-1.3.2.js';

/** Bars per chunk: the smallest step at which the music can change. */
const CHUNK_BARS = 4;

/** Extra seconds kept after a chunk so its last notes and echoes ring out. */
const TAIL_SECONDS = 1.5;

/**
 * Builds a ZzFX sound once per distinct parameter list.
 *
 * @param {Map} cache - Shared cache.
 * @param {Array} params - ZzFX parameters.
 * @returns {number[]}
 */
function drawBuild(cache, params) {
    const key = params.join(',');
    if (!cache.has(key)) {
        ZZFX.volume = 1;
        cache.set(key, ZZFX.buildSamples(...params));
    }
    return cache.get(key);
}

/**
 * The nearest chord tone above (dir 1) or below (dir -1) a note.
 *
 * @param {number[]} chord - Chord, in semitones.
 * @param {number} note - Note, in semitones.
 * @param {number} dir - 1 or -1.
 * @returns {number}
 */
function drawChordTone(chord, note, dir) {
    for (let d = 1; d <= 12; d++) {
        const n = note + dir * d;
        if (chord.some(c => (((n - c) % 12) + 12) % 12 === 0)) {
            return n;
        }
    }
    return note;
}

/**
 * Every note a section plays in one bar, step by step (16 steps per bar).
 *
 * @param {object} song - Song (BATTLE_SONG format).
 * @param {object} section - Section.
 * @param {number} bar - Bar index in the section.
 * @param {function(): number|null} [rand] - Random source for this pass; null plays the bar exactly as written.
 * @returns {Array<object>} Events: {step, instrument, note, len?} or {step, drum}.
 */
function drawBarEvents(song, section, bar, rand = null) {
    const amount = rand ? (section.variation ?? 1) : 0;
    const chance = (p) => amount > 0 && rand() < p * amount;
    const pick = (list) => list[Math.floor(rand() * list.length)];
    const shift = section.transpose || 0;
    const chord = section.chords[bar].map(c => c + shift);
    const last = bar === section.chords.length - 1;
    const events = [];

    const row = section.melody[bar] || [];
    const per = row.length === 16 ? 1 : 2;
    const lead = section.lead || 'lead';
    const pushLead = (step, note, len) => {
        events.push({ step, instrument: lead, note, len });
        for (const octave of section.leadOctaves || []) {
            events.push({ step, instrument: lead, note: note + octave, len });
        }
    };
    row.forEach((entry, slot) => {
        if (entry === null || entry === undefined) {
            return;
        }
        const [raw, slots] = Array.isArray(entry) ? entry : [entry, 1];
        const note = raw + shift;
        const step = slot * per;
        const len = slots * per;
        if (len === 2 && chance(0.18)) {
            pushLead(step, note, 1);
            pushLead(step + 1, drawChordTone(chord, note, rand() < 0.5 ? 1 : -1), 1);
        }
        else {
            pushLead(step, note + (slot === 0 && len <= 2 && chance(0.08) ? 12 : 0), len);
        }
    });

    const counter = (section.counter || [])[bar] || [];
    const counterPer = counter.length === 16 ? 1 : 2;
    counter.forEach((entry, slot) => {
        if (entry === null || entry === undefined) {
            return;
        }
        const [raw, slots] = Array.isArray(entry) ? entry : [entry, 1];
        events.push({ step: slot * counterPer, instrument: section.counterLead || 'counter', note: raw + shift, len: slots * counterPer });
    });

    let bass = section.bass;
    if (bass === 'octaves' && chance(0.25)) {
        bass = 'fifths';
    }
    let arpStyle = section.arp ? 'up' : null;
    if (arpStyle && chance(0.4)) {
        arpStyle = pick(['down', 'updown']);
    }
    const arpShift = section.arp === 'high' ? 12 : 0;

    let pattern;
    if (section.drumBars) {
        pattern = song.drumPatterns[section.drumBars[bar]];
    }
    else if (last && section.fill) {
        pattern = song.drumPatterns[rand && amount > 0 ? pick(song.fills || ['fill']) : 'fill'];
    }
    else {
        pattern = song.drumPatterns[section.drums];
    }
    pattern = (pattern || []).slice();
    if (!last && section.drums === 'beat') {
        if (chance(0.25) && !pattern[15]) {
            pattern[15] = 's';
        }
        if (chance(0.2) && !pattern[10]) {
            pattern[10] = 'k';
        }
    }

    for (let step = 0; step < 16; step++) {
        const eighth = Math.floor(step / 2);
        if (bass === 'octaves' && step % 2 === 0) {
            events.push({ step, instrument: 'bass', note: chord[0] + (eighth % 2 ? 12 : 0) });
        }
        else if (bass === 'fifths' && step % 2 === 0) {
            events.push({ step, instrument: 'bass', note: chord[0] + (eighth % 2 ? 7 : 0) });
        }
        else if (bass === 'drive') {
            events.push({ step, instrument: 'bass', note: chord[0] + (step % 4 === 2 ? 12 : 0) });
        }
        else if (bass === 'long' && step % 8 === 0) {
            events.push({ step, instrument: 'bassLong', note: chord[0] });
        }
        if (arpStyle === 'up') {
            events.push({ step, instrument: 'arp', note: chord[step % 3] + 12 * (Math.floor(step / 3) % 2) + arpShift });
        }
        else if (arpStyle === 'down') {
            events.push({ step, instrument: 'arp', note: chord[2 - (step % 3)] + 12 * (1 - (Math.floor(step / 3) % 2)) + arpShift });
        }
        else if (arpStyle === 'updown') {
            events.push({ step, instrument: 'arp', note: chord[[0, 1, 2, 1][step % 4]] + 12 + arpShift });
        }
        for (const drum of pattern[step] || '') {
            events.push({ step, drum });
        }
    }
    if ((section.crash && bar === 0) || (section.crashBars || []).includes(bar)) {
        events.push({ step: 0, drum: 'c' });
    }
    if (section.riserBar === bar) {
        events.push({ step: 0, drum: 'r' });
    }
    return events;
}

/**
 * Renders bars of a section into samples.
 *
 * @param {object} song - Song.
 * @param {string} name - Section name.
 * @param {number} fromBar - First bar (included).
 * @param {number} toBar - Last bar (excluded).
 * @param {object} [options]
 * @param {boolean} [options.wrap] - Wrap tails to the start (a standalone loop) instead of keeping them after the end.
 * @param {Map} [options.cache] - Sound cache to share between renders.
 * @param {function(): number} [options.rand] - Random source for variation; none plays exactly as written.
 * @returns {{samples: Float32Array, length: number}} length = samples before the tail.
 */
function drawRenderBars(song, name, fromBar, toBar, options = {}) {
    const section = song.sections[name];
    const cache = options.cache || new Map();
    const stepSeconds = 60 / song.bpm / 4;
    const stepSamples = Math.round(ZZFX.sampleRate * stepSeconds);
    const length = (toBar - fromBar) * 16 * stepSamples;
    const out = new Float32Array(length + (options.wrap ? 0 : Math.round(TAIL_SECONDS * ZZFX.sampleRate)));
    const mix = (samples, at) => {
        for (let i = 0; i < samples.length; i++) {
            const to = options.wrap ? (at + i) % out.length : at + i;
            if (to < out.length) {
                out[to] += samples[i];
            }
        }
    };
    for (let bar = fromBar; bar < toBar; bar++) {
        const offset = (bar - fromBar) * 16 * stepSamples;
        for (const e of drawBarEvents(song, section, bar, options.rand || null)) {
            let params;
            if (e.drum) {
                params = (song.drums[e.drum] || []).slice();
            }
            else {
                const inst = song.instruments[e.instrument];
                params = inst.zzfx.slice();
                params[2] = ZZFX.getNote(e.note, inst.root);
                if (e.len > 2) {
                    params[4] = Math.max(params[4] || 0, e.len * stepSeconds - (params[3] || 0) - (params[5] || 0) - 0.02);
                }
            }
            if (!params.length) {
                continue;
            }
            params[1] = 0;
            mix(drawBuild(cache, params), offset + e.step * stepSamples);
        }
    }
    return { samples: out, length };
}

/**
 * Scales samples by a gain, softly rounding off anything above 0.95 so a
 * loud section never clips hard.
 *
 * @param {Float32Array} samples
 * @param {number} gain
 */
function drawLevel(samples, gain) {
    for (let i = 0; i < samples.length; i++) {
        const v = samples[i] * gain;
        const a = Math.abs(v);
        samples[i] = a <= 0.95 ? v : Math.sign(v) * (0.95 + 0.05 * Math.tanh((a - 0.95) / 0.05));
    }
}

/**
 * Plays a sectioned song without end, following a calm or epic mood.
 */
class DrawMusic {
    /**
     * @param {object} song - Song (BATTLE_SONG format).
     * @param {function(): number} [rand] - Random source for the variations.
     */
    constructor(song, rand = Math.random) {
        this.song = song;
        this.rand = rand;
        this.cache = new Map();
        this.mood = 'calm';
        this.cued = null;
        this.sources = new Set();
        this.playing = false;
        this.output = null;
        this.current = null;
        this.cyclePos = 0;
        this.passes = {};
        this.upcoming = null;
        this._timer = null;
        this._prerender = null;
        this.stepSeconds = 60 / song.bpm / 4;
        this.chunkSeconds = CHUNK_BARS * 16 * this.stepSeconds;
    }

    /**
     * The gain that makes the theme (as written) peak at 0.8, used for every
     * section so they keep the same loudness relation; the theme then sounds
     * exactly like the v1 loop.
     */
    get gain() {
        if (!this._gain) {
            const loop = drawRenderBars(this.song, this.song.calm, 0, 8, { wrap: true, cache: this.cache }).samples;
            let peak = 0;
            for (const v of loop) {
                peak = Math.max(peak, Math.abs(v));
            }
            this._gain = peak > 0 ? 0.8 / peak : 1;
        }
        return this._gain;
    }

    /**
     * Renders one chunk.
     *
     * @param {{name: string, index: number, vary: boolean}} pick - Section, chunk index, whether to vary it.
     * @returns {Float32Array}
     */
    render(pick) {
        const from = pick.index * CHUNK_BARS;
        const { samples } = drawRenderBars(this.song, pick.name, from, from + CHUNK_BARS, {
            cache: this.cache,
            rand: pick.vary ? this.rand : null
        });
        drawLevel(samples, this.gain * (this.song.sections[pick.name].level ?? 1));
        return samples;
    }

    /**
     * Builds every section's sounds ahead of time, one chunk every 60 ms, so
     * rendering during the battle only has to mix (no long first builds),
     * and keeps the first chunk ready to play.
     */
    prepare() {
        const jobs = [];
        for (const [name, section] of Object.entries(this.song.sections)) {
            for (let index = 0; index < section.chords.length / CHUNK_BARS; index++) {
                jobs.push({ name, index, vary: false });
            }
        }
        const next = () => {
            const job = jobs.shift();
            if (!job) {
                const first = { name: this.song.calm, index: 0, vary: false };
                if (!this.playing) {
                    this.upcoming = { key: this._key(first), samples: this.render(first) };
                }
                return;
            }
            if (!this.playing) {
                this.render(job);
            }
            setTimeout(next, 60);
        };
        setTimeout(next, 60);
    }

    /**
     * Starts playing into an audio node.
     *
     * @param {AudioNode} output - Where the music goes (a gain node for its volume).
     */
    start(output) {
        if (this.playing) {
            return;
        }
        this.output = output;
        this.playing = true;
        this.current = null;
        this.cyclePos = 0;
        this.passes = {};
        this.cued = null;
        this.nextTime = ZZFX.audioContext.currentTime + 0.05;
        this._schedule();
        this._timer = setInterval(() => this._schedule(), 200);
    }

    /**
     * Stops at a given audio time (after a fade made by the caller).
     *
     * @param {number} [at] - Audio context time; now when missing.
     */
    stop(at) {
        this.playing = false;
        clearInterval(this._timer);
        clearTimeout(this._prerender);
        const when = at ?? ZZFX.audioContext.currentTime;
        for (const source of this.sources) {
            try {
                source.stop(when);
            } catch (e) {}
        }
        this.sources.clear();
    }

    /**
     * Sets the mood: 'calm' walks the cycle, 'epic' builds up into the epic sections.
     *
     * @param {string} mood
     */
    setMood(mood) {
        this.mood = mood === 'epic' ? 'epic' : 'calm';
    }

    /**
     * Plays a section after the current one ends (in the calm mood).
     *
     * @param {string} name - Section name.
     */
    cue(name) {
        if (this.song.sections[name]) {
            this.cued = name;
        }
    }

    /**
     * Cache key of a chunk choice.
     *
     * @param {{name: string, index: number}} pick
     * @returns {string}
     */
    _key(pick) {
        return `${pick.name}:${pick.index}:${pick.vary ? 1 : 0}`;
    }

    /** Queues chunks until about a second of music is scheduled ahead, then renders the next one in advance. */
    _schedule() {
        const ctx = ZZFX.audioContext;
        let scheduled = false;
        while (this.playing && this.nextTime < ctx.currentTime + 1) {
            const pick = this._decide();
            this._commit(pick);
            const samples = this.upcoming && this.upcoming.key === this._key(pick) ? this.upcoming.samples : this.render(pick);
            this.upcoming = null;
            const buffer = ctx.createBuffer(1, samples.length, ZZFX.sampleRate);
            buffer.getChannelData(0).set(samples);
            const source = ctx.createBufferSource();
            source.buffer = buffer;
            source.connect(this.output);
            source.start(Math.max(this.nextTime, ctx.currentTime));
            source.onended = () => this.sources.delete(source);
            this.sources.add(source);
            this.nextTime += this.chunkSeconds;
            scheduled = true;
        }
        if (scheduled) {
            clearTimeout(this._prerender);
            this._prerender = setTimeout(() => {
                if (this.playing) {
                    const next = this._decide();
                    this.upcoming = { key: this._key(next), samples: this.render(next) };
                }
            }, 50);
        }
    }

    /**
     * Records that a chunk was scheduled: moves the cycle forward when a
     * section starts and counts passes (the first pass of a section plays
     * exactly as written).
     *
     * @param {object} pick - Chosen chunk.
     */
    _commit(pick) {
        if (pick.index === 0) {
            this.passes[pick.name] = (this.passes[pick.name] || 0) + 1;
            if (pick.fromCycle !== undefined) {
                this.cyclePos = pick.fromCycle;
            }
            if (pick.name === this.cued) {
                this.cued = null;
            }
        }
        this.current = pick;
    }

    /**
     * Chooses the next chunk without changing any state.
     *
     * @returns {{name: string, index: number, vary: boolean, fromCycle?: number}}
     */
    _decide() {
        const song = this.song;
        const cur = this.current;
        const fresh = (name, extra = {}) => Object.assign({ name, index: 0, vary: (this.passes[name] || 0) > 0 }, extra);
        const epicCycle = song.epicCycle || [song.epic];
        const inEpic = cur && (epicCycle.includes(cur.name) || cur.name === song.build);
        if (!cur) {
            return this.mood === 'epic' ? fresh(epicCycle[0]) : fresh(song.cycle[0], { fromCycle: 0 });
        }
        if (this.mood === 'epic' && !inEpic) {
            return fresh(song.build && song.sections[song.build] ? song.build : epicCycle[0]);
        }
        const chunks = song.sections[cur.name].chords.length / CHUNK_BARS;
        if (cur.index < chunks - 1) {
            return { name: cur.name, index: cur.index + 1, vary: cur.vary, cuedPlay: cur.cuedPlay };
        }
        if (this.mood === 'epic') {
            const at = epicCycle.indexOf(cur.name);
            return fresh(epicCycle[at < 0 ? 0 : (at + 1) % epicCycle.length]);
        }
        if (this.cued && this.cued !== cur.name) {
            return fresh(this.cued, { cuedPlay: true });
        }
        if (inEpic || cur.cuedPlay || !song.cycle.includes(cur.name)) {
            const pos = (song.afterEpic ?? 0) % song.cycle.length;
            return fresh(song.cycle[pos], { fromCycle: pos });
        }
        const pos = (this.cyclePos + 1) % song.cycle.length;
        return fresh(song.cycle[pos], { fromCycle: pos });
    }
}

export { DrawMusic, drawRenderBars, drawLevel, drawBarEvents, CHUNK_BARS };
