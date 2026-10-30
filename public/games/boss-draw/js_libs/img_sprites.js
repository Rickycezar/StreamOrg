/**
 * SpriteAnimator
 *
 * A lightweight JavaScript library for managing spritesheet animations,
 * featuring frame control, perfect square scaling without anti-aliasing,
 * method chaining, hardware-synced playback, and per-call event hooks.
 *
 * @author Henrique Cezar
 * @date July 20, 2026
 * @license MIT - Free to use, modify, and distribute for any purpose.
 */
class SpriteAnimator {
    /**
     * @param {HTMLElement|string} container - DOM element or selector where the sprite will be inserted.
     * @param {string} imageUrl - URL of the spritesheet image.
     * @param {object} defaultSize - Default size of each frame { w: width, h: height }.
     * @param {Array} frames - Array containing frame positions { x, y, w? (optional), h? (optional) }.
     * @param {number} scale - Integer number for scale multiplier. 1 (100%), 2 (400%), 3 (900%). Default is 1.
     */
    constructor(container, imageUrl, defaultSize, frames, scale = 1) {
        this.scaleMultiplier = parseInt(scale, 10) || 1;
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        this.defaultSize = defaultSize;
        this.frames = frames;
        this.animations = {};
        this.currentAnimationId = 0;

        this.queue = [];
        this.isProcessingQueue = false;

        this.playbackRate = 1.0;
        this.isPaused = false;

        this._currentResolve = null;
        this._rafId = null;

        this.spriteElement = document.createElement('div');
        this.spriteElement.style.backgroundImage = `url('${imageUrl}')`;
        this.spriteElement.style.backgroundRepeat = 'no-repeat';
        this.spriteElement.style.display = 'inline-block';

        this.spriteElement.style.imageRendering = 'pixelated';
        this.spriteElement.style.transform = `scale(${this.scaleMultiplier})`;
        this.spriteElement.style.transformOrigin = 'top left';

        this.setFrame(0);
        this.container.appendChild(this.spriteElement);

        this._handleVisibilityChange = () => {
            this.isPaused = document.hidden;
        };
        document.addEventListener('visibilitychange', this._handleVisibilityChange);
    }

    /**
     * Updates the CSS of the element to display the correct frame.
     * @param {number} index - Index of the frame to be displayed.
     */
    setFrame(index) {
        const frame = this.frames[index];
        if (!frame) {
            return;
        }

        const width = frame.w || this.defaultSize.w;
        const height = frame.h || this.defaultSize.h;

        this.spriteElement.style.width = `${width}px`;
        this.spriteElement.style.height = `${height}px`;
        this.spriteElement.style.backgroundPosition = `-${frame.x}px -${frame.y}px`;
    }

    /**
     * Pre-programs a sequence of frames.
     * @param {string} name - Animation name.
     * @param {Array} sequence - Array of objects { frameIndex, duration }.
     * @param {boolean} loop - Whether the animation should repeat infinitely.
     */
    defineAnimation(name, sequence, loop = false) {
        this.animations[name] = { sequence, loop };
    }

    /**
     * Adjusts the speed multiplier of all animations dynamically.
     * @param {number} rate - Playback rate multiplier (e.g., 0.5 for half speed, 2.0 for double speed).
     * @returns {SpriteAnimator} The current instance.
     */
    setPlaybackRate(rate) {
        this.playbackRate = Math.max(0, rate);
        return this;
    }

    /**
     * Adds an animation to the playback queue. Returns the instance to allow method chaining.
     * @param {string} name - Animation name.
     * @param {object} [options] - Optional configuration for this specific animation call.
     * @param {Function} [options.onFrame] - Triggered on every frame change. Passes (frameIndex, animationName).
     * @param {Function} [options.onSequenceEnd] - Triggered when the sequence finishes naturally. Passes (animationName).
     * @returns {SpriteAnimator} The current instance.
     */
    playAnimation(name, options = {}) {
        this.queue.push({
            name: name,
            onFrame: options.onFrame || null,
            onSequenceEnd: options.onSequenceEnd || null
        });

        if (!this.isProcessingQueue) {
            this._processQueue();
        }
        return this;
    }

    /**
     * Internal method that processes the animation queue asynchronously.
     * @returns {Promise<void>}
     */
    async _processQueue() {
        this.isProcessingQueue = true;

        while (this.queue.length > 0) {
            const queueItem = this.queue.shift();
            const anim = this.animations[queueItem.name];

            if (!anim) {
                continue;
            }

            const finishedNaturally = await this.play(anim.sequence, anim.loop, queueItem.name, queueItem.onFrame);

            if (finishedNaturally && queueItem.onSequenceEnd) {
                queueItem.onSequenceEnd(queueItem.name);
            }
        }

        this.isProcessingQueue = false;
    }

    /**
     * Main frame loop function tied to requestAnimationFrame.
     * @param {Array} sequence - Array of objects { frameIndex, duration }.
     * @param {boolean} loop - If true, repeats the animation infinitely.
     * @param {string} animationName - The name of the animation playing.
     * @param {Function|null} onFrame - Specific callback for this queue item.
     * @returns {Promise<boolean>} Returns true if the animation finished naturally, and false if it was interrupted.
     */
    async play(sequence, loop = false, animationName = '', onFrame = null) {
        const animationId = ++this.currentAnimationId;

        do {
            for (const step of sequence) {
                if (this.currentAnimationId !== animationId) {
                    return false;
                }

                this.setFrame(step.frameIndex);

                if (onFrame) {
                    onFrame(step.frameIndex, animationName);
                }

                await this._sleep(step.duration);
            }
        } while (loop && this.currentAnimationId === animationId && this.queue.length === 0);

        return true;
    }

    /**
     * Instantly stops the current animation, clears the queue, and breaks the current frame loop.
     * @returns {SpriteAnimator} The current instance to allow chaining.
     */
    stop() {
        this.queue = [];
        this.currentAnimationId++;
        this._cancelCurrentFrame();
        return this;
    }

    /**
     * Completely removes the sprite from the DOM and clears event listeners to prevent memory leaks.
     */
    destroy() {
        this.stop();
        document.removeEventListener('visibilitychange', this._handleVisibilityChange);
        if (this.spriteElement && this.spriteElement.parentNode) {
            this.spriteElement.parentNode.removeChild(this.spriteElement);
        }
    }

    /**
     * Asynchronous hardware-synced pause helper function utilizing requestAnimationFrame and playbackRate.
     * @param {number} ms - Target duration in milliseconds.
     * @returns {Promise<void>}
     */
    _sleep(ms) {
        return new Promise(resolve => {
            this._currentResolve = resolve;

            let accumulatedTime = 0;
            let lastTimestamp = performance.now();

            const tick = (currentTimestamp) => {
                const deltaTime = currentTimestamp - lastTimestamp;
                lastTimestamp = currentTimestamp;

                if (!this.isPaused) {
                    accumulatedTime += deltaTime * this.playbackRate;
                }

                if (accumulatedTime >= ms) {
                    this._currentResolve = null;
                    resolve();
                } else {
                    this._rafId = requestAnimationFrame(tick);
                }
            };

            this._rafId = requestAnimationFrame(tick);
        });
    }

    /**
     * Cancels the currently running animation frame loop, allowing instant visual interruptions.
     */
    _cancelCurrentFrame() {
        if (this._currentResolve) {
            cancelAnimationFrame(this._rafId);
            this._currentResolve();
            this._currentResolve = null;
        }
    }
}