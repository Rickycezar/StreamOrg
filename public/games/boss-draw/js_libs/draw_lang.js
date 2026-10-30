/**
 * BossDraw texts
 *
 * Every text the draw shows, by key, in Brazilian Portuguese and English
 * (the shipped defaults). Keys are stable ids ([a-z0-9_.]): StreamOrg's
 * "Game texts" tool keeps translations by key, so a key is renamed only when
 * its meaning changes. A text comes from, in order: the overrides in use
 * (texts/overrides/<locale>.json next to the page, or the embedding config),
 * the defaults of the language, the English defaults, the key itself.
 *
 * @author Henrique Cezar
 * @license MIT
 */

/** Built-in texts, by locale. */
const DRAW_LANG = {
    'pt-BR': {
        'side.ranged': 'À distância',
        'side.melee': 'Corpo a corpo',
        'boss.goblin': 'Rei Goblin',
        'boss.orc': 'Chefe Orc',
        'boss.label': 'Chefão {n}: {name}',
        'boss.waiting': 'Aguardando heróis',
        'boss.appears': 'Chefão {n}: {name} apareceu!',
        'boss.defeated': 'Chefão {n} derrotado! Sobreviventes curados',
        'player.miss': 'ERROU!',
        'player.kick': '✕ remover?',
        'result.winner': 'Vencedor',
        'result.winners': 'Vencedores',
        'result.nobody': 'Ninguém',
        'result.meta': 'Chefões alcançados: {bosses} · {seconds} s',
        'showdown.prompt': '{names}, fale no chat antes que o chefão chegue!',
        'showdown.executed': 'EXECUTADO!',
        'showdown.lost_title': 'O prêmio se perdeu',
        'showdown.lost_line': 'Perderam o prêmio: {names}',
        'showdown.lost_panel': 'Perderam o prêmio (calados): {names}',
        'showdown.redraw': 'Sortear outro',
        'showdown.test': 'Hora de falar:',
        'showdown.present': '✔ {name} falou',
        'entries.min': 'Precisa de pelo menos {n} participantes',
        'entries.open': 'Inscrições abertas!',
        'entries.open_pick': 'Digite {command} melee (corpo a corpo) ou {command} ranged (à distância) — só {command} cai no lado menor',
        'entries.open_phrase': 'Digite "{phrase}" no chat para entrar',
        'entries.closed': 'Inscrições fechadas',
        'entries.closed_wait': 'Aguarde o streamer abrir',
        'entries.only_subs': 'Só inscritos podem entrar',
        'entries.only_subs_vips': 'Só inscritos e VIPs podem entrar',
        'page.title': 'Batalha do Chefão',
        'page.sound_gate': 'Clique para ativar o som',
        'page.speed': 'Velocidade',
        'page.panel': '⚙ Painel',
        'page.panel_result': '⚙ Resultado',
        'page.fullscreen': 'Tela cheia (F)',
        'page.fullscreen_exit': 'Sair da tela cheia (F)',
        'page.fullscreen_failed': 'Tela cheia indisponível aqui',
        'page.too_small': 'Aumente a janela ou use a tela cheia',
        'page.language': 'Idioma',
        'page.locked': 'Definido pelo StreamOrg',
        'page.giveaway': 'Sorteio: {name}',
        'tab.main': 'Partida',
        'tab.advanced': 'Avançado',
        'tab.rules': 'Regras',
        'stream.title': 'Transmissão',
        'stream.channel': 'Canal da Twitch',
        'stream.channel_hint': 'nome do canal',
        'stream.connect': 'Conectar',
        'stream.disconnected': 'Chat desconectado',
        'stream.connecting': 'Conectando a #{channel}…',
        'stream.connected': 'Conectado a #{channel}',
        'draw.title': 'Sorteio',
        'draw.prize': 'Texto no topo (o prêmio, por exemplo)',
        'draw.prize_hint': 'Ex.: Sorteio de uma chave de jogo',
        'draw.entry': 'Comando para entrar',
        'draw.entry_help': 'Com um comando que começa com ! (como !join), cada um pode escolher o lado: !join melee ou !join ranged. Com qualquer outra frase, o lado é sorteado.',
        'draw.winners': 'Vencedores',
        'draw.hearts': 'Corações por jogador',
        'draw.showdown': 'Hora de falar',
        'draw.showdown_seconds': 'segundos',
        'draw.showdown_help': 'No fim, o vencedor precisa falar no chat antes que o chefão o alcance, ou perde o prêmio.',
        'option.hearts': 'Corações por jogador',
        'option.hearts.hint': 'Quantos golpes cada jogador aguenta (1–5).',
        'option.showdown': 'Hora de falar',
        'option.showdown.hint': 'No fim, o vencedor precisa falar no chat antes que o chefão o alcance, ou perde o prêmio.',
        'option.showdown_seconds': 'Segundos para falar',
        'option.showdown_seconds.hint': 'Quanto tempo o vencedor tem para falar (10–300).',
        'option.entry_from': 'Quem pode entrar',
        'option.entry_from.hint': 'Restringe as inscrições aos inscritos (e VIPs, se quiser). A Twitch mostra no chat quem é inscrito.',
        'option.entry_from.everyone': 'Todo mundo',
        'option.entry_from.subs': 'Só inscritos',
        'option.entry_from.subs_vips': 'Inscritos e VIPs',
        'option.sub_bonus': 'Bônus para inscritos',
        'option.sub_bonus.hint': 'Corações extras (dourados) para inscritos: mais chances de sobreviver e vencer.',
        'option.sub_bonus.none': 'Nenhum',
        'option.sub_bonus.flat': 'Igual para todo inscrito',
        'option.sub_bonus.tier': 'Pelo nível (Tier 1: +1, Tier 2: +2, Tier 3: +3)',
        'option.sub_bonus_hearts': 'Corações extras por inscrito',
        'option.sub_bonus_hearts.hint': 'Usado no bônus igual para todos (1–3).',
        'group.battle': 'Batalha',
        'group.draw': 'Sorteio',
        'group.rules': 'Regras',
        'entries.title': 'Inscrições',
        'entries.counts': '{total} inscritos · {ranged} à distância · {melee} corpo a corpo',
        'entries.open_button': 'Abrir inscrições',
        'entries.start_button': 'Fechar e lutar!',
        'entries.reset_button': 'Recomeçar',
        'entries.skip': 'Pular para o resultado',
        'entries.back': 'Voltar às inscrições',
        'result.title': 'Resultado',
        'result.none': 'Ninguém venceu',
        'result.confirm': 'Confirmar',
        'result.rerun': 'Lutar de novo',
        'result.confirm_send': 'Confirmar e enviar',
        'result.confirmed': 'Resultado confirmado.',
        'result.sending': 'Enviando…',
        'result.no_answer': 'Sem resposta do StreamOrg. Tente de novo.',
        'result.fewer': 'Só {n} de {total} vencedores: não há participantes suficientes para sortear outro.',
        'sound.title': 'Som',
        'sound.mute': 'Mudo',
        'sound.effects': 'Efeitos',
        'sound.music': 'Música',
        'adv.battle': 'Batalha',
        'adv.seed': 'Semente (vazio = aleatória)',
        'adv.seed_hint': 'aleatória',
        'adv.reset_params': 'Restaurar padrões',
        'adv.tests': 'Testes',
        'adv.name_hint': 'nome',
        'adv.color': 'Cor do nome na Twitch',
        'adv.no_side': 'sem lado',
        'adv.not_sub': 'não inscrito',
        'adv.vip': 'VIP',
        'adv.join': 'Entrar',
        'adv.fakes': 'Participantes falsos',
        'adv.simulate': 'Simular chat',
        'adv.simulate_stop': 'Parar chat',
        'adv.paste': 'Colar uma lista',
        'adv.paste_hint': 'nome [#cor] [melee|ranged] [t1|t2|t3] [vip], um por linha',
        'adv.paste_add': 'Adicionar lista',
        'adv.sounds': 'Sons',
        'adv.sounds_help': 'Cada som é uma lista de números do ZzFX: crie um no editor do ZzFX e cole aqui.',
        'adv.music_play': '▶ Música',
        'adv.music_stop': '■ Música',
        'adv.reset_sounds': 'Restaurar sons',
        'adv.last_result': 'Dados do último resultado',
        'param.ranged_share': 'Parte à distância sem escolha (0–1)',
        'param.tick_ms': 'Intervalo dos ataques dos jogadores (ms)',
        'param.player_damage_min': 'Dano mínimo do jogador',
        'param.player_damage_max': 'Dano máximo do jogador',
        'param.boss_hp': 'Vida do primeiro chefão',
        'param.boss_hp_from': 'Vida do primeiro chefão é',
        'param.boss_hp_from.fixed': 'fixa',
        'param.boss_hp_from.entrants': 'por participante',
        'param.boss_hp_from.alive': 'por vivo',
        'param.boss_hp_per_entrant': 'Vida por jogador (se não fixa)',
        'param.boss_hp_growth': 'Aumento de vida por chefão',
        'param.first_attack_ms': 'Primeiro ataque após surgir (ms)',
        'param.attack_min_ms': 'Intervalo mínimo entre ataques (ms)',
        'param.attack_max_ms': 'Intervalo máximo entre ataques (ms)',
        'param.boss_focus': 'Chefão prefere o lado maior (0 = não)',
        'param.target_ratio': 'Parte atingida (0–1)',
        'param.target_base': 'Parte atingida de',
        'param.target_base.side': 'o lado',
        'param.target_base.alive': 'todos vivos',
        'param.target_base.entrants': 'participantes',
        'param.min_targets': 'Mínimo de alvos',
        'param.min_wipes_side': 'Mínimo pode atingir um lado inteiro',
        'param.min_wipes_side.yes': 'sim',
        'param.min_wipes_side.no': 'não',
        'param.respawn_ms': 'Tempo até o próximo chefão (ms)',
        'param.max_duration_ms': 'Tempo limite (ms)'
    },
    en: {
        'side.ranged': 'Ranged',
        'side.melee': 'Melee',
        'boss.goblin': 'Goblin King',
        'boss.orc': 'Orc Chieftain',
        'boss.label': 'Boss {n}: {name}',
        'boss.waiting': 'Waiting for heroes',
        'boss.appears': 'Boss {n}: {name} appears!',
        'boss.defeated': 'Boss {n} defeated! Survivors heal',
        'player.miss': 'MISS!',
        'player.kick': '✕ remove?',
        'result.winner': 'Winner',
        'result.winners': 'Winners',
        'result.nobody': 'Nobody',
        'result.meta': 'Bosses reached: {bosses} · {seconds} s',
        'showdown.prompt': '{names}, speak in chat before the boss gets you!',
        'showdown.executed': 'EXECUTED!',
        'showdown.lost_title': 'The prize was lost',
        'showdown.lost_line': 'Lost the prize: {names}',
        'showdown.lost_panel': 'Lost the prize (stayed silent): {names}',
        'showdown.redraw': 'Draw another',
        'showdown.test': 'Time to speak:',
        'showdown.present': '✔ {name} spoke',
        'entries.min': 'At least {n} entrants are needed',
        'entries.open': 'Entries open!',
        'entries.open_pick': 'Type {command} melee or {command} ranged (plain {command} goes to the smaller side)',
        'entries.open_phrase': 'Type "{phrase}" in chat to join',
        'entries.closed': 'Entries closed',
        'entries.closed_wait': 'Waiting for the streamer to open them',
        'entries.only_subs': 'Subscribers only',
        'entries.only_subs_vips': 'Subscribers and VIPs only',
        'page.title': 'Boss Battle',
        'page.sound_gate': 'Click to enable sound',
        'page.speed': 'Speed',
        'page.panel': '⚙ Panel',
        'page.panel_result': '⚙ Result',
        'page.fullscreen': 'Fullscreen (F)',
        'page.fullscreen_exit': 'Exit fullscreen (F)',
        'page.fullscreen_failed': 'Fullscreen is not available here',
        'page.too_small': 'Make the window bigger or use fullscreen',
        'page.language': 'Language',
        'page.locked': 'Set by StreamOrg',
        'page.giveaway': 'Giveaway: {name}',
        'tab.main': 'Match',
        'tab.advanced': 'Advanced',
        'tab.rules': 'Rules',
        'stream.title': 'Stream',
        'stream.channel': 'Twitch channel',
        'stream.channel_hint': 'channel name',
        'stream.connect': 'Connect',
        'stream.disconnected': 'Chat not connected',
        'stream.connecting': 'Connecting to #{channel}…',
        'stream.connected': 'Connected to #{channel}',
        'draw.title': 'Giveaway',
        'draw.prize': 'Text on top (the prize, for example)',
        'draw.prize_hint': 'E.g. Giveaway of a game key',
        'draw.entry': 'Entry command',
        'draw.entry_help': 'With a command starting with ! (like !join), everyone may pick a side: !join melee or !join ranged. With any other phrase, sides are drawn.',
        'draw.winners': 'Winners',
        'draw.hearts': 'Hearts per player',
        'draw.showdown': 'Time to speak',
        'draw.showdown_seconds': 'seconds',
        'draw.showdown_help': 'At the end, the winner must speak in chat before the boss reaches them, or they lose the prize.',
        'option.hearts': 'Hearts per player',
        'option.hearts.hint': 'How many hits each player takes (1–5).',
        'option.showdown': 'Time to speak',
        'option.showdown.hint': 'At the end, the winner must speak in chat before the boss reaches them, or they lose the prize.',
        'option.showdown_seconds': 'Seconds to speak',
        'option.showdown_seconds.hint': 'How long the winner has to speak (10–300).',
        'option.entry_from': 'Who can enter',
        'option.entry_from.hint': 'Limits entries to subscribers (and VIPs, if you like). Twitch shows in chat who is subscribed.',
        'option.entry_from.everyone': 'Everyone',
        'option.entry_from.subs': 'Subscribers only',
        'option.entry_from.subs_vips': 'Subscribers and VIPs',
        'option.sub_bonus': 'Subscriber bonus',
        'option.sub_bonus.hint': 'Extra (golden) hearts for subscribers: more chances to survive and win.',
        'option.sub_bonus.none': 'None',
        'option.sub_bonus.flat': 'Same for every subscriber',
        'option.sub_bonus.tier': 'By tier (Tier 1: +1, Tier 2: +2, Tier 3: +3)',
        'option.sub_bonus_hearts': 'Extra hearts per subscriber',
        'option.sub_bonus_hearts.hint': 'Used by the same-for-everyone bonus (1–3).',
        'group.battle': 'Battle',
        'group.draw': 'Giveaway',
        'group.rules': 'Rules',
        'entries.title': 'Entries',
        'entries.counts': '{total} entrants · {ranged} ranged · {melee} melee',
        'entries.open_button': 'Open entries',
        'entries.start_button': 'Close and fight!',
        'entries.reset_button': 'Start over',
        'entries.skip': 'Skip to the result',
        'entries.back': 'Back to entries',
        'result.title': 'Result',
        'result.none': 'Nobody won',
        'result.confirm': 'Confirm',
        'result.rerun': 'Fight again',
        'result.confirm_send': 'Confirm and send',
        'result.confirmed': 'Result confirmed.',
        'result.sending': 'Sending…',
        'result.no_answer': 'No answer from StreamOrg. Try again.',
        'result.fewer': 'Only {n} of {total} winners: not enough entrants left to draw another.',
        'sound.title': 'Sound',
        'sound.mute': 'Mute',
        'sound.effects': 'Effects',
        'sound.music': 'Music',
        'adv.battle': 'Battle',
        'adv.seed': 'Seed (empty = random)',
        'adv.seed_hint': 'random',
        'adv.reset_params': 'Restore defaults',
        'adv.tests': 'Tests',
        'adv.name_hint': 'name',
        'adv.color': 'Twitch name colour',
        'adv.no_side': 'no side',
        'adv.not_sub': 'not subscribed',
        'adv.vip': 'VIP',
        'adv.join': 'Join',
        'adv.fakes': 'Fake entrants',
        'adv.simulate': 'Simulate chat',
        'adv.simulate_stop': 'Stop chat',
        'adv.paste': 'Paste a list',
        'adv.paste_hint': 'name [#colour] [melee|ranged] [t1|t2|t3] [vip], one per line',
        'adv.paste_add': 'Add list',
        'adv.sounds': 'Sounds',
        'adv.sounds_help': 'Each sound is a ZzFX number list: design one in the ZzFX editor and paste it here.',
        'adv.music_play': '▶ Music',
        'adv.music_stop': '■ Music',
        'adv.reset_sounds': 'Restore sounds',
        'adv.last_result': 'Data of the last result',
        'param.ranged_share': 'Ranged share without a pick (0–1)',
        'param.tick_ms': 'Player attack interval (ms)',
        'param.player_damage_min': 'Player damage min',
        'param.player_damage_max': 'Player damage max',
        'param.boss_hp': 'First boss HP',
        'param.boss_hp_from': 'First boss HP is',
        'param.boss_hp_from.fixed': 'fixed',
        'param.boss_hp_from.entrants': 'per entrant',
        'param.boss_hp_from.alive': 'per survivor',
        'param.boss_hp_per_entrant': 'HP per player (if not fixed)',
        'param.boss_hp_growth': 'HP growth per boss',
        'param.first_attack_ms': 'First attack after spawning (ms)',
        'param.attack_min_ms': 'Minimum gap between attacks (ms)',
        'param.attack_max_ms': 'Maximum gap between attacks (ms)',
        'param.boss_focus': 'Boss prefers the bigger side (0 = no)',
        'param.target_ratio': 'Share hit (0–1)',
        'param.target_base': 'Share hit of',
        'param.target_base.side': 'the side',
        'param.target_base.alive': 'everyone alive',
        'param.target_base.entrants': 'entrants',
        'param.min_targets': 'Minimum targets',
        'param.min_wipes_side': 'Minimum may hit a whole side',
        'param.min_wipes_side.yes': 'yes',
        'param.min_wipes_side.no': 'no',
        'param.respawn_ms': 'Time until the next boss (ms)',
        'param.max_duration_ms': 'Time limit (ms)'
    }
};

