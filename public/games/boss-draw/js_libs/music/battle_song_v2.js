/**
 * Battle song v2 (2026-10-27): the sectioned song rewritten so nothing ever
 * hangs or goes quiet. Same format as BATTLE_SONG in battle_theme.js; the
 * `theme` section is still the v1 loop note for note.
 *
 * The calm cycle (about 2:08 before it repeats) grows from the theme to a
 * heroic climax and falls back into the theme:
 *
 *   theme -> theme_b -> interlude -> theme -> duet -> bridge -> march -> rise
 *   -> heroic -> heroic_b -> (theme ...)
 *
 * The epic mood plays `build` once, then walks the epic cycle (about 64 s):
 *
 *   epic -> epic_b -> epic_high (+2) -> epic_peak (+2) -> epic_break -> (epic ...)
 *
 * Every section with held lead notes carries a `counter` line that moves
 * under them, so the rule "no silence, no hanging notes" holds everywhere.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/** Semitones from A of the chords used, root first. */
const Am = [0, 3, 7];
const F = [-4, 0, 3];
const C = [3, 7, 10];
const G = [-2, 2, 5];
const E = [-5, -1, 2];
const Dm = [5, 8, 12];
const Em = [-5, -2, 2];
const A = [0, 4, 7];

/**
 * A harp-like 16th-note arpeggio over two octaves (16 slots), for sparse
 * sections where the engine's quiet arp is not enough.
 *
 * @param {number[]} chord
 * @returns {number[]}
 */
function harp(chord) {
    const tones = [chord[0], chord[1], chord[2], chord[0] + 12, chord[1] + 12, chord[2] + 12];
    return [0, 1, 2, 3, 4, 5, 4, 3, 2, 1, 0, 1, 2, 3, 4, 5].map(i => tones[i]);
}

/**
 * The theme's own rhythm (x . x x x . x x) on chord tones: the counter line
 * under the long notes of the heroic sections.
 *
 * @param {number[]} chord
 * @param {number} [up] - Semitones added (an octave for the high half).
 * @returns {Array}
 */
function pulse(chord, up = 0) {
    return [chord[1] + up, null, chord[1] + up, chord[2] + up, chord[0] + 12 + up, null, chord[2] + up, chord[1] + up];
}

/**
 * Two long horn-like notes then the root an octave up (8 slots).
 *
 * @param {number[]} chord
 * @returns {Array}
 */
function horn(chord) {
    return [[chord[2], 4], null, null, null, [chord[1], 2], null, [chord[0] + 12, 2], null];
}

/**
 * Chord tones chopped in eighths (8 slots).
 *
 * @param {number[]} chord
 * @returns {number[]}
 */
function chops(chord) {
    return [chord[0] + 12, chord[2], chord[1], chord[2], chord[0] + 12, chord[2], chord[1], chord[2]];
}

/** The epic section, shared by its key-change copy (draft 1's, unchanged). */
const EPIC = {
    chords: [Am, F, C, G, Am, F, G, E],
    melody: [
        [24, 24, 22, 24, 27, 24, 22, 19],
        [24, null, 20, 24, 27, null, 24, 20],
        [22, 22, 19, 22, 27, 26, 22, 19],
        [26, null, 22, 26, 29, null, 26, 22],
        [24, 27, 31, 27, 24, 22, 19, 22],
        [24, 20, 24, 27, 32, 31, 27, 24],
        [26, 29, 31, 29, 26, 22, 19, 22],
        [23, 26, 31, 26, 23, null, 19, null]
    ],
    leadOctaves: [-12],
    bass: 'drive',
    arp: 'high',
    drums: 'drive',
    crash: true,
    fill: true,
    level: 0.85
};

