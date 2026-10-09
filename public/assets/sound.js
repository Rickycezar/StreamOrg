/**
 * SoundLib
 *
 * A lightweight JavaScript library for managing HTML5 Audio playback,
 * featuring preloading, caching, volume control, fade transitions,
 * and polyphonic (overlapping) playback.
 *
 * Shared across StreamOrg: overlays and any page that plays sounds load
 * this one file. A sprite is one sound file cut into named segments
 * (sprite()): the file is loaded once, and each segment plays on its own.
 *
 * @author Henrique Barros
 * @date May 6, 2026
 * @license MIT - Free to use, modify, and distribute for any purpose.
 */
class SoundLib {
    /**
     * Initializes the SoundLib instance.
     *
     * @param {string} [basePath="mp3/"] - The default base directory path for audio files.
     */
    constructor(basePath = "mp3/") {
        this.basePath = basePath !== '' && !basePath.endsWith('/') ? `${basePath}/` : basePath;
        this.cache = {};
        this.activePlaybacks = new Set();
        this.fadeStepsPerSecond = 20;
    }

    /**
     * Formats the full file path for an audio asset.
     *
     * @private
     * @param {string} fileName - The filename including extension and any subfolders.
     * @returns {string} The formatted file path.
     */
    _getAudioPath(fileName) {
        return `${this.basePath}${fileName}`;
    }

    /**
     * Pre-loads audio files into memory with optional default configuration parameters.
     *
     * @param {Array<string|Object>} items - An array containing filenames (strings) or configuration objects.
     *                                       Object structure: { name, fileName, volume, start, duration, fadeIn, fadeOut }
     * @returns {void}
     */
    preload(items) {
        items.forEach(item => {
            const isObject = typeof item === 'object';
            const name = isObject ? item.name : item;
            const fileName = isObject ? (item.fileName || item.name) : item;
            const options = isObject ? item : {};

            const audio = new Audio(this._getAudioPath(fileName));
            audio.preload = "auto";
            audio.load();

            this.cache[name] = {
                audio: audio,
                options: {
                    volume: options.volume ?? 1,
                    start: options.start ?? 0,
                    duration: options.duration ?? null,
                    fadeIn: options.fadeIn ?? 0,
                    fadeOut: options.fadeOut ?? 0
                }
            };
        });
    }

    /**
     * Pre-loads one sound file cut into named segments (a sprite): the
     * file is loaded once and every segment shares it.
     *
     * @param {string} fileName - The sprite's file.
     * @param {Object<string, {start: number, duration: number, volume?: number, fadeIn?: number, fadeOut?: number}>} segments - Segment name => where it is in the file (seconds).
     * @returns {void}
     */
    sprite(fileName, segments) {
        const audio = new Audio(this._getAudioPath(fileName));
        audio.preload = "auto";
        audio.load();

        Object.keys(segments).forEach(name => {
            const options = segments[name] || {};
            this.cache[name] = {
                audio: audio,
                options: {
                    volume: options.volume ?? 1,
                    start: options.start ?? 0,
                    duration: options.duration ?? null,
                    fadeIn: options.fadeIn ?? 0,
                    fadeOut: options.fadeOut ?? 0
                }
            };
        });
    }

    /**
     * Whether a name was pre-loaded (a sound or a sprite segment).
     *
     * @param {string} name - The cache alias.
     * @returns {boolean}
     */
    has(name) {
        return Object.prototype.hasOwnProperty.call(this.cache, name);
    }