/** Languages with built-in texts. */
const DRAW_SHIPPED = Object.keys(DRAW_LANG);

/** Current language and its validated overrides. */
const drawTexts = { lang: 'pt-BR', overrides: {} };

/**
 * Whether a value looks like a language tag (pt-BR, en, es, zh-Hant…).
 *
 * @param {*} tag
 * @returns {boolean}
 */
function drawIsLanguageTag(tag) {
    return typeof tag === 'string' && tag.length <= 35 && /^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/.test(tag);
}

/**
 * The language to use for an asked one: a shipped language (exact, any case,
 * or by its first part: pt → pt-BR), else the asked tag itself when it looks
 * valid (texts come from overrides, English for gaps), else English.
 *
 * @param {string} asked
 * @returns {string}
 */
function drawPickLanguage(asked) {
    if (!drawIsLanguageTag(asked)) {
        return 'en';
    }
    const lower = asked.toLowerCase();
    const exact = DRAW_SHIPPED.find(l => l.toLowerCase() === lower);
    if (exact) {
        return exact;
    }
    const base = lower.split('-')[0];
    return DRAW_SHIPPED.find(l => l.toLowerCase().split('-')[0] === base) || asked;
}

/**
 * The {placeholders} of a text, sorted.
 *
 * @param {string} text
 * @returns {string[]}
 */
