/**
 * BossDraw identity
 *
 * The game's name, version and the StreamOrg integration contract it
 * follows (STREAMORG_INTEGRATION.md). The version is raised on every build
 * handed over to StreamOrg; tools/export_streamorg.mjs copies it into the
 * package's manifest.json.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/**
 * Game identity, and the options a streamer picks per draw (declared in the
 * manifest so StreamOrg can offer them; labels are the texts option.<key>).
 */
const DRAW_GAME = {
    name: 'boss-draw',
    version: '1.1.5',
    contract: 1,
    options: [
        { key: 'hearts', type: 'int', min: 1, max: 5, step: 1, default: 2, group: 'battle' },
        { key: 'showdown', type: 'bool', default: false, group: 'draw' },
        { key: 'showdown_seconds', type: 'int', min: 10, max: 300, step: 5, default: 60, group: 'draw' },
        { key: 'entry_from', type: 'select', choices: ['everyone', 'subs', 'subs_vips'], default: 'everyone', group: 'rules' },
        { key: 'sub_bonus', type: 'select', choices: ['none', 'flat', 'tier'], default: 'none', group: 'rules' },
        { key: 'sub_bonus_hearts', type: 'int', min: 1, max: 3, step: 1, default: 1, group: 'rules' },
        { key: 'extra_regen', type: 'bool', default: false, group: 'rules' },
        { key: 'list_duplicates', type: 'select', choices: ['ignore', 'hearts'], default: 'ignore', group: 'rules' },
        { key: 'list_min_hearts', type: 'int', min: 1, max: 20, step: 1, default: 1, group: 'rules' },
        { key: 'list_max_hearts', type: 'int', min: 1, max: 20, step: 1, default: 5, group: 'rules' },
        { key: 'boss_custom', type: 'bool', default: false, group: 'battle' },
        { key: 'boss_name', type: 'text', maxLength: 40, default: '', group: 'battle' }
    ]
};

/**
 * An option's value checked against its declaration: the default when
 * missing or invalid.
 *
 * @param {object} option - Declaration from DRAW_GAME.options.
 * @param {*} value - Value to check (untrusted).
 * @returns {*}
 */
function drawOptionValue(option, value) {
    switch (option.type) {
        case 'bool':
            return typeof value === 'boolean' ? value : option.default;
        case 'int':
        case 'number': {
            const ok = typeof value === 'number' && Number.isFinite(value) && (option.type === 'number' || Number.isInteger(value))
                && (option.min === undefined || value >= option.min) && (option.max === undefined || value <= option.max);
            return ok ? value : option.default;
        }
        case 'select':
            return option.choices.includes(value) ? value : option.default;
        case 'text':
            return typeof value === 'string' && value.length <= (option.maxLength || 120) ? value : option.default;
        default:
            return option.default;
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { DRAW_GAME, drawOptionValue };
}
