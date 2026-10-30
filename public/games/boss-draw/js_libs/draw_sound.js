/**
 * BossDraw sounds
 *
 * 8-bit sound effects for the draw, generated in the browser by ZzFX (no audio
 * files). Each sound is a ZzFX parameter array (the format the ZzFX designer
 * at https://killedbyapixel.github.io/ZzFX/ copies out), built once and cached;
 * a melody is one array played on a list of notes. Sounds that fire often have
 * a minimum gap so a big draw does not turn into noise.
 *
 * Volume is applied once, when a sound plays: samples are built at full level
 * (ZZFX.volume = 1 while building, as ZzFX would otherwise bake its 0.3 master
 * into them and apply it again on play), then played at HEADROOM × the page
 * volume, so the loudest default sound (1.4) stays just under clipping.
 *
 * The battle music (BATTLE_SONG_V2, played by DrawMusic from draw_music.js) runs
 * — or, during the winner's showdown, the hurry loop (HURRY_SONG) —
 * under the effects at its own volume, calm or epic as the battle goes.
 *
 * ZzFX array order: volume, randomness, frequency, attack, sustain, release,
 * shape, shapeCurve, slide, deltaSlide, pitchJump, pitchJumpTime, repeatTime,
 * noise, modulation, bitCrush, delay, sustainVolume, decay, tremolo, filter.
 *
 * @author Henrique Cezar
 * @license MIT
 */
import { ZZFX, ZZFXSound } from './vendor/zzfx/zzfx-1.3.2.js';
import { DrawMusic } from './draw_music.js';
import { BATTLE_SONG_V2 } from './music/battle_song_v2.js';
import { HURRY_SONG } from './music/hurry_theme.js';

/** Output gain at full page volume; keeps sounds up to volume 1.4 from clipping. */
const HEADROOM = 0.7;

/** Default sounds of the draw, by name. `gap` is the minimum ms between two plays. */
export const DRAW_SOUND_DEFAULTS = {
    chip: { zzfx: [.45, .2, 520, , .01, .04, 2, 1.5, -30], gap: 90 },
    windup_melee: { zzfx: [.5, 0, 110, .25, .12, .08, 2, 1, 9, , , , , , , .1, , .8] },
    windup_ranged: { zzfx: [.4, 0, 420, .2, .1, .05, 0, 1, 14, , , , .05] },
    hit_melee: { zzfx: [1.1, .1, 80, , .05, .3, 4, 2, -5, , , , , 1.5, , .3, , .6, .05] },
    hit_ranged: { zzfx: [.8, .1, 300, , .03, .2, 4, 1, -20, , , , , .8, , .2] },
    death: { zzfx: [.6, 0, 420, , .1, .35, 1, 1, -16, , , , , , , , , .5, .08], gap: 120 },
    miss: { zzfx: [.6, 0, 1200, , .05, .15, 0, 1, , , 600, .05] },
    boss_down: { zzfx: [1.4, .1, 60, .01, .3, .8, 4, 1.5, -2, , , , , 2, , .5, .1, .5, .2] },
    heal: { zzfx: [.45, 0, 500, .05, .2, .3, 0, 1, 10, , , , .06, , , , , .7] },
    spawn: { zzfx: [.9, .05, 50, .1, .4, .6, 2, 1, -1, , , , , .5, , .4, , .7, .2] },
    victory: { zzfx: [.45, 0, 440, , .08, .12, 5, .5], notes: [0, 4, 7, 12], step: 100 },
    fanfare: { zzfx: [.5, 0, 440, , .12, .2, 5, .5], notes: [0, 4, 7, 12, 7, 12, 16], step: 130 },
    coin: { zzfx: [.7, 0, 1000, , .03, .2, 1, 1.5, , , 500, .04] },
    join: { zzfx: [.4, 0, 660, , .02, .08, 1, 1, , , 220, .03], gap: 120 },
    switch: { zzfx: [.5, .1, 300, , .05, .2, 4, 1, 10, , , , , .6], gap: 120 },
    flare: { zzfx: [.8, 0, 220, .05, .35, .4, 2, 1, 18, , 300, .12, , , , , .08, .7] },
    execute: { zzfx: [1.5, .05, 45, .01, .25, .9, 4, 2, -3, , , , , 2.5, , .6, .15, .4, .1] },
    blast: { zzfx: [1.3, .05, 80, .005, .12, .6, 4, 1.8, -4, , , , , 1.6, , .4, .05, .5, .15] },
    tick: { zzfx: [.35, 0, 1600, , .01, .03, 1, 1, , , , , , , , .1], gap: 200 }
};

/**
 * Plays the draw's sounds and the battle music, with a master volume, a music
 * volume (relative to the effects) and a mute switch.
 */
export class DrawSound {
    /**
     * @param {Object<string, object>} [defs] - Sounds by name, as in DRAW_SOUND_DEFAULTS.
     */
    constructor(defs = DRAW_SOUND_DEFAULTS) {
        this.volume = 0.8;
        this.musicVolume = 0.5;
        this.muted = false;
        this.music = new DrawMusic(BATTLE_SONG_V2);
        this.hurry = new DrawMusic(HURRY_SONG);
        this.active = null;
        this.musicGain = null;
        this._stopTimer = null;
        this.lastPlayed = {};
        this.timers = new Set();
        this.setSounds(defs);
    }

    /**
     * Replaces the sounds and builds their samples.
     *
     * @param {Object<string, object>} defs - Sounds by name.
     */
    setSounds(defs) {
        this.defs = defs;
        this.cache = {};
        ZZFX.volume = 1;
        for (const [name, def] of Object.entries(defs)) {
            if (def.notes) {
                this.cache[name] = def.notes.map(n => {
                    const params = def.zzfx.slice();
                    params[2] = ZZFX.getNote(n, def.zzfx[2] || 220);
                    return new ZZFXSound(params);
                });
            }
            else {
                this.cache[name] = [new ZZFXSound(def.zzfx.slice())];
            }
        }
    }