function drawPlaceholders(text) {
    return (String(text).match(/\{\w+\}/g) || []).sort();
}

/**
 * Keeps only valid overrides: known keys, plain text up to 500 characters,
 * and every {placeholder} the default has.
 *
 * @param {*} texts - Flat object key → text (untrusted).
 * @returns {Object<string, string>}
 */
function drawCleanTexts(texts) {
    const out = {};
    if (!texts || typeof texts !== 'object' || Array.isArray(texts)) {
        return out;
    }
    for (const [key, value] of Object.entries(texts)) {
        const base = DRAW_LANG.en[key];
        if (base === undefined || typeof value !== 'string' || value.length > 500) {
            continue;
        }
        const needed = drawPlaceholders(base);
        const has = new Set(drawPlaceholders(value));
        if (needed.every(p => has.has(p))) {
            out[key] = value;
        }
    }
    return out;
}

/**
 * Switches the language of the texts.
 *
 * @param {string} lang - Language (see drawPickLanguage).
 * @param {object} [overrides] - Changed texts for it (validated here).
 */
function drawSetLanguage(lang, overrides = {}) {
    drawTexts.lang = drawPickLanguage(lang);
    drawTexts.overrides = drawCleanTexts(overrides);
    if (typeof document !== 'undefined') {
        document.documentElement.lang = drawTexts.lang;
    }
}

