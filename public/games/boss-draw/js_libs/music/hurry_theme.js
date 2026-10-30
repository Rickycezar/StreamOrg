/**
 * Hurry theme (2026-10-30): the ominous loop of the winner's showdown, while
 * the boss closes in and the winner must speak in chat. Same format as
 * BATTLE_SONG (battle_theme.js), played by DrawMusic: one 8-bar section in
 * the same key and tempo as the battle, built on diminished and half-step
 * chords, a heartbeat kick, a driving 16th-note bass, and a held lead that
 * creeps up a half step every two bars.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/** The showdown's loop. */
export const HURRY_SONG = {
    bpm: 150,
    instruments: {
        lead: { root: 220, zzfx: [.3, 0, 220, .02, .2, .12, 5, .35] },
        bass: { root: 110, zzfx: [.55, 0, 110, .005, .08, .04, 2, 1, , , , , , , , .2] },
        bassLong: { root: 110, zzfx: [.5, 0, 110, .01, .3, .15, 1] },
        arp: { root: 440, zzfx: [.12, 0, 440, .002, .03, .03, 5, .2] },
        counter: { root: 220, zzfx: [.24, 0, 220, .005, .1, .08, 1] }
    },
    drums: {
        k: [.95, 0, 90, .001, .03, .16, 0, 1, -10],
        s: [.35, 0, 300, .001, .03, .1, 4, 1],
        h: [.1, 0, 5000, .001, .004, .025, 4, 1],
        c: [.25, 0, 900, .001, .05, .9, 4, 1]
    },
    drumPatterns: {
        heartbeat: ['k', 'h', 'k', 'h', '', 'h', '', 'h', 'k', 'h', 'k', 'h', 's', 'h', '', 'h'],
        fill: ['k', 'h', 'k', 'h', 's', 'h', 's', 'h', 'k', 's', 'k', 's', 's', 's', 's', 's']
    },
    fills: ['fill'],
    sections: {
        hurry: {
            chords: [[0, 3, 6], [1, 4, 7], [0, 3, 6], [1, 4, 7], [3, 6, 9], [4, 7, 10], [6, 9, 12], [5, 8, 11]],
            melody: [
                [[12, 4], null, null, null, [13, 4], null, null, null],
                [[12, 4], null, null, null, [13, 2], null, 12, 13],
                [[15, 4], null, null, null, [16, 4], null, null, null],
                [[15, 4], null, null, null, [16, 2], null, 15, 16],
                [[18, 4], null, null, null, [19, 4], null, null, null],
                [[18, 4], null, null, null, [19, 2], null, 18, 19],
                [[21, 4], null, null, null, [22, 4], null, null, null],
                [[21, 2], null, 20, 19, [18, 2], null, 17, 16]
            ],
            counter: [
                [0, null, 1, null, 0, null, 1, null],
                [0, null, 1, null, 3, null, 1, null],
                [3, null, 4, null, 3, null, 4, null],
                [3, null, 4, null, 6, null, 4, null],
                [6, null, 7, null, 6, null, 7, null],
                [6, null, 7, null, 9, null, 7, null],
                [9, null, 10, null, 9, null, 10, null],
                [9, null, 8, null, 7, null, 6, null]
            ],
            bass: 'drive',
            arp: 'up',
            drums: 'heartbeat',
            fill: true,
            variation: 0.4
        }
    },
    cycle: ['hurry'],
    afterEpic: 0,
    calm: 'hurry',
    epicCycle: ['hurry']
};