    /**
     * Wakes the audio up; browsers only allow it after a click on the page.
     */
    unlock() {
        if (ZZFX.audioContext.state === 'suspended') {
            ZZFX.audioContext.resume();
        }
    }

    /** Whether the browser is letting sound play (false until a click, in most browsers; true in OBS). */
    audioRunning() {
        return ZZFX.audioContext.state === 'running';
    }

    /**
     * Sets the master volume.
     *
     * @param {number} volume - From 0 to 1.
     */
    setVolume(volume) {
        this.volume = Math.max(0, Math.min(1, volume));
        this._applyMusicGain();
    }

    /**
     * Sets the music volume, as a share of the master volume.
     *
     * @param {number} volume - From 0 to 1.
     */
    setMusicVolume(volume) {
        this.musicVolume = Math.max(0, Math.min(1, volume));
        this._applyMusicGain();
    }

    /**
     * Mutes or unmutes everything, the music included.
     *
     * @param {boolean} muted
     */
    setMuted(muted) {
        this.muted = muted;
        if (muted) {
            this.stop();
        }
        this._applyMusicGain();
    }

    /** Whether some music (battle or hurry) is playing. */
    get musicPlaying() {
        return !!(this.active && this.active.playing);
    }

    /** Renders the music ahead of time, a bit at a time (call once the page is idle). */
    prepareMusic() {
        this.music.prepare();
        setTimeout(() => this.hurry.prepare(), 4000);
    }

    /**
     * Sets the music's mood: 'calm' or 'epic' (switches on the next 4-bar boundary).
     *
     * @param {string} mood
     */
    setMusicMood(mood) {
        this.music.setMood(mood);
    }

    /**
     * Plays a section of the song after the current one (a boss defeat cues the song's triumph).
     *
     * @param {string} [name] - Section name; the song's triumph section when missing.
     */
    cueMusic(name) {
        this.music.cue(name || this.music.song.triumph);
    }

    /**
     * Starts a music in a loop: the battle song, or the showdown's hurry loop
     * (whatever was playing fades out quickly first).
     *
     * @param {number} [fadeMs] - Fade-in time.
     * @param {string} [which] - 'battle' or 'hurry'.
     */
    playMusic(fadeMs = 600, which = 'battle') {
        const song = which === 'hurry' ? this.hurry : this.music;
        if (this.active === song && song.playing) {
            return;
        }
        this.stopMusic(200);
        const ctx = ZZFX.audioContext;
        this.musicGain = ctx.createGain();
        this.musicGain.gain.value = 0;
        this.musicGain.connect(ctx.destination);
        song.setMood('calm');
        song.start(this.musicGain);
        this.active = song;
        this._applyMusicGain(fadeMs);
    }

    /**
     * Stops whatever music is playing.
     *
     * @param {number} [fadeMs] - Fade-out time; 0 stops at once.
     */
    stopMusic(fadeMs = 800) {
        if (!this.active || !this.active.playing) {
            return;
        }
        const ctx = ZZFX.audioContext;
        const end = ctx.currentTime + Math.max(0, fadeMs) / 1000;
        const g = this.musicGain.gain;
        g.cancelScheduledValues(ctx.currentTime);
        g.setValueAtTime(g.value, ctx.currentTime);
        g.linearRampToValueAtTime(0, end);
        this.active.stop(end + 0.05);
    }

    /**
     * Moves the music's gain to the current volume settings.
     *
     * @param {number} [fadeMs] - Ramp time.
     */
    _applyMusicGain(fadeMs = 120) {
        if (!this.active || !this.active.playing || !this.musicGain) {
            return;
        }
        const ctx = ZZFX.audioContext;
        const target = this.muted ? 0 : this.volume * this.musicVolume * HEADROOM;
        const g = this.musicGain.gain;
        g.cancelScheduledValues(ctx.currentTime);
        g.setValueAtTime(g.value, ctx.currentTime);
        g.linearRampToValueAtTime(target, ctx.currentTime + fadeMs / 1000);
    }

    /**
     * Plays a sound by name (all notes of a melody, one after the other).
     *
     * @param {string} name - Sound name.
     * @param {object} [options]
     * @param {number} [options.volume] - Volume of this play, from 0 to 1.
     * @param {number} [options.pitch] - Pitch multiplier.
     * @param {boolean} [options.force] - Ignore the sound's minimum gap.
     */
    play(name, options = {}) {
        const def = this.defs[name];
        const sounds = this.cache[name];
        if (this.muted || this.volume === 0 || !def || !sounds) {
            return;
        }
        const now = performance.now();
        if (!options.force && def.gap && now - (this.lastPlayed[name] || 0) < def.gap) {
            return;
        }
        this.lastPlayed[name] = now;
        const volume = (options.volume ?? 1) * this.volume * HEADROOM;
        const pitch = options.pitch ?? 1;
        sounds.forEach((sound, i) => {
            if (i === 0) {
                sound.play(volume, pitch);
                return;
            }
            const id = setTimeout(() => {
                this.timers.delete(id);
                if (!this.muted) {
                    sound.play(volume, pitch);
                }
            }, i * (def.step || 100));
            this.timers.add(id);
        });
    }

    /** Stops melodies that are still waiting for their next notes. */
    stop() {
        for (const id of this.timers) {
            clearTimeout(id);
        }
        this.timers.clear();
    }
}