export const BATTLE_SONG_V2 = {
    bpm: 150,
    instruments: {
        lead: { root: 220, zzfx: [.32, 0, 220, .005, .1, .07, 5, .5] },
        leadSoft: { root: 220, zzfx: [.3, 0, 220, .02, .12, .12, 5, .25] },
        leadBright: { root: 220, zzfx: [.3, 0, 220, .005, .1, .1, 5, .5, , , , , , , , , .09] },
        bass: { root: 110, zzfx: [.6, 0, 110, .005, .12, .05, 1] },
        bassLong: { root: 110, zzfx: [.55, 0, 110, .01, .3, .15, 1] },
        arp: { root: 440, zzfx: [.09, 0, 440, .002, .03, .03, 5, .25] },
        counter: { root: 220, zzfx: [.26, 0, 220, .005, .1, .08, 1] },
        /** A louder, plucked arpeggio voice for the sparse sections (same root as the lead). */
        arpLoud: { root: 220, zzfx: [.17, 0, 220, .002, .03, .07, 5, .25] },
        /** A soft square counter for the driving sections, sits under the lead. */
        counterPulse: { root: 220, zzfx: [.2, 0, 220, .005, .08, .06, 5, .25] }
    },
    drums: {
        k: [.9, 0, 120, .001, .02, .12, 0, 1, -12],
        s: [.4, 0, 300, .001, .03, .1, 4, 1],
        h: [.12, 0, 4000, .001, .005, .03, 4, 1],
        c: [.3, 0, 900, .001, .05, .9, 4, 1],
        r: [.22, 0, 260, 2.8, .05, .05, 4, 1, 4]
    },
    drumPatterns: {
        beat: ['k', 'h', '', 'h', 's', 'h', '', 'h', 'k', 'h', '', 'h', 's', 'h', '', 'h'],
        drive: ['kh', 'h', 'kh', 'h', 'sh', 'h', 'kh', 'h', 'kh', 'h', 'kh', 'h', 'sh', 'h', 'kh', 'sh'],
        half: ['k', '', '', 'h', '', '', 'h', '', 's', '', '', 'h', '', '', 'h', ''],
        march: ['k', '', 'h', '', 's', '', 'h', 'k', '', 'k', 'h', '', 's', '', 'h', 'h'],
        fill: ['s', '', 's', 's', 's', '', 's', 's', 'sh', 's', 'sh', 's', 'ks', 's', 's', 's'],
        fill2: ['k', '', 's', '', 'k', 's', 's', '', 'k', 's', 'k', 's', 's', 's', 's', 's'],
        fill3: ['s', 's', '', 's', 's', '', 's', 's', 'k', '', 'k', '', 's', 's', 'sh', 's'],
        pulse: ['k', '', '', '', 'k', '', '', '', 'k', '', '', '', 'k', '', '', ''],
        roll8: ['k', '', 's', '', 'k', '', 's', '', 'k', '', 's', '', 'k', '', 's', ''],
        roll16: ['ks', 's', 's', 's', 'ks', 's', 's', 's', 'ks', 's', 's', 's', 'ks', 's', 's', 's']
    },
    fills: ['fill', 'fill2', 'fill3'],
    sections: {
        /** The v1 loop, note for note (never edit). */
        theme: {
            chords: [[0, 3, 7], [0, 3, 7], [-4, 0, 3], [-4, 0, 3], [3, 7, 10], [3, 7, 10], [-2, 2, 5], [-5, -1, 2]],
            melody: [
                [12, null, 15, 12, 19, null, 17, 15],
                [12, 15, 19, 24, 22, 19, 17, 15],
                [12, null, 15, 12, 20, null, 19, 15],
                [17, 15, 12, 15, 17, 20, 19, 17],
                [15, null, 19, 15, 22, null, 19, 17],
                [15, 19, 22, 27, 26, 22, 19, 15],
                [14, null, 17, 14, 22, 21, 19, 17],
                [19, null, 23, 19, 26, 23, 19, 14]
            ],
            bass: 'octaves',
            arp: 'up',
            drums: 'beat',
            variation: 0.35
        },

        /** Draft 1's variation of the theme (held notes), now with a low counter answering the holds. */
        theme_b: {
            chords: [Am, Am, F, F, C, C, G, E],
            melody: [
                [12, 15, [19, 2], null, 24, 22, 19, 17],
                [15, 12, 15, 19, [22, 3], null, null, 19],
                [12, 15, [20, 2], null, 24, 20, 19, 15],
                [17, 19, 20, 24, [22, 2], null, 19, 17],
                [15, 19, [22, 2], null, 27, 26, 22, 19],
                [22, 24, 26, 27, [26, 3], null, null, 22],
                [17, 19, 22, 26, 24, 22, 21, 19],
                [[23, 2], null, 26, 23, [19, 2], null, 23, 26]
            ],
            counter: [
                [null, null, 7, 3, null, null, 7, 10],
                [null, null, null, null, 12, 10, 7, null],
                [null, null, 8, 3, null, null, 8, 12],
                [null, null, null, null, 12, 8, 5, null],
                [null, null, 7, 3, null, null, 10, 7],
                [null, null, null, null, 15, 14, 10, 7],
                [null, null, 5, 2, null, null, 5, 2],
                [null, 2, null, 7, null, 11, 7, 2]
            ],
            bass: 'octaves',
            arp: 'up',
            drums: 'beat',
            fill: true
        },

        /**
         * A lighter interlude: soft lead in long notes over Am Em F G, a plucked
         * two-octave arpeggio (the counter) and half-time drums. Its melody
         * stretches the theme's first-bar contour (A C A E, D C).
         */
        interlude: {
            chords: [Am, Em, F, G, Am, Em, F, E],
            melody: [
                [[12, 3], null, null, 15, [19, 4], null, null, null],
                [[14, 2], null, [19, 2], null, [17, 3], null, null, 14],
                [[12, 3], null, null, 15, [20, 2], null, 19, 17],
                [[14, 4], null, null, null, [17, 2], null, 19, 22],
                [[24, 3], null, null, 22, [19, 4], null, null, null],
                [[14, 2], null, [19, 2], null, [22, 3], null, null, 19],
                [[20, 3], null, null, 19, [17, 2], null, 15, 12],
                [[14, 2], null, [11, 2], null, [7, 2], null, 11, 14]
            ],
            counter: [Am, Em, F, G, Am, Em, F, E].map(harp),
            counterLead: 'arpLoud',
            lead: 'leadSoft',
            bass: 'long',
            arp: null,
            drums: 'half',
            fill: true,
            level: 0.9
        },

        /** The theme melody again, as a duet: a slow cello-like counter in long notes beneath it. */
        duet: {
            chords: [Am, Am, F, F, C, C, G, E],
            melody: [
                [12, null, 15, 12, 19, null, 17, 15],
                [12, 15, 19, 24, 22, 19, 17, 15],
                [12, null, 15, 12, 20, null, 19, 15],
                [17, 15, 12, 15, 17, 20, 19, 17],
                [15, null, 19, 15, 22, null, 19, 17],
                [15, 19, 22, 27, 26, 22, 19, 15],
                [14, null, 17, 14, 22, 21, 19, 17],
                [19, null, 23, 19, 26, 23, 19, 14]
            ],
            counter: [
                [[0, 4], null, null, null, [7, 2], null, 3, 7],
                [[12, 2], null, 10, 7, [3, 4], null, null, null],
                [[-4, 4], null, null, null, [3, 2], null, 0, 3],
                [[8, 2], null, 5, 3, [0, 4], null, null, null],
                [[3, 4], null, null, null, [10, 2], null, 7, 10],
                [[15, 2], null, 14, 12, [7, 4], null, null, null],
                [[2, 4], null, null, null, [5, 2], null, 2, 5],
                [[-1, 2], null, 2, 7, [11, 2], null, 7, 2]
            ],
            bass: 'octaves',
            arp: 'up',
            drums: 'beat',
            fill: true
        },

        /** Draft 1's bridge (Dm Dm Am Am F F E E, soft lead), with a counter that walks under every held note. */
        bridge: {
            chords: [Dm, Dm, Am, Am, F, F, E, E],
            melody: [
                [[17, 2], null, [20, 2], null, [24, 3], null, null, 22],
                [24, 22, [20, 2], null, [17, 4], null, null, null],
                [[15, 2], null, [19, 2], null, [24, 3], null, null, 22],
                [19, null, 15, null, [12, 4], null, null, null],
                [[12, 2], null, [15, 2], null, [20, 3], null, null, 19],
                [20, 19, 17, 15, [12, 2], null, 15, null],
                [[14, 2], null, [19, 2], null, [23, 2], null, [26, 2], null],
                [[23, 3], null, null, 19, [26, 2], null, 24, 23]
            ],
            counter: [
                [null, 5, null, 8, null, 12, 8, null],
                [12, null, 8, 5, 0, 5, 8, 12],
                [null, 0, null, 3, null, 7, 3, null],
                [7, null, 3, null, 0, 3, 7, 10],
                [null, -4, null, 0, null, 3, 0, null],
                [8, null, 5, 3, 0, null, -4, 0],
                [null, -5, null, -1, null, 2, null, 7],
                [-5, null, -1, 2, 7, null, 11, 7]
            ],
            lead: 'leadSoft',
            bass: 'long',
            arp: 'up',
            drums: 'half',
            fill: true,
            level: 0.9
        },

        /**
         * A driving march on the theme's first-bar rhythm (x . x x x . x x)
         * over Am G F G, with pumping bass and horn-like counter chords.
         */
        march: {
            chords: [Am, G, F, G, Am, G, F, E],
            melody: [
                [12, null, 12, 15, 19, null, 15, 12],
                [14, null, 14, 17, 19, null, 17, 14],
                [12, null, 12, 15, 20, null, 17, 15],
                [14, 17, 19, 22, 19, 17, 14, 17],
                [24, null, 24, 22, 19, null, 22, 24],
                [26, null, 26, 22, 19, null, 22, 26],
                [27, null, 24, 20, 17, null, 20, 24],
                [23, 26, 23, 19, 26, 23, 26, 29]
            ],
            counter: [Am, G, F, G, Am, G, F, E].map(horn),
            counterLead: 'counterPulse',
            bass: 'drive',
            arp: 'high',
            drums: 'march',
            fill: true,
            level: 0.95
        },

        /**
         * The rise into the climax: the theme's second-bar run (A C E A, G E D C)
         * climbs through Am C Dm F, then long notes over G and E with snare
         * rolls and a riser. Ends on E, the deceptive cadence into heroic's F.
         */
        rise: {
            chords: [Am, C, Dm, F, G, G, E, E],
            melody: [
                [12, 15, 19, 24, 22, 19, 17, 15],
                [15, 19, 22, 27, 26, 22, 19, 17],
                [17, 20, 24, 29, 27, 24, 22, 20],
                [20, 24, 27, 32, 31, 27, 24, 20],
                [[26, 2], null, [22, 2], null, [19, 2], null, 22, 26],
                [[29, 4], null, null, null, 26, 22, 19, 22],
                [[23, 2], null, [26, 2], null, [23, 2], null, 19, 23],
                [[23, 2], null, [26, 2], null, [29, 2], null, [31, 2], null]
            ],
            counter: [
                chops(Am), chops(C), chops(Dm), chops(F),
                [-2, 2, 5, 10, 2, 5, 10, 14],
                [2, 5, 10, 14, 5, 10, 14, 17],
                [-5, -1, 2, 7, -1, 2, 7, 11],
                [2, 7, 11, 14, 7, 11, 14, 19]
            ],
            counterLead: 'counterPulse',
            bass: 'drive',
            arp: 'high',
            drumBars: ['drive', 'drive', 'drive', 'drive', 'roll8', 'roll8', 'roll16', 'fill'],
            crashBars: [0, 4],
            riserBar: 4,
            variation: 0,
            level: 0.9
        },

        /**
         * The heroic climax (draft 1's): long leaping notes in a bright,
         * echoing lead over F G Am Am F G A(major) E, now with the theme's
         * rhythm pulsing in the counter under every long note.
         */
        heroic: {
            chords: [F, G, Am, Am, F, G, A, E],
            melody: [
                [[8, 3], null, null, 7, [12, 4], null, null, null],
                [[14, 3], null, null, 12, [10, 2], null, 7, 10],
                [[12, 6], null, null, null, null, null, 14, 15],
                [[19, 4], null, null, null, 17, 15, 14, 12],
                [[17, 3], null, null, 15, [20, 4], null, null, null],
                [[19, 3], null, null, 17, [22, 2], null, 19, 22],
                [[24, 6], null, null, null, null, null, 23, 21],
                [[23, 2], null, 26, 23, [19, 4], null, null, null]
            ],
            counter: [
                pulse(F), pulse(G), pulse(Am), pulse(Am, 12),
                pulse(F, 12), pulse(G, 12), pulse(A), pulse(E)
            ],
            lead: 'leadBright',
            leadOctaves: [12],
            bass: 'drive',
            arp: null,
            drums: 'drive',
            crashBars: [0, 4, 6],
            fill: true,
            variation: 0.5,
            level: 0.85
        },

        /**
         * The summit: a second heroic statement an octave higher, F G A A F G E E,
         * ending on a G#-B pickup that drops back into the theme's A.
         */
        heroic_b: {
            chords: [F, G, A, A, F, G, E, E],
            melody: [
                [[15, 3], null, null, 17, [20, 4], null, null, null],
                [[19, 3], null, null, 17, [22, 2], null, 19, 17],
                [[16, 3], null, null, 19, [24, 4], null, null, null],
                [[24, 2], null, 28, 24, [19, 4], null, null, null],
                [[27, 3], null, null, 24, [20, 4], null, null, null],
                [[26, 3], null, null, 22, [19, 2], null, 22, 26],
                [[23, 4], null, null, null, 26, 23, 19, 14],
                [[23, 2], null, 26, 23, [19, 2], null, 14, 11]
            ],
            counter: [
                pulse(F), pulse(G), pulse(A), pulse(A, 12),
                pulse(F, 12), pulse(G, 12), pulse(E, 12), pulse(E)
            ],
            lead: 'leadBright',
            leadOctaves: [12],
            bass: 'drive',
            arp: null,
            drums: 'drive',
            crashBars: [0, 2, 4, 6],
            fill: true,
            variation: 0.5,
            level: 0.85
        },

        /**
         * The build-up into the epic: rising held notes over Am Am F E with a
         * climbing counter in eighths, kick pulse -> snare rolls, and the riser.
         */
        build: {
            chords: [Am, Am, F, E],
            melody: [
                [[12, 4], null, null, null, [15, 4], null, null, null],
                [[17, 4], null, null, null, [19, 4], null, null, null],
                [[20, 4], null, null, null, [24, 4], null, null, null],
                [[23, 2], null, [26, 2], null, [29, 2], null, [31, 2], null]
            ],
            counter: [
                [0, 3, 7, 3, 0, 3, 7, 12],
                [3, 7, 12, 7, 3, 7, 12, 15],
                [-4, 0, 3, 0, -4, 0, 3, 8],
                [-5, -1, 2, 7, -1, 2, 7, 11]
            ],
            lead: 'leadSoft',
            bass: 'long',
            arp: 'up',
            drumBars: ['pulse', 'roll8', 'roll8', 'roll16'],
            riserBar: 1,
            crash: true,
            variation: 0,
            level: 0.9
        },

        epic: EPIC,

        /** The epic's answer: wide arpeggio leaps over Am Am Dm Dm F G E E. */
        epic_b: {
            chords: [Am, Am, Dm, Dm, F, G, E, E],
            melody: [
                [19, 24, 27, 24, 31, 27, 24, 19],
                [19, null, 22, 24, 27, null, 24, 22],
                [20, 24, 29, 24, 32, 29, 24, 20],
                [20, null, 22, 24, 29, null, 27, 24],
                [27, 24, 20, 24, 27, 24, 20, 15],
                [26, 22, 19, 22, 26, 22, 19, 14],
                [19, 23, 26, 23, 19, 23, 26, 29],
                [31, null, 26, 23, [19, 2], null, 23, 26]
            ],
            leadOctaves: [-12],
            bass: 'drive',
            arp: 'high',
            drums: 'drive',
            crash: true,
            fill: true,
            level: 0.85
        },

        epic_high: Object.assign({}, EPIC, { transpose: 2 }),

        /**
         * The epic's soaring peak, a step up (B minor): long leaping notes in
         * the bright lead over F G Am Am F G A(major), ending on F (G major
         * once transposed), which pivots back down to A minor for the break.
         */
        epic_peak: {
            chords: [F, G, Am, Am, F, G, A, F],
            melody: [
                [[20, 3], null, null, 19, [24, 4], null, null, null],
                [[26, 3], null, null, 24, [22, 2], null, 19, 22],
                [[24, 6], null, null, null, null, null, 26, 27],
                [[31, 4], null, null, null, 29, 27, 26, 24],
                [[27, 3], null, null, 24, [20, 4], null, null, null],
                [[22, 3], null, null, 19, [26, 2], null, 22, 26],
                [[28, 4], null, null, null, [31, 2], null, 28, 24],
                [[27, 2], null, 24, 20, [17, 2], null, 20, 22]
            ],
            counter: [
                pulse(F), pulse(G), pulse(Am, 12), pulse(Am, 12),
                pulse(F, 12), pulse(G, 12), pulse(A, 12), pulse(F)
            ],
            lead: 'leadBright',
            leadOctaves: [12],
            bass: 'drive',
            arp: null,
            drums: 'drive',
            crashBars: [0, 2, 4, 6],
            fill: true,
            transpose: 2,
            variation: 0.5,
            level: 0.85
        },

        /**
         * A breakdown that rebuilds: the theme's opening figures in half time
         * over Am Am F F Dm Dm E E, drums coming back from half to rolls and a
         * riser, ending in a run up to the epic's first note.
         */
        epic_break: {
            chords: [Am, Am, F, F, Dm, Dm, E, E],
            melody: [
                [12, null, 15, 12, [19, 4], null, null, null],
                [17, 15, [12, 2], null, [19, 2], null, 17, 15],
                [12, null, 15, 12, [20, 4], null, null, null],
                [17, 15, [12, 2], null, [20, 2], null, 19, 17],
                [17, null, 20, 17, [24, 4], null, null, null],
                [22, 20, [17, 2], null, [24, 2], null, 22, 20],
                [19, null, 23, 19, [26, 4], null, null, null],
                [23, 26, 29, 26, 23, 26, 29, 31]
            ],
            counter: [Am, Am, F, F, Dm, Dm, E, E].map(chops),
            bass: 'octaves',
            arp: 'up',
            drumBars: ['half', 'half', 'half', 'half', 'beat', 'beat', 'roll8', 'roll16'],
            crash: true,
            riserBar: 6,
            variation: 0.5,
            level: 0.85
        }
    },
    cycle: ['theme', 'theme_b', 'interlude', 'theme', 'duet', 'bridge', 'march', 'rise', 'heroic', 'heroic_b'],
    afterEpic: 1,
    calm: 'theme',
    build: 'build',
    epicCycle: ['epic', 'epic_b', 'epic_high', 'epic_peak', 'epic_break'],
    triumph: 'heroic'
};