    /**
     * Plays a specified audio file or cached alias with polyphonic support.
     *
     * @param {string} nameOrFile - The cache alias, or the full filename (including extension) if not cached.
     * @param {Object} [playOptions={}] - Manual overrides for playback parameters.
     *                                    Object structure: { volume, start, duration, fadeIn, fadeOut }
     * @returns {Object} An object containing a `stop` method to halt this specific playback instance.
     */
    play(nameOrFile, playOptions = {}) {
        let defaultOptions = { volume: 1, start: 0, duration: null, fadeIn: 0, fadeOut: 0 };
        let sourceAudio;

        if (this.cache[nameOrFile]) {
            sourceAudio = this.cache[nameOrFile].audio;
            defaultOptions = this.cache[nameOrFile].options;
        } else {
            sourceAudio = new Audio(this._getAudioPath(nameOrFile));
        }

        const finalOptions = {
            volume: playOptions.volume ?? defaultOptions.volume,
            start: playOptions.start ?? defaultOptions.start,
            duration: playOptions.duration ?? defaultOptions.duration,
            fadeIn: playOptions.fadeIn ?? defaultOptions.fadeIn,
            fadeOut: playOptions.fadeOut ?? defaultOptions.fadeOut
        };

        const audioInstance = sourceAudio.cloneNode();
        audioInstance.currentTime = finalOptions.start;

        let fadeOutTriggered = false;
        let fadeInterval = null;

        const performFade = (startVol, endVol, durationSec) => {
            if (fadeInterval) clearInterval(fadeInterval);

            const intervalMs = 1000 / this.fadeStepsPerSecond;
            const totalSteps = durationSec * this.fadeStepsPerSecond;
            const volChangePerStep = (endVol - startVol) / totalSteps;
            let currentStep = 0;

            fadeInterval = setInterval(() => {
                currentStep++;
                let newVol = startVol + (volChangePerStep * currentStep);

                audioInstance.volume = Math.max(0, Math.min(1, newVol));

                if (currentStep >= totalSteps) {
                    clearInterval(fadeInterval);
                    audioInstance.volume = endVol;
                }
            }, intervalMs);
        };

        if (finalOptions.fadeIn > 0) {
            audioInstance.volume = 0;
            performFade(0, finalOptions.volume, finalOptions.fadeIn);
        } else {
            audioInstance.volume = finalOptions.volume;
        }

        const stopPlayback = () => {
            audioInstance.pause();
            audioInstance.removeEventListener('timeupdate', timeUpdateHandler);
            if (fadeInterval) clearInterval(fadeInterval);
            this.activePlaybacks.delete(stopPlayback);
        };

        stopPlayback.fadeOut = (durationSec) => {
            fadeOutTriggered = true;
            performFade(audioInstance.volume, 0, durationSec);
            setTimeout(stopPlayback, durationSec * 1000);
        };

        this.activePlaybacks.add(stopPlayback);

        const timeUpdateHandler = () => {
            const currentTime = audioInstance.currentTime;
            const targetEnd = finalOptions.duration !== null
                ? (finalOptions.start + finalOptions.duration)
                : (audioInstance.duration || Infinity);

            if (finalOptions.fadeOut > 0 && !fadeOutTriggered) {
                const fadeOutStartTime = targetEnd - finalOptions.fadeOut;

                if (currentTime >= fadeOutStartTime) {
                    fadeOutTriggered = true;
                    performFade(audioInstance.volume, 0, finalOptions.fadeOut);
                }
            }

            if (currentTime >= targetEnd) {
                stopPlayback();
            }
        };

        audioInstance.addEventListener('timeupdate', timeUpdateHandler);

        audioInstance.play().catch(err => {
            console.error("Playback failed:", err);
            this.activePlaybacks.delete(stopPlayback);
        });

        return { stop: stopPlayback };
    }

    /**
     * Stops all currently overlapping audio tracks, instantly or with a fade out.
     *
     * @param {number} [fadeOutSec=0] - Fade out duration in seconds. 0 stops instantly.
     * @returns {void}
     */
    stop(fadeOutSec = 0) {
        if (fadeOutSec > 0) {
            this.activePlaybacks.forEach(stopFn => stopFn.fadeOut(fadeOutSec));
            return;
        }
        this.activePlaybacks.forEach(stopFn => stopFn());
        this.activePlaybacks.clear();
    }

    /**
     * Clears all preloaded audio files from the cache and forces the browser
     * to release them from memory.
     *
     * @returns {void}
     */
    clearCache() {
        this.stop();

        for (const key in this.cache) {
            if (this.cache.hasOwnProperty(key)) {
                const cachedAudio = this.cache[key].audio;
                if (cachedAudio) {
                    cachedAudio.pause();
                    cachedAudio.removeAttribute('src');
                    cachedAudio.load();
                }
            }
        }
        this.cache = {};
    }
}