/**
 * Fetches a JSON file next to the page, giving up after a few seconds.
 *
 * @param {string} path - Relative path.
 * @param {number} [ms] - Timeout.
 * @returns {Promise<*>} The parsed JSON, or null on any failure.
 */
async function drawFetchJson(path, ms = 3000) {
    const abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = setTimeout(() => abort && abort.abort(), ms);
    try {
        const res = await fetch(path, { signal: abort ? abort.signal : undefined, cache: 'no-cache' });
        return res.ok ? await res.json() : null;
    } catch (e) {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

/**
 * The text overrides published for a language (texts/overrides/<lang>.json);
 * none when missing, broken or slow.
 *
 * @param {string} lang
 * @returns {Promise<Object<string, string>>}
 */
async function drawFetchOverrides(lang) {
    if (!drawIsLanguageTag(lang)) {
        return {};
    }
    return drawCleanTexts(await drawFetchJson(`texts/overrides/${lang}.json`));
}

/**
 * Every language on offer: the shipped ones plus those with published
 * overrides (texts/overrides/index.json).
 *
 * @returns {Promise<string[]>}
 */
async function drawFetchLanguages() {
    const index = await drawFetchJson('texts/overrides/index.json');
    const extra = index && Array.isArray(index.languages) ? index.languages.filter(drawIsLanguageTag).slice(0, 50) : [];
    return [...new Set([...DRAW_SHIPPED, ...extra.map(drawPickLanguage)])];
}

/**
 * A text by key, with {placeholders} filled in.
 *
 * @param {string} key
 * @param {Object<string, *>} [vars]
 * @returns {string}
 */
function drawT(key, vars = {}) {
    const local = DRAW_LANG[drawTexts.lang] || {};
    const text = drawTexts.overrides[key] ?? local[key] ?? DRAW_LANG.en[key] ?? key;
    return text.replace(/\{(\w+)\}/g, (m, name) => (name in vars ? String(vars[name]) : m));
}

/**
 * Fills the texts of a page: data-t (text), data-t-placeholder, data-t-title.
 *
 * @param {ParentNode} [root]
 */
function drawTranslatePage(root = document) {
    for (const el of root.querySelectorAll('[data-t]')) {
        el.textContent = drawT(el.dataset.t);
    }
    for (const el of root.querySelectorAll('[data-t-placeholder]')) {
        el.placeholder = drawT(el.dataset.tPlaceholder);
    }
    for (const el of root.querySelectorAll('[data-t-title]')) {
        el.title = drawT(el.dataset.tTitle);
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { DRAW_LANG, drawT, drawSetLanguage, drawCleanTexts, drawPickLanguage };
}
