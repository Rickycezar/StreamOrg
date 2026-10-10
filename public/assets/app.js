/* StreamOrg — progressive enhancement, no framework. */
(function () {
    'use strict';

    function loadGlobals() {
        document.querySelectorAll('script[type="application/json"][data-globals]').forEach(function (el) {
            try {
                Object.assign(window, JSON.parse(el.textContent));
            } catch (e) { }
        });
    }

    loadGlobals();

    const base  = window.BASE_URL || '/';

    function csrf() {
        return window.CSRF_TOKEN || '';
    }

    function url(path) {
        return base.replace(/\/$/, '') + path;
    }

    async function postJson(path, data) {
        const body = new URLSearchParams(data);
        body.set('_token', csrf());

        const response = await fetch(url(path), {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
            body: body
        });

        try {
            return await response.json();
        } catch (e) {
            return { ok: false, error: 'HTTP ' + response.status };
        }
    }

    const pageInits = [];

    function onPage(fn) {
        pageInits.push(fn);
    }

    const PICKERS = {
        games:      { endpoint: '/pickers/games', label: 'title' },
        publishers: { endpoint: '/pickers/companies?type=publishers', label: 'name' },
        developers: { endpoint: '/pickers/companies?type=developers', label: 'name' },
        categories: { endpoint: '/pickers/twitch-categories', label: 'name' },
        users:      { endpoint: '/pickers/users', label: 'name' },
    };

    /** Lets the content form's title helper tag a game the picker loaded. */
    function rememberGame(item) {
        window.STREAMORG_GAMES = window.STREAMORG_GAMES || {};
        window.STREAMORG_GAMES[String(item.id)] = {
            developer: item.developer || null,
            publisher: item.publisher || null,
        };
    }

    function pickerOption(kind, item, escape) {
        const L = window.STREAMORG_L || {};

        if (kind === 'games') {
            const studios = [item.developer, item.publisher]
                .filter(function (v, i, all) { return v && all.indexOf(v) === i; })
                .join(' · ');

            return '<div class="picker-option">'
                + (item.cover ? '<img class="picker-cover" src="' + escape(item.cover) + '" alt="">'
                              : '<span class="picker-cover"></span>')
                + '<span class="picker-text"><span class="picker-title">' + escape(item.title) + '</span>'
                + (item.year ? ' <span class="picker-year">' + escape(String(item.year)) + '</span>' : '')
                + (studios ? '<small class="picker-sub">' + escape(studios) + '</small>' : '')
                + '</span></div>';
        }

        if (kind === 'categories') {
            return '<div class="picker-option">'
                + (item.cover ? '<img class="picker-cover boxart" src="' + escape(item.cover) + '" alt="">'
                              : '<span class="picker-cover boxart"></span>')
                + '<span class="picker-text"><span class="picker-title">' + escape(item.name) + '</span></span></div>';
        }

        return '<div class="picker-option"><span class="picker-text"><span class="picker-title">'
            + escape(item.name) + '</span>'
            + (item.games !== undefined ? '<small class="picker-sub">' + escape((L.picker_games || '%d').replace('%d', item.games)) + '</small>' : '')
            + '</span></div>';
    }

    function initPicker(select) {
        if (select.tomselect || !window.TomSelect) return;

        const kind = select.dataset.picker;
        const conf = PICKERS[kind];
        if (!conf) return;

        const L = window.STREAMORG_L || {};

        const picker = new window.TomSelect(select, {
            valueField: 'id',
            labelField: conf.label,
            searchField: [conf.label],
            maxOptions: 30,
            preload: 'focus',
            loadThrottle: 250,
            allowEmptyOption: false,
            plugins: select.multiple ? ['remove_button'] : (select.required ? [] : { clear_button: { title: L.picker_clear || '' } }),
            placeholder: select.dataset.placeholder || L.picker_search || '',
            setFirstOptionActive: true,
            onType: function (text) {
                this.wrapper.classList.toggle('typing', text.length > 0);
            },
            onDropdownClose: function () {
                this.wrapper.classList.remove('typing');
            },
            load: async function (query, callback) {
                const glue = conf.endpoint.indexOf('?') === -1 ? '?' : '&';
                const response = await fetch(url(conf.endpoint) + glue + new URLSearchParams({ q: query }), {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json().catch(function () { return { ok: false }; });

                if (!data.ok) {
                    callback();
                    return;
                }

                if (kind === 'games') data.items.forEach(rememberGame);
                callback(data.items);
            },
            render: {
                option: function (item, escape) { return pickerOption(kind, item, escape); },
                item: function (item, escape) {
                    const label = escape(item[conf.label] || item.text || '');

                    return (kind === 'games' || kind === 'categories') && item.cover
                        ? '<div class="picker-item"><img class="picker-cover' + (kind === 'categories' ? ' boxart' : '') + '" src="' + escape(item.cover) + '" alt="">' + label + '</div>'
                        : '<div>' + label + '</div>';
                },
                no_results: function (data, escape) {
                    return '<div class="no-results">' + escape(L.picker_none || '—') + '</div>';
                },
                loading: function () {
                    return '<div class="spinner"></div>';
                },
            },
        });

        completePickerValue(picker.input);
    }

    /**
     * A preselected game arrives as a bare <option> with only its title.
     * Ask for the rest once, so the chosen game shows its image too.
     */
    async function completePickerValue(select) {
        const ts = select.tomselect;
        const value = select.value;

        if (!ts || select.dataset.picker !== 'games' || !value || (ts.options[value] || {}).cover !== undefined) return;

        const response = await fetch(url('/pickers/games') + '?' + new URLSearchParams({ ids: value }), {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json().catch(function () { return { ok: false }; });
        const item = data.ok ? data.items[0] : null;

        if (item && ts.options[value]) {
            rememberGame(item);
            ts.updateOption(value, item);
        }
    }

    function initPickers(root) {
        root.querySelectorAll('select[data-picker]').forEach(function (select) {
            if (!select.closest('tr.editrow')) initPicker(select);
        });
    }

    /** Sets a picker (or a plain select) to a value it may not have yet. */
    function setPickerValue(select, item) {
        if (select.tomselect) {
            select.tomselect.addOption(item);
            select.tomselect.setValue(String(item.id));
            return;
        }

        let option = select.querySelector('option[value="' + item.id + '"]');

        if (!option) {
            option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.title || item.name;
            select.append(option);
        }

        select.value = String(item.id);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    onPage(function () { initPickers(document); });

    document.addEventListener('click', function (event) {
        const field = event.target.closest('[data-select-on-click]');
        if (field && typeof field.select === 'function') field.select();
    });

    document.addEventListener('change', function (event) {
        const field = event.target.closest('[data-autosubmit]');
        if (field && field.form) field.form.requestSubmit();
    });

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-toggle]');
        if (!toggle) return;

        const target = document.querySelector(toggle.dataset.toggle);
        if (target) target.classList.toggle('hidden');
    });

    const GIVEAWAY_STATUSES = ['for_giveaway', 'given_away'];

    document.addEventListener('change', async function (event) {
        const select = event.target.closest('.status-select');
        if (!select || select.classList.contains('content-status')) return;

        const previous = select.dataset.previous || select.dataset.initial || select.value;
        const payload = { id: select.dataset.id, status: select.value };

        if (select.dataset.embargo && GIVEAWAY_STATUSES.indexOf(select.value) !== -1) {
            const L = window.STREAMORG_L || {};
            const ok = await confirmModal(
                L.embargo_giveaway_title || 'Warning',
                (L.embargo_giveaway || '%s is under embargo until %s.')
                    .replace('%s', select.dataset.game)
                    .replace('%s', select.dataset.embargo)
            );

            if (!ok) { select.value = previous; return; }
            payload.confirm = '1';
        }

        select.disabled = true;
        const result = await postJson('/keys/status', payload);
        select.disabled = false;

        if (!result.ok) {
            select.value = previous;

            if (result.confirm) {
                const ok = await confirmModal(result.title || '', result.error || '');

                if (ok) {
                    payload.confirm = '1';
                    select.value = payload.status;
                    select.disabled = true;
                    const retry = await postJson('/keys/status', payload);
                    select.disabled = false;

                    if (retry.ok) {
                        select.dataset.previous = select.value;
                        select.className = 'status-select status-' + retry.status;
                        return;
                    }

                    select.value = previous;
                }

                return;
            }

            alert(result.error || 'Error');
            return;
        }

        select.dataset.previous = select.value;
        select.className = 'status-select status-' + result.status;
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.test-provider');
        if (!button) return;

        const section = button.closest('.provider');
        const output  = section.querySelector('.test-result');

        button.disabled = true;
        output.textContent = '…';

        const result = await postJson('/admin/api/test', { provider: button.dataset.provider });

        button.disabled = false;
        output.textContent = (result.ok ? '✔ ' : '✗ ') + (result.note || result.error || '');
    });

    onPage(function () {
        const searchInput = document.getElementById('import-search');

        if (searchInput) {
            const providerSelect = document.getElementById('import-provider');
            const resultsBox     = document.getElementById('import-results');
            let timer = null;
            let sequence = 0;

            async function runSearch() {
                const term = searchInput.value.trim();

                if (term.length < 2) {
                    resultsBox.innerHTML = '';
                    return;
                }

                const mine = ++sequence;
                resultsBox.innerHTML = skeletonRows(3);

                const params = new URLSearchParams({ provider: providerSelect.value, q: term });
                const response = await fetch(url('/admin/import/search') + '?' + params, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json().catch(() => ({ ok: false }));

                if (mine !== sequence) return;

                if (!data.ok) {
                    resultsBox.innerHTML = '';
                    const empty = document.createElement('p');
                    empty.className = 'empty';
                    empty.textContent = data.error || 'Error';
                    resultsBox.append(empty);
                    return;
                }

                if (!data.results.length) {
                    resultsBox.innerHTML = '<p class="empty">—</p>';
                    return;
                }

                resultsBox.innerHTML = '';

                data.results.forEach(function (item) {
                    const row = document.createElement('div');
                    row.className = 'result';

                    const img = document.createElement('img');
                    img.alt = '';
                    img.loading = 'lazy';
                    if (item.image) img.src = item.image;

                    const title = document.createElement('span');
                    title.className = 'title';
                    title.textContent = item.title;

                    const year = document.createElement('span');
                    year.className = 'year';
                    year.textContent = item.year || '';

                    const action = document.createElement('button');
                    action.type = 'button';
                    action.className = 'btn small';
                    action.textContent = '+';
                    action.addEventListener('click', async function () {
                        action.disabled = true;
                        action.textContent = '…';

                        const result = await postJson('/admin/import/game', {
                            provider: providerSelect.value,
                            ref: item.ref
                        });

                        action.textContent = result.ok ? '✔' : '✗';
                        if (result.ok) {
                            row.classList.add('done');
                            title.textContent = item.title + ' — ' + result.message;
                        } else {
                            action.disabled = false;
                            alert(result.error || 'Error');
                        }
                    });

                    row.append(img, title, year, action);
                    resultsBox.append(row);
                });
            }

            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(runSearch, 300);
            });

            providerSelect.addEventListener('change', runSearch);
        }
    });

    onPage(function () {
        const contentGame = document.getElementById('content-game');

        if (contentGame) {
            const keySelect = document.getElementById('content-key');
            const keys = window.STREAMORG_KEYS || [];

            function refreshKeys() {
                const gameId = parseInt(contentGame.value, 10);
                const matching = keys.filter(function (k) { return k.game_id === gameId; });

                keySelect.innerHTML = '';

                const none = document.createElement('option');
                none.value = '';
                none.textContent = matching.length
                    ? keySelect.dataset.noneLabel || '—'
                    : (gameId ? (keySelect.dataset.emptyLabel || '—') : '—');
                keySelect.append(none);

                matching.forEach(function (k) {
                    const option = document.createElement('option');
                    option.value = k.id;
                    option.textContent = k.label;
                    keySelect.append(option);
                });

                keySelect.disabled = !gameId;

                const embargoField = document.getElementById('content-embargo');

                if (embargoField) {
                    const stored = (window.STREAMORG_EMBARGOES || {})[gameId];
                    embargoField.value = stored || embargoField.dataset.default || '';
                    embargoField.disabled = !gameId;
                }
            }

            contentGame.addEventListener('change', refreshKeys);
            refreshKeys();

            const keySave = document.getElementById('inline-key-save');

            if (keySave) {
                keySave.addEventListener('click', async function () {
                    const output = document.getElementById('inline-key-result');
                    const gameId = contentGame.value;
                    const code = document.getElementById('inline-key-code').value.trim();

                    if (!gameId || !code) {
                        output.textContent = '!';
                        return;
                    }

                    keySave.disabled = true;
                    output.textContent = '…';

                    const result = await postJson('/keys/quick', {
                        game_id: gameId,
                        game_platform: document.getElementById('inline-key-platform').value,
                        key_platform: document.getElementById('inline-key-source').value,
                        key_type: document.getElementById('inline-key-type').value,
                        content_type: document.getElementById('inline-key-content').value,
                        status: document.getElementById('inline-key-status').value,
                        expires_at: document.getElementById('inline-key-expires').value,
                        key_code: code
                    });

                    keySave.disabled = false;

                    if (!result.ok) {
                        output.textContent = '✗ ' + (result.error || '');
                        return;
                    }

                    keys.push({ id: result.id, game_id: result.game_id, label: result.label });
                    refreshKeys();
                    keySelect.value = String(result.id);

                    document.getElementById('inline-key-code').value = '';
                    output.textContent = '✔';
                });
            }
        }
    });

    /** Marks a Twitch search result whose game the catalogue already has. */
    function knownBadge(title, item) {
        if (!item.known) return;

        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = (window.STREAMORG_L || {}).in_catalog || '✔';
        title.append(' ', badge);
    }

    onPage(function () {
        document.querySelectorAll('[data-game-import]').forEach(function (box) {
            const gameSelect = document.querySelector(box.dataset.gameImport);
            const gameSearch = box.querySelector('.game-import-search');
            const resultsBox = box.querySelector('.game-import-results');

            if (!gameSelect || !gameSearch || !resultsBox) return;

            let timer = null;
            let sequence = 0;

            gameSearch.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') event.preventDefault();
            });

            gameSearch.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(async function () {
                    const term = gameSearch.value.trim();

                    if (term.length < 2) {
                        resultsBox.innerHTML = '';
                        return;
                    }

                    const mine = ++sequence;
                    resultsBox.innerHTML = skeletonRows(3);

                    const params = new URLSearchParams({ q: term });
                    const response = await fetch(url('/catalog/search') + '?' + params, {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json().catch(function () { return { ok: false }; });

                    if (mine !== sequence) return;

                    if (!data.ok || !data.results.length) {
                        resultsBox.innerHTML = '<p class="empty">—</p>';
                        return;
                    }

                    resultsBox.innerHTML = '';

                    data.results.slice(0, 8).forEach(function (item) {
                        const row = document.createElement('div');
                        row.className = 'result';

                        const img = document.createElement('img');
                        img.alt = '';
                        img.loading = 'lazy';
                        if (item.image) img.src = item.image;

                        const title = document.createElement('span');
                        title.className = 'title';
                        title.textContent = item.title;
                        knownBadge(title, item);

                        const action = document.createElement('button');
                        action.type = 'button';
                        action.className = 'btn small';
                        action.textContent = '+';
                        action.addEventListener('click', async function () {
                            action.disabled = true;
                            action.textContent = '…';

                            const result = await postJson('/catalog/import', { ref: item.ref });

                            if (!result.ok) {
                                action.disabled = false;
                                action.textContent = '✗';
                                return;
                            }

                            action.textContent = '✔';

                        const imported = {
                            id: result.id,
                            title: result.title,
                            developer: result.developer || null,
                            publisher: result.publisher || null,
                        };

                        rememberGame(imported);
                        setPickerValue(gameSelect, imported);
                        });

                        row.append(img, title, action);
                        resultsBox.append(row);
                    });
                }, 300);
            });
        });
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.twitch-push');
        if (!button) return;

        const T = window.STREAMORG_TWITCH_L || {};
        const id = button.dataset.id;
        let category = '';

        async function render() {
            const body = openModal(T.heading || 'Twitch');
            if (!body) return;
            modalSkeleton(body);

            const params = new URLSearchParams({ id: id, category: category });
            const response = await fetch(url('/content/twitch') + '?' + params, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json().catch(function () { return { ok: false }; });

            body.innerHTML = '';
            modal.querySelector('#modal-title').textContent = T.heading || 'Twitch';

            if (!data.ok) {
                const box = document.createElement('div');
                box.className = 'detail-problems';
                box.textContent = data.error || 'Error';
                body.append(box);
                return;
            }

            const plan = data.plan;
            const el = function (tag, cls, text) {
                const node = document.createElement(tag);
                if (cls) node.className = cls;
                if (text !== undefined) node.textContent = text;
                return node;
            };

            body.append(el('p', 'muted small', (T.channel || '') + ' twitch.tv/' + plan.login));

            section(body, T.title);
            body.append(el('p', 'twitch-title', plan.title));
            const count = el('p', 'small ' + (plan.too_long ? 'twitch-over' : 'muted'),
                plan.title_length + '/' + plan.title_max);
            body.append(count);
            if (plan.too_long) {
                body.append(el('div', 'detail-problems',
                    (T.too_long || '%d/%d').replace('%d', plan.title_length).replace('%d', plan.title_max)));
            }
            body.append(el('p', 'muted small', (T.now || '') + ' ' + (plan.current_title || '—')));

            section(body, T.category);
            const cat = plan.category;

            if (cat.state === 'choose') {
                const select = el('select', 'twitch-category');
                const keep = el('option', '', (T.keep || 'Keep') + ' (' + (plan.current_category || '—') + ')');
                keep.value = 'keep';
                select.append(keep);

                cat.candidates.forEach(function (c) {
                    const option = el('option', '', c.name);
                    option.value = c.id;
                    select.append(option);
                });

                body.append(el('p', 'small', cat.candidates.length
                    ? (T.choose || '') + ' ' + (plan.game_title || '')
                    : (T.none_found || '') + ' ' + (plan.game_title || '')));
                body.append(select);
                category = category || (cat.candidates.length ? cat.candidates[0].id : 'keep');
                select.value = category;
                select.addEventListener('change', function () { category = select.value; });
            } else if (cat.state === 'set') {
                body.append(el('p', 'twitch-title', cat.name));
                body.append(el('p', 'muted small', (T.now || '') + ' ' + (plan.current_category || '—')));
            } else {
                body.append(el('p', 'muted', (T.keep || 'Keep') + ' (' + (plan.current_category || '—') + ')'));
            }

            section(body, T.tags);
            const tags = el('div', 'twitch-tags');

            plan.tags.result.forEach(function (tag) {
                const added = plan.tags.added.indexOf(tag) !== -1;
                const chip = el('span', 'badge ' + (added ? 'ok' : ''), tag);
                if (added) chip.title = T.added || '';
                tags.append(chip);
            });

            plan.tags.removed.forEach(function (tag) {
                const chip = el('span', 'badge twitch-removed', tag);
                chip.title = T.removed || '';
                tags.append(chip);
            });

            if (!plan.tags.result.length && !plan.tags.removed.length) {
                tags.append(el('span', 'muted', T.no_tags || '—'));
            }

            body.append(tags);

            const actions = el('div', 'confirm-actions');
            const cancel = el('button', 'btn', T.cancel || 'Cancel');
            cancel.type = 'button';
            cancel.addEventListener('click', closeModal);

            const send = el('button', 'btn primary', T.send || 'Send');
            send.type = 'button';
            send.disabled = plan.too_long;

            send.addEventListener('click', async function () {
                send.disabled = true;
                send.textContent = '…';

                const result = await postJson('/content/twitch', { id: id, category: category });

                if (!result.ok) {
                    send.disabled = false;
                    send.textContent = T.send || 'Send';
                    const box = el('div', 'detail-problems', result.error || 'Error');
                    actions.before(box);
                    return;
                }

                body.innerHTML = '';
                body.append(el('div', 'flash flash-success', result.message));
                const close = el('button', 'btn', 'OK');
                close.type = 'button';
                close.addEventListener('click', function () {
                    closeModal(true);
                    if (window.Turbo) window.Turbo.visit(location.href, { action: 'replace' });
                });
                body.append(close);
                button.textContent = '✔ ' + (T.heading || '');
            });

            actions.append(cancel, send);
            body.append(actions);
        }

        render();
    });

    const loadedScripts = {};

    function loadScript(src) {
        if (!loadedScripts[src]) {
            loadedScripts[src] = new Promise(function (resolve, reject) {
                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = reject;
                document.head.append(script);
            });
        }

        return loadedScripts[src];
    }

    let planner = null;

    function destroyPlanner() {
        if (!planner) return;
        planner.draggable.destroy();
        planner.calendar.destroy();
        planner = null;
    }

    /** "2026-10-05T20:00:00": the calendar's UTC is the user's wall clock. */
    function wallTime(date) {
        return date.toISOString().slice(0, 19);
    }

    function plannerEventContent(arg) {
        const props = arg.event.extendedProps;
        const wrap = document.createElement('div');
        wrap.className = 'planner-event';

        if (props.thumb && arg.view.type !== 'dayGridMonth') {
            const img = document.createElement('img');
            img.className = 'picker-cover';
            img.src = props.thumb;
            img.alt = '';
            wrap.append(img);
        }

        const text = document.createElement('span');
        text.className = 'planner-event-text';

        const title = document.createElement('span');
        title.className = 'planner-event-title';
        title.textContent = (props.together ? '👥 ' : '') + (arg.timeText ? arg.timeText + ' ' : '') + arg.event.title;
        text.append(title);

        if (arg.view.type !== 'dayGridMonth' && props.games) {
            const games = document.createElement('small');
            games.textContent = props.games;
            text.append(games);
        }

        wrap.append(text);

        if (props.warnings && props.warnings.length) {
            wrap.title = props.warnings.join(' · ');
        }

        return { domNodes: [wrap] };
    }

    onPage(async function () {
        const root = document.querySelector('[data-planner]');
        if (!root) return;

        destroyPlanner();

        try {
            await loadScript(root.dataset.fcSrc);
            if (root.dataset.fcLocaleSrc) await loadScript(root.dataset.fcLocaleSrc);
        } catch (e) {
            return;
        }

        if (!root.isConnected || !window.FullCalendar) return;

        const L = window.STREAMORG_L || {};
        const backlog = root.querySelector('#planner-backlog');
        const list = backlog.querySelector('.backlog-list');
        const schedule = JSON.parse(root.dataset.schedule || '{}');
        const defaultStart = root.dataset.defaultStart || '20:00';
        const contentMinutes = parseInt(root.dataset.contentMinutes, 10) || 120;

        /** "HH:MM" from the stream schedule for a calendar date (UTC wall time). */
        function scheduledStart(date) {
            const weekday = date.getUTCDay() || 7;
            return (schedule[weekday] && schedule[weekday].start) || defaultStart;
        }

        /** The schedule as FullCalendar business hours: off-hours are shaded. */
        function businessHours() {
            const hours = [];

            Object.keys(schedule).forEach(function (weekday) {
                const day = schedule[weekday];
                const dow = parseInt(weekday, 10) % 7;
                let end = day.end;

                if (!end) {
                    const h = Math.min(parseInt(day.start, 10) + 2, 24);
                    end = String(h).padStart(2, '0') + day.start.slice(2);
                }

                if (end > day.start) {
                    hours.push({ daysOfWeek: [dow], startTime: day.start, endTime: end });
                } else {
                    hours.push({ daysOfWeek: [dow], startTime: day.start, endTime: '24:00' });
                    if (end !== '00:00') hours.push({ daysOfWeek: [(dow + 1) % 7], startTime: '00:00', endTime: end });
                }
            });

            return hours.length ? hours : false;
        }

        function refreshBacklog() {
            const items = list.querySelectorAll('.backlog-item').length;
            backlog.querySelector('[data-backlog-count]').textContent = '(' + items + ')';
            list.querySelector('.backlog-empty').classList.toggle('hidden', items > 0);
        }

        /** Keeps the content list under the calendar in step. */
        function updateListRow(id, label) {
            const cell = document.querySelector('tr[data-content-id="' + id + '"] td[data-col="scheduled"]');
            if (!cell) return;

            if (label) {
                cell.textContent = label;
            } else {
                cell.innerHTML = '';
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = L.undated || '—';
                cell.append(badge);
            }
        }

        function backlogItem(item) {
            const el = document.createElement('div');
            el.className = 'backlog-item';
            el.dataset.id = item.id;
            el.dataset.event = JSON.stringify(item);

            const cover = document.createElement(item.extendedProps.thumb ? 'img' : 'span');
            cover.className = 'picker-cover';
            if (item.extendedProps.thumb) { cover.src = item.extendedProps.thumb; cover.alt = ''; }

            const text = document.createElement('span');
            text.className = 'backlog-text';
            const title = document.createElement('span');
            title.className = 'backlog-title';
            title.textContent = item.title;
            const sub = document.createElement('small');
            sub.className = 'muted';
            sub.textContent = item.extendedProps.games || '—';
            text.append(title, sub);

            el.append(cover, text);
            return el;
        }

        /**
         * Where content dropped on a day goes: right after the day's last
         * item, or at the usual start time when the day is still empty.
         */
        function slotFor(date, exceptId) {
            const day = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
            const key = wallTime(day).slice(0, 10);
            let latest = null;

            calendar.getEvents().forEach(function (other) {
                if (other.id === String(exceptId) || !other.start) return;
                if (wallTime(other.start).slice(0, 10) !== key) return;

                const end = other.end || new Date(other.start.getTime() + contentMinutes * 60000);
                if (!latest || end > latest) latest = end;
            });

            if (latest) return latest;

            const hm = scheduledStart(day).split(':');
            return new Date(day.getTime() + (parseInt(hm[0], 10) * 60 + parseInt(hm[1], 10)) * 60000);
        }

        function minutesOf(event) {
            return event.end ? Math.round((event.end.getTime() - event.start.getTime()) / 60000) : contentMinutes;
        }

        async function save(id, start, minutes) {
            const payload = { id: id, start: start };
            if (minutes) payload.minutes = minutes;

            const result = await postJson('/content/schedule', payload);

            if (!result.ok) {
                alert(result.error || 'Error');
                return null;
            }

            updateListRow(id, result.label);
            return result;
        }

        const calendar = new window.FullCalendar.Calendar(root.querySelector('#planner-calendar'), {
            timeZone: 'UTC',
            now: root.dataset.now,
            locale: root.dataset.locale,
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
            height: 'auto',
            stickyHeaderDates: false,
            events: root.dataset.eventsUrl,
            editable: true,
            eventDurationEditable: true,
            defaultTimedEventDuration: { minutes: contentMinutes },
            droppable: true,
            dayMaxEvents: 3,
            navLinks: true,
            nowIndicator: true,
            businessHours: businessHours(),
            allDaySlot: false,
            slotDuration: '00:30:00',
            snapDuration: '00:15:00',
            scrollTime: '12:00:00',
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            eventContent: plannerEventContent,

            dateClick: function (info) {
                if (info.view.type === 'dayGridMonth') calendar.changeView('timeGridDay', info.dateStr);
            },

            eventClick: function (info) {
                info.jsEvent.preventDefault();
                openContentDetail(info.event.id);
            },

            eventDrop: async function (info) {
                const minutes = minutesOf(info.oldEvent);
                let start = info.event.start;

                if (info.view.type === 'dayGridMonth') {
                    start = slotFor(start, info.event.id);
                    info.event.setDates(start, new Date(start.getTime() + minutes * 60000));
                }

                if (!await save(info.event.id, wallTime(start))) info.revert();
            },

            eventResize: async function (info) {
                if (!await save(info.event.id, wallTime(info.event.start), minutesOf(info.event))) info.revert();
            },

            eventReceive: async function (info) {
                const dragged = info.draggedEl;
                let start = info.event.start;

                if (info.event.allDay) {
                    start = slotFor(start, info.event.id);
                }

                info.event.remove();

                const result = await save(info.event.id, wallTime(start));
                if (!result) return;

                dragged.remove();
                refreshBacklog();
                calendar.addEvent(result.item);

                if (info.view.type === 'dayGridMonth') {
                    calendar.changeView('timeGridDay', wallTime(start).slice(0, 10));
                }
            },

            eventDragStop: async function (info) {
                const box = backlog.getBoundingClientRect();
                const x = info.jsEvent.clientX;
                const y = info.jsEvent.clientY;

                if (x < box.left || x > box.right || y < box.top || y > box.bottom) return;
                if (!info.event.startEditable) return;

                const result = await save(info.event.id, '');
                if (!result) return;

                info.event.remove();
                list.prepend(backlogItem(result.item));
                refreshBacklog();
            },
        });

        const draggable = new window.FullCalendar.Draggable(list, {
            itemSelector: '.backlog-item',
            eventData: function (el) {
                const item = JSON.parse(el.dataset.event);
                delete item.start;
                delete item.end;
                item.duration = { minutes: (item.extendedProps && item.extendedProps.minutes) || contentMinutes };
                return item;
            },
        });

        root.querySelector('#planner-calendar').innerHTML = '';
        calendar.render();
        planner = { calendar: calendar, draggable: draggable };
    });

    onPage(function () {
        const catalogSearch = document.getElementById('catalog-search');

        if (catalogSearch) {
            const resultsBox = document.getElementById('catalog-results');
            let timer = null;
            let sequence = 0;

            catalogSearch.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(async function () {
                    const term = catalogSearch.value.trim();

                    if (term.length < 2) {
                        resultsBox.innerHTML = '';
                        return;
                    }

                    const mine = ++sequence;
                    resultsBox.innerHTML = skeletonRows(3);

                    const response = await fetch(url('/catalog/search') + '?' + new URLSearchParams({ q: term }), {
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json().catch(function () { return { ok: false }; });

                    if (mine !== sequence) return;

                    if (!data.ok || !data.results.length) {
                        resultsBox.innerHTML = '';
                        const empty = document.createElement('p');
                        empty.className = 'empty';
                        empty.textContent = data.ok ? '—' : (data.error || 'Error');
                        resultsBox.append(empty);
                        return;
                    }

                    resultsBox.innerHTML = '';

                    data.results.forEach(function (item) {
                        const row = document.createElement('div');
                        row.className = 'result';

                        const img = document.createElement('img');
                        img.alt = '';
                        img.loading = 'lazy';
                        if (item.image) img.src = item.image;

                        const title = document.createElement('span');
                        title.className = 'title';
                        title.textContent = item.title;
                        knownBadge(title, item);

                        const year = document.createElement('span');
                        year.className = 'year';
                        year.textContent = item.year || '';

                        const action = document.createElement('button');
                        action.type = 'button';
                        action.className = 'btn small';
                        action.textContent = '+';
                        action.addEventListener('click', async function () {
                            action.disabled = true;
                            action.textContent = '…';

                            const result = await postJson('/catalog/import', { ref: item.ref });

                            action.textContent = result.ok ? '✔' : '✗';

                            if (result.ok) {
                                row.classList.add('done');
                                title.textContent = item.title + ' — ' + result.message;
                            } else {
                                action.disabled = false;
                                alert(result.error || 'Error');
                            }
                        });

                        row.append(img, title, year, action);
                        resultsBox.append(row);
                    });
                }, 300);
            });
        }
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.game-refresh');
        if (!button) return;

        const label = button.textContent;
        button.disabled = true;
        button.textContent = '…';

        const result = await postJson('/catalog/refresh', { game_id: button.dataset.id });

        button.disabled = false;
        button.textContent = label;

        if (!result.ok) {
            alert(result.error || 'Error');
            return;
        }

        const row = button.closest('tr');
        const titleCell = row.querySelector('[data-col="title"]');
        const releaseCell = row.querySelector('.release-cell');

        if (titleCell && result.title) {
            const name = titleCell.querySelector('.game-title') || titleCell;
            name.textContent = result.title;
        }

        const thumb = titleCell ? titleCell.querySelector('img.picker-cover') : null;
        if (thumb && result.thumb) thumb.src = result.thumb;

        if (releaseCell && result.precision === 'day' && result.release) {
            releaseCell.textContent = result.release;
        }

        let note = row.querySelector('.refresh-note');

        if (!note) {
            note = document.createElement('small');
            note.className = 'muted block refresh-note';
            releaseCell ? releaseCell.append(note) : button.after(note);
        }

        note.textContent = result.message;
    });

    document.addEventListener('change', async function (event) {
        const select = event.target.closest('.content-status');
        if (!select) return;

        const previous = select.dataset.previous || select.value;
        select.disabled = true;

        const result = await postJson('/content/status', {
            id: select.dataset.id,
            status: select.value
        });

        select.disabled = false;

        if (!result.ok) {
            select.value = previous;
            alert(result.error || 'Error');
            return;
        }

        select.dataset.previous = select.value;
    });

    let modal = document.getElementById('modal');

    let hostedNode = null;
    let hostedHome = null;

    function restoreHosted() {
        if (!hostedNode) return;

        if (hostedHome && hostedHome.parentNode) {
            hostedNode.classList.add('hidden');
            hostedHome.parentNode.insertBefore(hostedNode, hostedHome);
            hostedHome.remove();
        } else {
            hostedNode.remove();
        }

        hostedNode = null;
        hostedHome = null;
    }

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let modalCloseTimer = null;

    function openModal(title, wide) {
        if (!modal) return null;

        if (modalCloseTimer) finishCloseModal();
        modal.classList.remove('closing');

        restoreHosted();
        modal.querySelector('#modal-title').textContent = title;
        modal.querySelector('.modal-panel').classList.toggle('modal-form', !!wide);

        const body = modal.querySelector('#modal-body');
        body.innerHTML = '';
        modal.classList.remove('hidden');
        document.body.classList.add('modal-open');
        return body;
    }

    function finishCloseModal() {
        clearTimeout(modalCloseTimer);
        modalCloseTimer = null;

        restoreHosted();
        modal.classList.add('hidden');
        modal.classList.remove('closing');
        document.body.classList.remove('modal-open');
    }

    /**
     * Fades the modal out (see .modal.closing), then hides it. `immediate`
     * skips the fade — Turbo is about to replace the page under it.
     */
    function closeModal(immediate) {
        if (!modal) return;

        if (modal.classList.contains('hidden')) {
            restoreHosted();
            return;
        }

        if (immediate === true || reducedMotion.matches) {
            finishCloseModal();
            return;
        }

        if (modalCloseTimer) return;

        modal.classList.add('closing');
        modalCloseTimer = setTimeout(finishCloseModal, 170);
    }

    function skeletonRows(count) {
        let html = '';

        for (let i = 0; i < count; i++) {
            html += '<div class="skeleton-row"><span class="skeleton skeleton-thumb"></span>'
                + '<span class="skeleton-lines"><span class="skeleton skeleton-line"></span>'
                + '<span class="skeleton skeleton-line short"></span></span></div>';
        }

        return html;
    }

    function modalSkeleton(body) {
        modal.querySelector('#modal-title').innerHTML = '<span class="skeleton skeleton-title"></span>';

        let lines = '';
        for (let i = 0; i < 6; i++) {
            lines += '<span class="skeleton skeleton-line' + (i % 3 === 2 ? ' short' : '') + '"></span>';
        }

        body.innerHTML = '<div class="skeleton-block"><div class="skeleton skeleton-banner"></div>'
            + '<div class="skeleton-detail"><span class="skeleton skeleton-boxart"></span>'
            + '<span class="skeleton-lines">' + lines + '</span></div></div>';

        const observer = new MutationObserver(function () {
            const real = Array.prototype.some.call(body.children, function (child) {
                return !child.classList.contains('skeleton-block');
            });

            if (real || !body.querySelector('.skeleton-block')) {
                body.querySelectorAll(':scope > .skeleton-block').forEach(function (n) { n.remove(); });
                observer.disconnect();
            }
        });

        observer.observe(body, { childList: true });
    }

    document.addEventListener('click', async function (event) {
        const trigger = event.target.closest('[data-modal-form]');
        if (!trigger) return;

        let node = document.querySelector(trigger.dataset.modalForm);
        if (!node) return;

        if (node.tagName === 'TR' && node.dataset.editUrl && !node.querySelector('.inline-edit')) {
            trigger.disabled = true;
            const response = await fetch(node.dataset.editUrl, { headers: { 'Accept': 'text/html' } });
            trigger.disabled = false;

            if (!response.ok) return;
            node.querySelector('td').innerHTML = await response.text();
        }

        if (node.tagName === 'TR') {
            node = node.querySelector('.inline-edit') || node;
        }

        const body = openModal(trigger.dataset.modalTitle || trigger.textContent.trim(), true);
        if (!body) return;

        hostedHome = document.createComment('streamorg-form-home');
        node.parentNode.insertBefore(hostedHome, node);
        node.classList.remove('hidden');
        body.append(node);
        hostedNode = node;

        node.querySelectorAll('select[data-picker]').forEach(initPicker);

        const first = node.querySelector('input:not([type=hidden]), select, textarea');
        if (first) first.focus();
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('.row-cancel') && hostedNode) {
            closeModal();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-close-modal]')) closeModal();
    });

    /** Links marked data-popup open in a small window of their own (the vault security explainer). */
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[data-popup]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;

        const width = Math.min(780, screen.availWidth - 40);
        const height = Math.min(860, screen.availHeight - 60);
        const left = Math.max(0, (screen.availWidth - width) / 2);
        const top = Math.max(0, (screen.availHeight - height) / 2);
        const popup = window.open(link.href, link.target || 'streamorg-popup',
            'popup,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);

        if (popup) {
            event.preventDefault();
            popup.focus();
        }
    });

    /** Administration → Errors: tick all, the bulk button only with something ticked, and the problem a link points at in view. */
    onPage(function () {
        const log = document.querySelector('[data-error-log]');
        if (!log) return;

        const boxes = log.querySelectorAll('.error-check');
        const all = log.querySelector('[data-check-all]');
        const bulk = log.querySelector('[data-needs-checked]');
        const sync = function () {
            const ticked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
            if (bulk) bulk.disabled = ticked === 0;
            if (all) {
                all.checked = ticked > 0 && ticked === boxes.length;
                all.indeterminate = ticked > 0 && ticked < boxes.length;
            }
        };

        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        if (all) all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            sync();
        });

        const focus = log.querySelector('.error-item.focus');
        if (focus) focus.scrollIntoView({ block: 'center' });
    });

    /** Copy buttons: data-copy names the field whose value goes to the clipboard. */
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy]');
        if (!button) return;

        const field = document.querySelector(button.dataset.copy);
        if (!field) return;

        const done = function () {
            if (!button.dataset.label) button.dataset.label = button.textContent;
            button.textContent = '✓';
            clearTimeout(button._restore);
            button._restore = setTimeout(function () { button.textContent = button.dataset.label; }, 1400);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, function () {});
            return;
        }

        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { }
    });

    /** Planner: sends the planned content to the Twitch channel schedule. */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.twitch-schedule-send');
        if (!button) return;

        const label = button.textContent;
        const status = document.querySelector('[data-twitch-schedule-status]');
        button.disabled = true;
        button.textContent = '…';

        const result = await postJson('/content/twitch-schedule', {});

        button.disabled = false;
        button.textContent = label;

        if (!result.ok) {
            alert(result.error || 'Error');
            return;
        }

        if (status) status.textContent = result.message + ' ' + result.status;
    });

    /**
     * The content title field: prefix and suffix buttons around the
     * creator's own text, and a coloured preview of the finished
     * title. A counter's number is the content's place on the calendar:
     * how many dated, not cancelled plans using it come first, plus where
     * the counter starts; undated content shows "?".
     */
    const COUNTER = /\{([a-z][a-z0-9_]{0,19})\}/g;

    function counterNumber(name, field) {
        const timeline = (window.STREAMORG_TITLE_TIMELINE || {})[name];
        if (!timeline) return null;

        const form = field.closest('form');
        const when = form && form.querySelector('[name="scheduled_start"]');
        const status = form && form.querySelector('[name="status"]');
        if (!when || !when.value || (status && status.value === 'cancelled')) return '?';

        const at = new Date(when.value).getTime();
        const self = parseInt(field.dataset.streamId || '0', 10);
        const before = timeline.dates.filter(function (d) {
            return d[1] !== self && (d[0] < at || (d[0] === at && self && d[1] < self));
        }).length;

        return String(timeline.base + before + 1);
    }

    /** "Keymailer" / "#Keymailer" / "Key Mailer" -> "keymailer", to recognise sponsor tags. */
    function tagKey(text) {
        return String(text).toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');
    }

    function escapeRegExp(text) {
        return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /** The collab credit ("ft. @a, @b") as a pattern, with the user's own credit word. */
    function creditPattern() {
        const word = typeof window.STREAMORG_COLLAB_PREFIX === 'string' ? window.STREAMORG_COLLAB_PREFIX : 'ft.';
        return (word ? escapeRegExp(word) + ' ' : '') + '@[^\\s,#]+(?:,\\s*@[^\\s,#]+)*';
    }

    /**
     * The title split into coloured pieces: the user's prefixes and
     * suffixes wherever they are (and bracketed text at the start),
     * counters, the collab credit, sponsor tags and developer/publisher
     * tags; the rest is plain text.
     */
    function titleTokens(text, prefixes, sponsors) {
        const tokens = [];
        const lead = /^(\s*\[[^\]]*\])+/.exec(text);
        const affixes = prefixes.filter(Boolean).slice().sort(function (a, b) { return b.length - a.length; }).map(escapeRegExp);
        const pattern = new RegExp('(' + (affixes.length ? affixes.join('|') : '(?!)') + ')|(\\[[^\\]]*\\])|(\\{[a-z][a-z0-9_]{0,19}\\})|(' + creditPattern() + ')|(#[^\\s#]+)', 'g');
        let last = 0;
        let match;

        function push(kind, value) {
            if (value) tokens.push({ kind: kind, text: value });
        }

        while ((match = pattern.exec(text)) !== null) {
            push('text', text.slice(last, match.index));
            const value = match[0];

            if (match[1] || match[2]) {
                const isPrefix = !!match[1] || (lead && match.index < lead[0].length);
                value.split(/(\{[a-z][a-z0-9_]{0,19}\})/).forEach(function (part) {
                    if (part) push(/^\{/.test(part) ? 'counter' : (isPrefix ? 'prefix' : 'text'), part);
                });
            } else if (match[3]) {
                push('counter', value);
            } else if (match[4]) {
                push('collab', value);
            } else {
                push(sponsors.indexOf(tagKey(value)) !== -1 ? 'sponsor' : 'devpub', value);
            }

            last = match.index + value.length;
        }

        push('text', text.slice(last));
        return tokens;
    }

    function titleEditor(wrap) {
        const input = wrap.querySelector('[data-title-input]');
        const layer = wrap.querySelector('[data-title-highlight]');
        const hint = wrap.querySelector('[data-title-hint]');
        const form = wrap.closest('form');
        const prefixes = Array.from(wrap.querySelectorAll('.prefix-option')).map(function (b) { return b.dataset.prefixText; });
        const sponsors = (window.STREAMORG_SPONSOR_NAMES || []).map(tagKey);
        const labels = {};

        wrap.querySelectorAll('.title-legend span').forEach(function (span) {
            labels[span.className.replace('tok-', '')] = span.textContent;
        });

        function update() {
            const text = input.value;
            layer.innerHTML = '';

            titleTokens(text, prefixes, sponsors).forEach(function (token) {
                const span = document.createElement('span');
                span.className = 'tok-' + token.kind;
                span.textContent = token.text;
                layer.append(span);
            });
            layer.append('​');

            let rendered = text;
            const numbers = [];

            text.replace(COUNTER, function (whole, name) {
                const n = counterNumber(name, wrap);
                if (n !== null && numbers.indexOf(whole) === -1) {
                    numbers.push(whole);
                    rendered = rendered.split(whole).join(n);
                    const part = document.createElement('span');
                    part.className = 'tok-counter' + (n === '?' ? ' undated' : '');
                    part.textContent = whole + ' = ' + n;
                    if (n === '?') part.title = hint.dataset.undated;
                    numbers.push(part);
                }
                return whole;
            });

            const length = rendered.replace(/\s+/g, ' ').trim().length;
            const max = parseInt(hint.dataset.max, 10);
            const platform = form && form.querySelector('[name="platform"]');
            const over = (!platform || platform.value === 'twitch') && length > max;

            hint.innerHTML = '';
            numbers.filter(function (n) { return typeof n !== 'string'; }).forEach(function (n) { hint.append(n, ' '); });
            const count = document.createElement('span');
            count.className = 'title-count' + (over ? ' over' : '');
            count.textContent = length + '/' + max + (over ? ' · ' + hint.dataset.over : '');
            hint.append(count);
            wrap.classList.toggle('over', over);

            fit();
        }

        /** Grows the box to its text (only while visible: hidden forms measure nothing). */
        function fit() {
            if (!input.offsetParent) return;
            input.style.height = 'auto';
            input.style.height = input.scrollHeight + 'px';
        }

        if (window.ResizeObserver) new ResizeObserver(fit).observe(wrap);

        input.addEventListener('input', update);
        input.addEventListener('scroll', function () { layer.scrollTop = input.scrollTop; });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') event.preventDefault();
        });

        wrap.addEventListener('click', function (event) {
            const button = event.target.closest('.prefix-option');
            if (!button) return;

            const piece = button.dataset.prefixText;

            if (button.dataset.kind === 'suffix') {
                const tail = new RegExp('(\\s+(' + creditPattern() + '|#[^\\s#]+))+\\s*$').exec(input.value);
                const cut = tail ? tail.index : input.value.length;
                const body = input.value.slice(0, cut).replace(/\s+$/, '');
                input.value = (body ? body + ' ' : '') + piece + (tail ? tail[0].replace(/\s+$/, '') : '');
            } else {
                const lead = /^(\s*\[[^\]]*\])*/.exec(input.value)[0];
                const rest = input.value.slice(lead.length).replace(/^\s+/, '');
                input.value = (lead.trim() ? lead.trim() + ' ' : '') + piece + ' ' + rest;
            }

            input.focus();
            update();
        });

        if (wrap.dataset.caret !== undefined) {
            input.addEventListener('focus', function place() {
                input.removeEventListener('focus', place);
                const at = Math.min(parseInt(wrap.dataset.caret, 10) || 0, input.value.length);
                setTimeout(function () { input.setSelectionRange(at, at); }, 0);
            });
        }

        if (form) {
            form.addEventListener('change', function (event) {
                if (event.target.matches('[name="scheduled_start"], [name="status"], [name="platform"]')) update();
            });
        }

        update();
    }

    onPage(function () {
        document.querySelectorAll('[data-title-editor]').forEach(titleEditor);
    });

    /** Profile defaults: the collab credit field shows how a credit will read. */
    document.addEventListener('input', function (event) {
        const field = event.target.closest('input[name="collab_prefix"]');
        if (!field) return;

        const example = field.closest('label').querySelector('[data-collab-example]');
        if (example) example.textContent = (field.value.trim() + ' @hoku_xx, @eulink').trim();
    });

    /** Editable lists (title prefixes, counters): add a row from its template, remove one, move one up. */
    document.addEventListener('click', function (event) {
        const add = event.target.closest('[data-row-add]');
        const remove = event.target.closest('[data-row-remove]');
        const up = event.target.closest('[data-row-up]');

        if (add) {
            const scope = add.closest('[data-rows-scope]') || document;
            const kind = add.dataset.rowAdd;
            const template = scope.querySelector('[data-row-template="' + kind + '"]');
            const holder = document.createElement('div');
            holder.innerHTML = template.innerHTML.replace(/__KEY__/g, 'n' + Date.now());
            const row = holder.firstElementChild;
            scope.querySelector('[data-rows="' + kind + '"]').append(row);
            const first = row.querySelector('input:not([type=hidden]):not([type=checkbox])');
            if (first) first.focus();
        } else if (remove) {
            const row = remove.closest('.row-item');

            if (row.parentElement.children.length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach(function (input) {
                    if (input.type === 'checkbox') input.checked = false;
                    else if (input.type === 'number') input.value = '0';
                    else if (input.type !== 'hidden') input.value = '';
                    else input.remove();
                });
            }
        } else if (up) {
            const row = up.closest('.row-item');
            if (row.previousElementSibling) row.parentElement.insertBefore(row, row.previousElementSibling);
        }
    });

    /** A link ending in #new (the dashboard shortcuts) opens the page's add form. */
    onPage(function () {
        if (location.hash !== '#new') return;

        const opener = document.querySelector('[data-new]');
        history.replaceState(history.state, '', location.pathname + location.search);
        if (opener) opener.click();
    });

    /** Dashboard: schedules an undated item for today at the chosen time. */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-pick-id]');
        if (!button) return;

        const box = button.closest('.pick-today');
        const time = box.querySelector('[data-pick-time]');

        if (!time.reportValidity()) return;

        box.querySelectorAll('[data-pick-id]').forEach(function (b) { b.disabled = true; });
        button.classList.add('busy');

        const result = await postJson('/content/schedule', {
            id: button.dataset.pickId,
            start: box.dataset.date + 'T' + time.value.slice(0, 5) + ':00',
        });

        if (!result.ok) {
            box.querySelectorAll('[data-pick-id]').forEach(function (b) { b.disabled = false; });
            button.classList.remove('busy');
            alert(result.error || 'Error');
            return;
        }

        closeModal(true);

        if (window.Turbo) {
            window.Turbo.visit(location.href, { action: 'replace' });
        } else {
            location.reload();
        }
    });

    let relativeTimer = null;

    /** Dashboard: "in 1 h 20 min" / "due 5 min ago" under today's cards, refreshed every minute. */
    onPage(function () {
        clearInterval(relativeTimer);

        const grid = document.querySelector('[data-relative]');
        if (!grid) return;

        function span(minutes) {
            const h = Math.floor(minutes / 60);
            const m = minutes % 60;
            return (h ? h + ' h' : '') + (h && m ? ' ' : '') + (m || !h ? m + ' min' : '');
        }

        function tick() {
            if (!grid.isConnected) {
                clearInterval(relativeTimer);
                return;
            }

            const now = Date.now() / 1000;

            grid.querySelectorAll('[data-start]').forEach(function (el) {
                const diff = Math.round((parseInt(el.dataset.start, 10) - now) / 60);

                if (Math.abs(diff) < 1) {
                    el.textContent = grid.dataset.nowLabel;
                } else {
                    el.textContent = (diff > 0 ? grid.dataset.in : grid.dataset.ago).replace('%s', span(Math.abs(diff)));
                }

                el.classList.toggle('overdue', diff <= -1);
            });
        }

        tick();
        relativeTimer = setInterval(tick, 60000);
    });

    document.addEventListener('change', function (event) {
        const toggle = event.target.closest('[data-schedule-form] .schedule-toggle input');
        if (toggle) toggle.closest('.schedule-day').classList.toggle('on', toggle.checked);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });

    /** Banner: the hero art (or the store header), with the logo over it. */
    function artBanner(images, title) {
        images = images || {};
        const background = images.hero || images.header;
        if (!background) return null;

        const banner = document.createElement('div');
        const css = 'url("' + background.replace(/"/g, '%22') + '")';

        if (images.hero) {
            banner.className = 'detail-art';
            banner.style.backgroundImage = css;
        } else {
            banner.className = 'detail-art is-header';
            banner.style.setProperty('--art', css);

            const header = document.createElement('img');
            header.className = 'detail-art-header';
            header.src = background;
            header.alt = title || '';
            banner.append(header);
        }

        if (images.logo) {
            const logo = document.createElement('img');
            logo.className = 'detail-art-logo';
            logo.src = images.logo;
            logo.alt = title || '';
            banner.append(logo);
        }

        return banner;
    }

    /** Box art beside a block of details, when the game has one. */
    function withBoxArt(node, images, small) {
        images = images || {};
        const art = images.portrait || images.header;
        if (!art) return node;

        const wrap = document.createElement('div');
        wrap.className = 'detail-with-art' + (small ? ' small' : '');

        const img = document.createElement('img');
        img.className = 'detail-boxart' + (images.portrait ? '' : ' is-header');
        img.src = art;
        img.alt = '';
        img.loading = 'lazy';

        wrap.append(img, node);
        return wrap;
    }

    /** Builds a <dl> of label/value pairs, skipping empty values. */
    function definitionList(pairs) {
        const dl = document.createElement('dl');
        dl.className = 'detail';

        pairs.forEach(function (pair) {
            if (pair[1] === null || pair[1] === undefined || pair[1] === '') return;

            const dt = document.createElement('dt');
            dt.textContent = pair[0];
            const dd = document.createElement('dd');

            if (pair[2] === 'link' && /^https?:\/\//i.test(String(pair[1]))) {
                const a = document.createElement('a');
                a.href = pair[1];
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = pair[1];
                dd.append(a);
            } else {
                dd.textContent = pair[1];
            }

            dl.append(dt, dd);
        });

        return dl;
    }

    function section(body, heading) {
        const h = document.createElement('h3');
        h.textContent = heading;
        body.append(h);
    }


    /** Blocking confirmation in the modal. Resolves true only on confirm. */
    function confirmModal(title, message) {
        return new Promise(function (resolve) {
            const body = openModal(title);

            if (!body) { resolve(window.confirm(message)); return; }

            const warn = document.createElement('div');
            warn.className = 'detail-problems confirm-message';
            message.split('\n').forEach(function (line) {
                const p = document.createElement('p');
                p.textContent = line;
                warn.append(p);
            });
            body.append(warn);

            const actions = document.createElement('div');
            actions.className = 'confirm-actions';

            const no = document.createElement('button');
            no.type = 'button';
            no.className = 'btn';
            no.textContent = (window.STREAMORG_L || {}).cancel || 'Cancel';

            const yes = document.createElement('button');
            yes.type = 'button';
            yes.className = 'btn danger-btn';
            yes.textContent = (window.STREAMORG_L || {}).confirm_anyway || 'Confirm';

            let settled = false;
            const finish = function (value) {
                if (settled) return;
                settled = true;
                closeModal();
                resolve(value);
            };

            no.addEventListener('click', function () { finish(false); });
            yes.addEventListener('click', function () { finish(true); });
            modal.querySelectorAll('[data-close-modal]').forEach(function (el) {
                el.addEventListener('click', function () { finish(false); }, { once: true });
            });

            actions.append(no, yes);
            body.append(actions);
            no.focus();
        });
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        const button = event.submitter;

        if (!button || !button.dataset.confirm || form.dataset.confirmed) return;

        event.preventDefault();

        if (await confirmModal(button.textContent.trim(), button.dataset.confirm)) {
            form.dataset.confirmed = '1';
            form.requestSubmit(button);
        }
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.content-detail');
        if (button) openContentDetail(button.dataset.id);
    });

    /** Also opened from the planner calendar, which has no row button. */
    async function openContentDetail(id) {
        const body = openModal('');
        if (!body) return;
        modalSkeleton(body);

        const response = await fetch(url('/content/show') + '?id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json().catch(function () { return { ok: false }; });

        if (!data.ok) {
            body.textContent = data.error || 'Error';
            return;
        }

        const c = data.content;
        modal.querySelector('#modal-title').textContent = c.title;

        const contentBanner = artBanner(data.images, c.title);
        if (contentBanner) body.append(contentBanner);

        const L = window.STREAMORG_L || {};

        if (data.problems.length) {
            const box = document.createElement('div');
            box.className = 'detail-problems';
            data.problems.forEach(function (text) {
                const p = document.createElement('p');
                p.textContent = text;
                box.append(p);
            });
            body.append(box);
        }

        body.append(definitionList([
            [L.status, c.status_label],
            [L.platform, c.platform_label],
            [L.scheduled, c.scheduled || L.undated],
            [L.deadline, c.deadline],
            [L.started, c.started],
            [L.ended, c.ended],
            [L.peak, c.peak],
            [L.avg, c.avg],
            [L.collab, c.collab],
            [L.vod, c.vod_url, 'link'],
            [L.notes, c.notes],
            [L.created, c.created],
            [L.updated, c.updated]
        ]));

        if (data.games.length) {
            section(body, L.games);

            data.games.forEach(function (g) {
                const card = document.createElement('div');
                card.className = 'detail-game';

                const h = document.createElement('strong');
                h.textContent = g.title;
                card.append(h);

                const pairs = [
                    [L.release, g.release_date],
                    [L.coverage, g.coverage],
                    [L.embargo, g.embargo],
                    [L.note, g.note]
                ];

                if (g.key) {
                    pairs.push(
                        [L.key, g.key.type + ' · ' + g.key.content + ' · ' + g.key.status],
                        [L.platform, g.key.platform],
                        [L.source, g.key.source],
                        [L.redeem_by, g.key.expires]
                    );
                } else {
                    pairs.push([L.key, L.no_key]);
                }

                card.append(definitionList(pairs));
                body.append(withBoxArt(card, g.images, true));
            });
        }

        if (data.collaborators.length) {
            section(body, L.collaborators);
            body.append(definitionList(data.collaborators.map(function (p) {
                return [p.name, p.role + ' · ' + p.confirmation];
            })));
        }
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target.closest('.inline-edit');
        if (!form) return;

        event.preventDefault();

        const output = form.querySelector('.edit-result');
        const submit = form.querySelector('button[type=submit]');
        const payload = new URLSearchParams(new FormData(form));
        payload.set('id', form.dataset.id);

        submit.disabled = true;
        output.textContent = '…';

        let result = await postJson(form.dataset.endpoint, payload);

        if (!result.ok && result.confirm) {
            submit.disabled = false;
            output.textContent = '';

            if (!await confirmModal(result.title || '', result.error || '')) return;

            payload.set('confirm', '1');
            submit.disabled = true;
            output.textContent = '…';
            result = await postJson(form.dataset.endpoint, payload);
        }

        submit.disabled = false;
        output.textContent = (result.ok ? '✔ ' : '✗ ') + (result.message || result.error || '');

        if (result.ok) {
            setTimeout(function () {
                if (window.Turbo) {
                    window.Turbo.visit(window.location.href, { action: 'replace' });
                } else {
                    window.location.reload();
                }
            }, 450);
        }
    });

    function revealToggleEl() {
        return document.getElementById('reveal-toggle');
    }
    const REVEAL_KEY = 'streamorg.reveal';

    function readRevealState() {
        try {
            const raw = window.localStorage.getItem(REVEAL_KEY);
            if (!raw) return false;

            const saved = JSON.parse(raw);

            return !!saved && saved.on === true && saved.session === window.STREAMORG_SESSION;
        } catch (e) {
            return false;
        }
    }

    function writeRevealState(on) {
        try {
            window.localStorage.setItem(REVEAL_KEY, JSON.stringify({
                on: on,
                session: window.STREAMORG_SESSION,
            }));
        } catch (e) { }
    }

    let revealOn = readRevealState();

    function keyFields() {
        return Array.prototype.slice.call(document.querySelectorAll('.key-field'));
    }

    function applyMask() {
        keyFields().forEach(function (field) {
            field.classList.toggle('masked', !revealOn);
        });

        Array.prototype.forEach.call(document.querySelectorAll('.key-area'), function (area) {
            area.classList.toggle('masked', !revealOn);
        });

        const revealToggle = revealToggleEl();
        const usermenu = document.getElementById('usermenu');
        const badge = document.getElementById('keys-badge');

        if (usermenu) usermenu.classList.toggle('keys-on', revealOn);
        if (badge) badge.title = revealOn ? badge.dataset.on : badge.dataset.off;

        if (revealToggle) {
            revealToggle.setAttribute('aria-pressed', revealOn ? 'true' : 'false');
            revealToggle.classList.toggle('on', revealOn);
            revealToggle.querySelector('.txt').textContent =
                revealOn ? revealToggle.dataset.on : revealToggle.dataset.off;
        }
    }

    /** Fetches any codes not yet loaded, in one request. */
    async function loadCodes(fields) {
        const pending = fields.filter(function (f) { return !f.dataset.loaded && f.dataset.id; });

        if (!pending.length) return;

        const result = await postJson('/keys/reveal-bulk', {
            ids: pending.map(function (f) { return f.dataset.id; }).join(',')
        });

        if (!result.ok) {
            if (result.locked) alert(result.error);
            return;
        }

        pending.forEach(function (field) {
            if ((result.unreadable || []).indexOf(field.dataset.id) !== -1) {
                field.dataset.loaded = '1';
                field.placeholder = result.lost_label || '—';
                return;
            }

            const code = result.keys[field.dataset.id];
            if (code === undefined) return;
            field.dataset.loaded = '1';
            if (!field.value) field.value = code;
        });
    }

    onPage(function () {
        const revealToggle = revealToggleEl();

        revealOn = readRevealState();

        if (revealToggle) {
            revealToggle.addEventListener('click', async function () {
                revealOn = !revealOn;
                applyMask();
                writeRevealState(revealOn);

                if (revealOn) {
                    revealToggle.disabled = true;
                    await loadCodes(keyFields());
                    revealToggle.disabled = false;
                }
            });

            applyMask();

            if (revealOn) {
                loadCodes(keyFields());
            }
        }
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.key-load');
        if (!button) return;

        const field = button.closest('.keyfield').querySelector('.key-field');
        button.disabled = true;
        await loadCodes([field]);
        button.disabled = false;
        field.classList.toggle('masked', !revealOn);
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.key-copy');
        if (!button) return;

        const field = button.closest('.keyfield').querySelector('.key-field');

        if (!field.value) {
            button.disabled = true;
            await loadCodes([field]);
            button.disabled = false;
        }

        if (!field.value) return;

        const done = function () {
            if (!button.dataset.icon) button.dataset.icon = button.innerHTML;
            button.textContent = '✔';
            clearTimeout(button._restore);
            button._restore = setTimeout(function () {
                button.innerHTML = button.dataset.icon;
                delete button.dataset.icon;
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done, function () {});
            return;
        }

        const wasMasked = field.classList.contains('masked');
        field.classList.remove('masked');
        field.select();
        try { document.execCommand('copy'); done(); } catch (e) { }
        field.classList.toggle('masked', wasMasked);
        window.getSelection().removeAllRanges();
    });

    function peekButton(event) {
        return event.target instanceof Element ? event.target.closest('.key-peek') : null;
    }

    function peekStart(event) {
        const button = peekButton(event);
        if (!button) return;

        const field = button.closest('.keyfield').querySelector('.key-field');
        if (!field) return;

        event.preventDefault();

        if (!field.value && field.dataset.id) {
            loadCodes([field]).then(function () {
                if (button.dataset.holding) field.classList.remove('masked');
            });
        }

        button.dataset.holding = '1';
        button.classList.add('peeking');
        field.classList.remove('masked');
    }

    function peekEnd(event) {
        const button = peekButton(event);
        if (!button || !button.dataset.holding) return;

        delete button.dataset.holding;
        button.classList.remove('peeking');

        const field = button.closest('.keyfield').querySelector('.key-field');
        if (field) field.classList.toggle('masked', !revealOn);
    }

    ['mousedown', 'touchstart'].forEach(function (type) {
        document.addEventListener(type, peekStart, { passive: false });
    });

    ['mouseup', 'mouseleave', 'touchend', 'touchcancel', 'blur'].forEach(function (type) {
        document.addEventListener(type, peekEnd, true);
    });

    window.addEventListener('blur', function () {
        document.querySelectorAll('.key-peek[data-holding]').forEach(function (button) {
            peekEnd({ target: button });
        });
    });

    function applyColumns(table, hidden) {
        table.querySelectorAll('[data-col]').forEach(function (cell) {
            cell.style.display = hidden.indexOf(cell.dataset.col) === -1 ? '' : 'none';
        });
    }

    /**
     * Puts the columns in the user's order: cells with data-col move,
     * cells without one (the row's buttons) keep their place at the start.
     * Columns missing from the order stay after the ordered ones.
     */
    function applyOrder(table, order) {
        if (!order.length) return;

        const rank = function (col) {
            const i = order.indexOf(col);
            return i === -1 ? order.length : i;
        };

        Array.prototype.forEach.call(table.rows, function (row) {
            const cells = Array.prototype.filter.call(row.cells, function (cell) { return cell.dataset.col; });
            if (cells.length < 2) return;

            cells
                .map(function (cell, i) { return { cell: cell, i: i }; })
                .sort(function (a, b) { return rank(a.cell.dataset.col) - rank(b.cell.dataset.col) || a.i - b.i; })
                .forEach(function (item) { row.appendChild(item.cell); });
        });
    }

    /**
     * Column chooser: tick the columns to show, and put them in any order,
     * by dragging or with the arrows. Saved per user and table.
     */
    onPage(function () {
        document.querySelectorAll('table[data-table]').forEach(function (table) {
            const name = table.dataset.table;
            const prefKey = 'columns.' + name;
            const orderKey = prefKey + '.order';
            const prefs = window.STREAMORG_PREFS || {};
            const L = window.STREAMORG_L || {};
            let hidden = Array.isArray(prefs[prefKey]) ? prefs[prefKey].slice() : [];
            let order = Array.isArray(prefs[orderKey]) ? prefs[orderKey].slice() : [];

            const headers = Array.prototype.slice.call(table.querySelectorAll('thead th[data-col]'));
            if (!headers.length) return;

            const labels = {};
            const defaults = headers.map(function (th) {
                labels[th.dataset.col] = th.textContent.trim() || th.dataset.col;
                return th.dataset.col;
            });

            applyOrder(table, order);
            applyColumns(table, hidden);

            const current = function () {
                return Array.prototype.map.call(table.querySelectorAll('thead th[data-col]'), function (th) { return th.dataset.col; });
            };

            const wrap = document.createElement('details');
            wrap.className = 'columns-picker';

            const summary = document.createElement('summary');
            summary.textContent = L.columns || 'Columns';
            wrap.append(summary);

            const list = document.createElement('div');
            list.className = 'columns-list';

            const hint = document.createElement('p');
            hint.className = 'columns-hint';
            hint.textContent = L.columns_hint || '';

            const items = document.createElement('ol');
            items.className = 'columns-items';

            const reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'btn small ghost columns-reset';
            reset.textContent = L.columns_reset || 'Reset';

            const save = function (key, value) {
                postJson('/preferences', { key: key, value: JSON.stringify(value) });
            };

            const commitOrder = function () {
                order = Array.prototype.map.call(items.children, function (li) { return li.dataset.col; });
                applyOrder(table, order);
                applyColumns(table, hidden);
                save(orderKey, order);
                refreshArrows();
            };

            const refreshArrows = function () {
                Array.prototype.forEach.call(items.children, function (li, i) {
                    li.querySelector('[data-col-up]').disabled = i === 0;
                    li.querySelector('[data-col-down]').disabled = i === items.children.length - 1;
                });
            };

            const arrow = function (label, glyph, attr) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'col-move';
                button.setAttribute(attr, '');
                button.setAttribute('aria-label', label);
                button.title = label;
                button.textContent = glyph;
                return button;
            };

            const build = function () {
                items.innerHTML = '';

                current().forEach(function (col) {
                    const li = document.createElement('li');
                    li.dataset.col = col;
                    li.draggable = true;

                    const grip = document.createElement('span');
                    grip.className = 'col-grip';
                    grip.setAttribute('aria-hidden', 'true');
                    grip.textContent = '⋮⋮';

                    const label = document.createElement('label');
                    label.className = 'inline';
                    const box = document.createElement('input');
                    box.type = 'checkbox';
                    box.checked = hidden.indexOf(col) === -1;
                    const text = document.createElement('span');
                    text.textContent = labels[col];
                    label.append(box, text);

                    box.addEventListener('change', function () {
                        hidden = box.checked
                            ? hidden.filter(function (c) { return c !== col; })
                            : hidden.concat([col]);
                        applyColumns(table, hidden);
                        save(prefKey, hidden);
                    });

                    const up = arrow((L.move_up || 'Move up') + ': ' + labels[col], '↑', 'data-col-up');
                    const down = arrow((L.move_down || 'Move down') + ': ' + labels[col], '↓', 'data-col-down');

                    up.addEventListener('click', function () {
                        if (li.previousElementSibling) items.insertBefore(li, li.previousElementSibling);
                        commitOrder();
                        up.disabled ? down.focus() : up.focus();
                    });

                    down.addEventListener('click', function () {
                        if (li.nextElementSibling) items.insertBefore(li.nextElementSibling, li);
                        commitOrder();
                        down.disabled ? up.focus() : down.focus();
                    });

                    li.addEventListener('dragstart', function (event) {
                        li.classList.add('dragging');
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', col);
                    });

                    li.addEventListener('dragend', function () {
                        li.classList.remove('dragging');
                        commitOrder();
                    });

                    li.append(grip, label, up, down);
                    items.append(li);
                });

                refreshArrows();
            };

            items.addEventListener('dragover', function (event) {
                const dragging = items.querySelector('.dragging');
                if (!dragging) return;
                event.preventDefault();

                const after = Array.prototype.find.call(items.children, function (li) {
                    if (li === dragging) return false;
                    const box = li.getBoundingClientRect();
                    return event.clientY < box.top + box.height / 2;
                });

                if (after) items.insertBefore(dragging, after);
                else items.append(dragging);
            });

            reset.addEventListener('click', function () {
                hidden = [];
                order = defaults.slice();
                applyOrder(table, order);
                applyColumns(table, hidden);
                save(prefKey, hidden);
                save(orderKey, []);
                order = [];
                build();
            });

            build();
            list.append(hint, items, reset);
            wrap.append(list);

            const card = table.closest('.card');
            const head = card ? card.querySelector('.card-head') : null;
            const target = table.closest('.table-wrap') || table;

            if (head) {
                head.append(wrap);
            } else {
                target.parentNode.insertBefore(wrap, target);
            }
        });
    });

    /**
     * Sortable tables (data-sortable): a click on a column title sorts by
     * it, a second click the other way round, a third goes back to the
     * page's own order. Empty cells always stay at the bottom. A cell can
     * give its own value in data-sort (dates as numbers); a select sorts
     * by its chosen option. Rows of an inline edit form travel with their
     * row. The choice is remembered per table in this browser.
     */
    function sortValue(cell) {
        if (!cell) return '';
        if (cell.dataset.sort !== undefined) return cell.dataset.sort;

        const select = cell.querySelector('select');
        if (select && select.selectedIndex >= 0) return select.options[select.selectedIndex].text.trim();

        const text = cell.textContent.replace(/\s+/g, ' ').trim();
        return text === '—' ? '' : text;
    }

    function sortRows(table, index, dir) {
        const body = table.tBodies[0];
        if (!body) return;

        const groups = [];
        Array.prototype.forEach.call(body.rows, function (row) {
            if (row.classList.contains('editrow') && groups.length) groups[groups.length - 1].push(row);
            else groups.push([row]);
        });

        groups.forEach(function (group, i) {
            if (group[0].dataset.order === undefined) group[0].dataset.order = String(i);
        });

        const collator = new Intl.Collator(document.documentElement.lang || undefined, { numeric: true, sensitivity: 'base' });
        const number = function (v) { return /^-?\d+([.,]\d+)?$/.test(v) ? parseFloat(v.replace(',', '.')) : NaN; };

        const keyed = groups.map(function (group) {
            return { group: group, order: Number(group[0].dataset.order), value: dir === 0 ? '' : sortValue(group[0].cells[index]) };
        });

        keyed.sort(function (a, b) {
            if (dir === 0) return a.order - b.order;
            if ((a.value === '') !== (b.value === '')) return a.value === '' ? 1 : -1;

            const na = number(a.value);
            const nb = number(b.value);
            const compared = !isNaN(na) && !isNaN(nb) ? na - nb : collator.compare(a.value, b.value);

            return compared * dir || a.order - b.order;
        });

        keyed.forEach(function (item) {
            item.group.forEach(function (row) { body.appendChild(row); });
        });
    }

    function storedSort(key) {
        try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    }

    function storeSort(key, value) {
        try {
            if (value) localStorage.setItem(key, JSON.stringify(value));
            else localStorage.removeItem(key);
        } catch (e) { }
    }

    onPage(function () {
        document.querySelectorAll('table[data-sortable]').forEach(function (table, n) {
            if (table.dataset.sortReady) return;
            table.dataset.sortReady = '1';

            const key = 'sort.' + (table.dataset.table || location.pathname + '.' + n);
            const headers = Array.prototype.slice.call(table.tHead ? table.tHead.rows[0].cells : []);
            const L = window.STREAMORG_L || {};

            const show = function (active, dir) {
                headers.forEach(function (th) {
                    if (th.classList.contains('col-actions') || th.hasAttribute('data-nosort')) return;
                    th.setAttribute('aria-sort', th === active && dir ? (dir > 0 ? 'ascending' : 'descending') : 'none');
                });
            };

            headers.forEach(function (th, index) {
                if (th.classList.contains('col-actions') || th.hasAttribute('data-nosort') || !th.textContent.trim()) return;

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'th-sort';
                button.title = (L.sort_by || 'Sort by %s').replace('%s', th.textContent.trim());
                while (th.firstChild) button.appendChild(th.firstChild);
                th.appendChild(button);

                button.addEventListener('click', function () {
                    const current = th.getAttribute('aria-sort');
                    const dir = current === 'ascending' ? -1 : current === 'descending' ? 0 : 1;

                    sortRows(table, th.cellIndex, dir);
                    show(th, dir);
                    storeSort(key, dir ? { col: th.dataset.col || null, index: index, dir: dir } : null);
                });
            });

            show(null, 0);

            const saved = storedSort(key);
            const target = saved && (saved.col
                ? table.tHead.querySelector('th[data-col="' + saved.col + '"]')
                : headers[saved.index]);

            if (target && target.querySelector('.th-sort')) {
                sortRows(table, target.cellIndex, saved.dir);
                show(target, saved.dir);
            }
        });

        document.querySelectorAll('.table-wrap').forEach(function (wrap) {
            if (wrap.dataset.edgeReady || !wrap.querySelector('td.rowactions')) return;
            wrap.dataset.edgeReady = '1';

            const edge = function () { wrap.classList.toggle('scrolled', wrap.scrollLeft > 2); };
            wrap.addEventListener('scroll', function () { edge(); closeRowMenus(); }, { passive: true });
            edge();
        });

        document.querySelectorAll('table[data-sortable] td.rowactions').forEach(function (cell) {
            if (cell.dataset.menuReady) return;
            cell.dataset.menuReady = '1';

            if (cell.querySelectorAll('.btn').length < 2) return;

            const menu = document.createElement('div');
            menu.className = 'rowactions-menu';
            menu.setAttribute('role', 'menu');
            while (cell.firstChild) menu.appendChild(cell.firstChild);

            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'btn small row-more';
            more.setAttribute('aria-haspopup', 'menu');
            more.setAttribute('aria-expanded', 'false');
            more.setAttribute('aria-label', (window.STREAMORG_L || {}).more_actions || 'More actions');
            more.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>';

            cell.classList.add('has-menu');
            cell.append(more, menu);
        });
    });

    /** Takes the sort buttons and row menus out again before Turbo keeps a copy of the page, whose listeners would be gone. */
    function undoTableExtras() {
        closeRowMenus();

        document.querySelectorAll('.columns-picker').forEach(function (picker) { picker.remove(); });

        document.querySelectorAll('th .th-sort').forEach(function (button) {
            const th = button.parentNode;
            while (button.firstChild) th.insertBefore(button.firstChild, button);
            button.remove();
        });

        document.querySelectorAll('td.rowactions.has-menu').forEach(function (cell) {
            const menu = cell.querySelector('.rowactions-menu');
            const more = cell.querySelector('.row-more');
            if (more) more.remove();
            if (menu) {
                while (menu.firstChild) cell.insertBefore(menu.firstChild, menu);
                menu.remove();
            }
            cell.classList.remove('has-menu');
        });

        document.querySelectorAll('[data-sort-ready], [data-menu-ready], [data-edge-ready]').forEach(function (node) {
            delete node.dataset.sortReady;
            delete node.dataset.menuReady;
            delete node.dataset.edgeReady;
        });
    }

    /** The "⋮" of a row on small screens: opens that row's buttons in a small menu beside it. */
    function closeRowMenus() {
        document.querySelectorAll('td.rowactions.menu-open').forEach(function (cell) {
            cell.classList.remove('menu-open');
            const menu = cell.querySelector('.rowactions-menu');
            if (menu) menu.removeAttribute('style');
            const more = cell.querySelector('.row-more');
            if (more) more.setAttribute('aria-expanded', 'false');
        });
    }

    function openRowMenu(cell, more) {
        const menu = cell.querySelector('.rowactions-menu');
        cell.classList.add('menu-open');
        more.setAttribute('aria-expanded', 'true');

        const at = more.getBoundingClientRect();
        const height = menu.offsetHeight;
        const below = at.bottom + 6 + height <= window.innerHeight - 8;

        menu.style.left = Math.max(8, Math.min(at.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
        menu.style.top = (below ? at.bottom + 6 : Math.max(8, at.top - 6 - height)) + 'px';

        const first = menu.querySelector('.btn, a, button');
        if (first) first.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (event) {
        const more = event.target.closest('.row-more');

        if (more) {
            const cell = more.closest('td.rowactions');
            const wasOpen = cell.classList.contains('menu-open');
            closeRowMenus();
            if (!wasOpen) openRowMenu(cell, more);
            return;
        }

        if (event.target.closest('.rowactions-menu')) {
            setTimeout(closeRowMenus, 0);
            return;
        }

        closeRowMenus();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeRowMenus();
    });

    window.addEventListener('resize', closeRowMenus);
    window.addEventListener('scroll', closeRowMenus, { passive: true });

    function detailKeyField(id) {
        const source = document.querySelector('tr[data-key-id="' + id + '"] .keycell .keyfield');
        if (!source) return null;

        const copy = source.cloneNode(true);
        const original = source.querySelector('.key-field');
        const field = copy.querySelector('.key-field');

        field.value = original.value;
        if (!field.value) delete field.dataset.loaded;
        field.classList.toggle('masked', !revealOn);

        return copy;
    }

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.key-detail');
        if (!button) return;

        const body = openModal('');
        if (!body) return;
        modalSkeleton(body);

        const response = await fetch(url('/keys/show') + '?id=' + encodeURIComponent(button.dataset.id), {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json().catch(function () { return { ok: false }; });

        if (!data.ok) { body.textContent = data.error || 'Error'; return; }

        const k = data.key;
        const L = window.STREAMORG_L || {};
        modal.querySelector('#modal-title').textContent = k.game;

        const keyBanner = artBanner(k.images, k.game);
        if (keyBanner) body.append(keyBanner);

        if (data.embargo && data.embargo.active) {
            const box = document.createElement('div');
            box.className = 'detail-problems';
            const p = document.createElement('p');
            p.textContent = (L.under_embargo_until || 'Under embargo until %s.').replace('%s', data.embargo.lifts)
                + ' (' + data.embargo.kind + (data.embargo.label ? ' — ' + data.embargo.label : '') + ')';
            box.append(p);
            body.append(box);
        }

        const details = definitionList([
            [L.status, k.status],
            [L.key_type, k.key_type],
            [L.content_type, k.content_type],
            [L.platform, k.platform],
            [L.source, k.source],
            [L.region, k.region],
            [L.redeem_by, k.expires],
            [L.received, k.received],
            [L.activated, k.activated],
            [L.provenance, k.provenance],
            [L.notes, k.notes],
            [L.created, k.created],
            [L.updated, k.updated]
        ]);

        const keyField = detailKeyField(button.dataset.id);

        if (keyField) {
            const dt = document.createElement('dt');
            dt.textContent = L.key_code;
            const dd = document.createElement('dd');
            dd.append(keyField);
            details.prepend(dt, dd);
        }

        body.append(withBoxArt(details, k.images));

        section(body, L.games);
        body.append(definitionList([
            [L.publisher, k.publisher],
            [L.developer, k.developer],
            [L.release, k.release],
            [L.embargo, data.embargo
                ? data.embargo.lifts + ' (' + data.embargo.kind + ')'
                : null],
            [L.store_url, k.store_url, 'link']
        ]));

        if (data.negotiation) {
            section(body, L.negotiation);
            body.append(definitionList([
                [L.title, data.negotiation.subject],
                [L.status, data.negotiation.status]
            ]));
        }

        if (data.used_in.length) {
            section(body, L.used_in);
            body.append(definitionList(data.used_in.map(function (c) {
                return [c.title, c.status + (c.scheduled ? ' · ' + c.scheduled : '')];
            })));
        }

        if (data.prize) {
            section(body, L.giveaway);
            body.append(definitionList([
                [L.title, data.prize.giveaway],
                [L.winner, data.prize.winner],
                [L.won_at, data.prize.won],
                [L.delivered, data.prize.delivered]
            ]));
        }
    });

    let topbarTicking = false;

    function syncTopbar() {
        const topbar = document.querySelector('.topbar');
        if (topbar) topbar.classList.toggle('scrolled', window.scrollY > 4);
        topbarTicking = false;
    }

    window.addEventListener('scroll', function () {
        if (topbarTicking) return;
        topbarTicking = true;
        window.requestAnimationFrame(syncTopbar);
    }, { passive: true });

    onPage(syncTopbar);

    onPage(function () {
        const twitchSearch = document.getElementById('twitch-search');

        if (twitchSearch) {
            const box = document.getElementById('twitch-results');
            let timer = null, sequence = 0;

            twitchSearch.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(async function () {
                    const term = twitchSearch.value.trim();
                    if (term.length < 2) { box.innerHTML = ''; return; }

                    const mine = ++sequence;
                    box.innerHTML = skeletonRows(3);

                    const res = await fetch(url('/streamers/search') + '?q=' + encodeURIComponent(term),
                                            { headers: { 'Accept': 'application/json' } });
                    const data = await res.json().catch(function () { return { ok: false }; });
                    if (mine !== sequence) return;

                    if (!data.ok) {
                        box.innerHTML = '';
                        const empty = document.createElement('p');
                        empty.className = 'empty';
                        empty.textContent = data.error || 'Error';
                        box.append(empty);
                        return;
                    }
                    if (!data.results.length) { box.innerHTML = '<p class="empty">—</p>'; return; }

                    box.innerHTML = '';

                    data.results.forEach(function (item) {
                        const row = document.createElement('div');
                        row.className = 'result';

                        const img = document.createElement('img');
                        img.alt = ''; img.loading = 'lazy';
                        img.className = 'avatar-lg';
                        if (item.avatar) img.src = item.avatar;

                        const title = document.createElement('span');
                        title.className = 'title';
                        title.textContent = item.name;

                        const handle = document.createElement('span');
                        handle.className = 'year';
                        handle.textContent = '@' + item.login + (item.live ? ' · live' : '');
                        if (item.on_streamorg) {
                            const badge = document.createElement('span');
                            badge.className = 'badge on-streamorg';
                            badge.textContent = (window.STREAMORG_L || {}).on_streamorg || 'StreamOrg';
                            badge.title = (window.STREAMORG_L || {}).on_streamorg_hint || '';
                            title.append(' ', badge);
                        }

                        const add = document.createElement('button');
                        add.type = 'button';
                        add.className = 'btn small';
                        add.textContent = '+';
                        add.addEventListener('click', async function () {
                            add.disabled = true; add.textContent = '…';
                            const result = await postJson('/streamers/import', { ref: item.ref });

                            if (!result.ok) {
                                add.disabled = false; add.textContent = '✗';
                                alert(result.error || 'Error');
                                return;
                            }

                            add.textContent = '✔';
                            row.classList.add('done');
                            title.textContent = item.name + ' — ' + result.message;
                            setTimeout(function () { window.location.reload(); }, 600);
                        });

                        row.append(img, title, handle, add);
                        box.append(row);
                    });
                }, 320);
            });
        }
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.streamer-refresh');
        if (!button) return;

        const previous = button.textContent;
        button.disabled = true; button.textContent = '…';
        const result = await postJson('/streamers/import', { ref: button.dataset.ref });
        button.disabled = false; button.textContent = previous;

        if (!result.ok) { alert(result.error || 'Error'); return; }
        window.location.reload();
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.row-delete');
        if (!button) return;

        const L = window.STREAMORG_L || {};
        const label = button.dataset.label || '';
        const message = label
            ? (L.confirm_delete_named || '%s').replace('%s', label)
            : (L.confirm_delete || '');

        if (!await confirmModal(L.confirm_delete_title || '', message)) return;

        const payload = { id: button.dataset.id };

        if (button.dataset.kind) {
            payload.kind = button.dataset.kind;
        }

        button.disabled = true;
        const result = await postJson(button.dataset.endpoint, payload);
        button.disabled = false;

        if (!result.ok) {
            const body = openModal(L.confirm_delete_title || '');

            if (body) {
                const box = document.createElement('div');
                box.className = 'detail-problems';
                const p = document.createElement('p');
                p.textContent = result.error || 'Error';
                box.append(p);
                body.append(box);
            } else {
                alert(result.error || 'Error');
            }

            return;
        }

        window.location.reload();
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.collab-plan');
        if (!button) return;

        const target = url('/content') + '?collab=' + encodeURIComponent(button.dataset.id);

        if (window.Turbo) {
            window.Turbo.visit(target);
        } else {
            window.location.href = target;
        }
    });

    onPage(function () {
        const titleField = document.querySelector('#add-content textarea[name="title"]');

        if (titleField) {
            /** "Super Rare Originals" -> "SuperRareOriginals" */
            const CONFUSABLES = {
                'А':'A','В':'B','С':'C','Е':'E','Н':'H','К':'K','М':'M','О':'O','Р':'P','Т':'T','Х':'X',
                'а':'a','е':'e','о':'o','р':'p','с':'c','у':'y','х':'x',
                'Α':'A','Β':'B','Ε':'E','Η':'H','Ι':'I','Κ':'K','Μ':'M','Ν':'N','Ο':'O','Ρ':'P','Τ':'T','Υ':'Y','Χ':'X',
            };

            function camelTag(name) {
                const words = String(name || '')
                    .replace(/[^\x00-\x7F]/g, function (ch) { return CONFUSABLES[ch] || ch; })
                    .normalize('NFD').replace(/[̀-ͯ]/g, '')
                    .replace(/[^A-Za-z0-9 ]+/g, ' ')
                    .trim().split(/\s+/).filter(Boolean);

                if (!words.length) return '';

                return '#' + words.map(function (w) {
                    return /^[A-Z0-9]+$/.test(w) ? w : w.charAt(0).toUpperCase() + w.slice(1);
                }).join('');
            }

            function escapeRe(v) {
                return String(v).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function hasTag(text, tag) {
                if (!tag) return true;
                return new RegExp('(^|\\s)' + escapeRe(tag) + '(\\s|$)', 'i').test(text);
            }

            const applied = { game: [], sponsor: [], collab: [] };

            function removeTokens(tokens) {
                if (!tokens.length) return;

                let text = titleField.value;

                tokens.forEach(function (token) {
                    text = text.replace(
                        new RegExp('(^|\\s)' + escapeRe(token) + '(?=\\s|$)', 'i'),
                        ' '
                    );
                });

                titleField.value = text.replace(/[ \t]{2,}/g, ' ').trim();
            }

            /** Appends tags that are not already somewhere in the title. */
            function appendTags(tags) {
                let text = titleField.value.replace(/\s+$/, '');
                const added = [];

                tags.filter(Boolean).forEach(function (tag) {
                    if (!hasTag(text, tag)) {
                        text += (text ? ' ' : '') + tag;
                        added.push(tag);
                    }
                });

                titleField.value = text;

                return added;
            }

            /** Swaps one slot's contribution for a new set of tags. */
            function setSlot(slot, tags) {
                removeTokens(applied[slot]);
                appendTags(tags);
                applied[slot] = tags.filter(Boolean);
                titleField.dispatchEvent(new Event('input'));
            }

            /** "ft. @A, @B" (with the user's own word for "ft.") sits before the hashtags, where it reads naturally. */
            function featureText(names) {
                if (!names.length) return '';

                const word = typeof window.STREAMORG_COLLAB_PREFIX === 'string' ? window.STREAMORG_COLLAB_PREFIX : 'ft.';

                return (word ? word + ' ' : '') + names.map(function (n) {
                    return '@' + String(n).replace(/\s+/g, '');
                }).join(', ');
            }

            /** Same slot idea as the tags: swap the credit, never stack it. */
            function setFeature(names) {
                if (applied.collab.length) {
                    titleField.value = titleField.value
                        .replace(applied.collab[0], '')
                        .replace(/[ \t]{2,}/g, ' ')
                        .trim();
                }

                applied.collab = [];

                const feature = featureText(names);

                if (!feature) return;

                if (titleField.value.indexOf(feature) !== -1) {
                    applied.collab = [feature];
                    return;
                }

                const text = titleField.value;
                const m = text.match(/^(.*?)(\s*(?:#\S+\s*)+)$/);

                titleField.value = m
                    ? (m[1].replace(/\s+$/, '') + ' ' + feature + ' ' + m[2].trim()).trim()
                    : (text.replace(/\s+$/, '') + (text ? ' ' : '') + feature);

                applied.collab = [feature];
                titleField.dispatchEvent(new Event('input'));
            }

            const gameSelect    = document.getElementById('content-game');
            const sponsorSelect = document.getElementById('content-sponsor');
            const collabSelect  = document.getElementById('content-collab');

            if (gameSelect) {
                gameSelect.addEventListener('change', function () {
                    const g = (window.STREAMORG_GAMES || {})[gameSelect.value];
                    setSlot('game', g ? [camelTag(g.developer), camelTag(g.publisher)] : []);
                });
            }

            if (sponsorSelect) {
                sponsorSelect.addEventListener('change', function () {
                    setSlot('sponsor', sponsorSelect.value ? [camelTag(sponsorSelect.value)] : []);
                });
            }

            if (collabSelect) {
                const applyCollab = function () {
                    const c = (window.STREAMORG_COLLABS || {})[collabSelect.value];

                    if (!c) {
                        setFeature([]);
                        return;
                    }

                    if (!titleField.value.trim() && c.title) {
                        titleField.value = c.title;
                    }

                    setFeature(c.cast || []);

                    const when = document.querySelector('#add-content input[name="scheduled_start"]');
                    if (when && !when.value && c.at) when.value = c.at;

                    const plat = document.querySelector('#add-content select[name="platform"]');
                    if (plat && c.platform) plat.value = c.platform;
                };

                collabSelect.addEventListener('change', applyCollab);

                const pre = window.STREAMORG_PRESELECT_COLLAB;

                if (pre) {
                    collabSelect.value = String(pre);
                    applyCollab();

                    const opener = document.querySelector('[data-modal-form="#add-content"]');
                    if (opener) opener.click();
                }
            }
        }
    });

    function navToggleEl() {
        return document.getElementById('nav-toggle');
    }

    function setNavOpen(open) {
        const navToggle = navToggleEl();
        const backdrop = document.getElementById('nav-backdrop');

        document.body.classList.toggle('nav-open', open);
        if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (backdrop) backdrop.hidden = !open;
    }

    /** The bell: its panel is fetched each time it opens, so it is never stale. */
    async function notifyMenu(open) {
        const button = document.getElementById('notify-button');
        const panel = document.getElementById('notify-panel');
        if (!button || !panel) return;

        const show = open === undefined ? panel.hidden : open;
        panel.hidden = !show;
        button.setAttribute('aria-expanded', show ? 'true' : 'false');

        if (!show) return;

        panel.innerHTML = '<div class="notify-loading"><span class="spinner"></span></div>';
        const response = await fetch(button.dataset.panel, { headers: { 'Accept': 'text/html' } }).catch(function () { return null; });
        if (response && response.ok && !panel.hidden) panel.innerHTML = await response.text();
    }

    function setNotifyCount(count) {
        const badge = document.getElementById('notify-count');
        if (!badge) return;

        badge.hidden = count <= 0;
        badge.textContent = count > 99 ? '99+' : String(count);
    }

    document.addEventListener('click', async function (event) {
        if (event.target.closest('#notify-button')) {
            userMenu(false);
            notifyMenu();
            return;
        }

        const readAll = event.target.closest('[data-notify-read-all]');

        if (readAll) {
            readAll.disabled = true;
            const result = await postJson('/notifications/read-all', {});

            if (result.ok) {
                setNotifyCount(0);
                document.querySelectorAll('#notify-panel .note.unread').forEach(function (n) { n.classList.remove('unread'); });
                document.querySelectorAll('#notify-panel .note-dot').forEach(function (d) { d.remove(); });
                readAll.remove();
            }
            return;
        }

        if (!event.target.closest('#notify-panel')) notifyMenu(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') notifyMenu(false);
    });

    /** Admin composer: one tab per language (ticked once written) and the choice of recipients. */
    onPage(function () {
        const form = document.querySelector('[data-notify-compose]');
        if (!form) return;

        const tabs = form.querySelectorAll('[data-lang-tab]');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) {
                    const on = t === tab;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    form.querySelector('[data-lang-panel="' + t.dataset.langTab + '"]').hidden = !on;
                });
            });
        });

        form.addEventListener('input', function (event) {
            const panel = event.target.closest('[data-lang-panel]');
            if (!panel) return;

            const filled = panel.querySelector('input').value.trim() !== '';
            form.querySelector('[data-lang-tab="' + panel.dataset.langPanel + '"] .lang-filled').hidden = !filled;
        });

        const users = form.querySelector('[data-audience-users]');

        form.querySelectorAll('[data-audience]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                users.hidden = radio.value !== 'users' || !radio.checked;
                if (!users.hidden) users.querySelectorAll('select[data-picker]').forEach(initPicker);
            });
        });
    });

    /**
     * The notification text editor: toolbar buttons (and Ctrl+B / Ctrl+I /
     * Ctrl+K) that put NoteFormat's marks around the selection or at the
     * start of the selected lines, and a preview drawn by the server.
     */
    function richReplace(area, start, end, text, selectFrom, selectTo) {
        area.focus();
        area.setSelectionRange(start, end);
        if (!document.execCommand || !document.execCommand('insertText', false, text)) {
            area.setRangeText(text, start, end, 'end');
            area.dispatchEvent(new Event('input', { bubbles: true }));
        }
        area.setSelectionRange(start + selectFrom, start + selectTo);
    }

    function richWrap(area, mark, placeholder) {
        const start = area.selectionStart;
        const end = area.selectionEnd;
        const chosen = area.value.slice(start, end);
        const before = area.value.slice(Math.max(0, start - mark.length), start);
        const after = area.value.slice(end, end + mark.length);

        if (chosen && before === mark && after === mark) {
            richReplace(area, start - mark.length, end + mark.length, chosen, 0, chosen.length);
            return;
        }

        const inner = chosen || placeholder;
        richReplace(area, start, end, mark + inner + mark, mark.length, mark.length + inner.length);
    }

    function richLines(area, kind) {
        const value = area.value;
        const start = value.lastIndexOf('\n', area.selectionStart - 1) + 1;
        let end = value.indexOf('\n', area.selectionEnd);
        if (end === -1 || (area.selectionEnd > area.selectionStart && value[area.selectionEnd - 1] === '\n')) {
            end = end === -1 ? value.length : area.selectionEnd - 1;
        }

        const patterns = { heading: /^##\s+/, list: /^[-*•]\s+/, numbers: /^\d+[.)]\s+/, quote: /^>\s?/ };
        const lines = value.slice(start, end).split('\n');
        const all = lines.every(function (line) { return patterns[kind].test(line); });
        const result = lines.map(function (line, i) {
            const bare = line.replace(/^(##\s+|[-*•]\s+|\d+[.)]\s+|>\s?)/, '');
            if (all) return bare;
            const mark = kind === 'heading' ? '## ' : kind === 'list' ? '- ' : kind === 'numbers' ? (i + 1) + '. ' : '> ';
            return mark + bare;
        }).join('\n');

        richReplace(area, start, end, result, result.length, result.length);
    }

    function richLink(area) {
        const start = area.selectionStart;
        const end = area.selectionEnd;
        const chosen = area.value.slice(start, end);

        if (/^(https?:\/\/|\/)\S*$/.test(chosen)) {
            const words = area.dataset.linkWords || 'link';
            richReplace(area, start, end, '[' + words + '](' + chosen + ')', 1, 1 + words.length);
            return;
        }

        const words = chosen || area.dataset.linkWords || 'link';
        const text = '[' + words + '](https://)';
        richReplace(area, start, end, text, words.length + 3, text.length - 1);
    }

    function richFormat(area, tool) {
        if (tool === 'bold') richWrap(area, '**', area.dataset.boldWords || 'bold');
        else if (tool === 'italic') richWrap(area, '*', area.dataset.italicWords || 'italic');
        else if (tool === 'strike') richWrap(area, '~~', area.dataset.strikeWords || 'text');
        else if (tool === 'code') richWrap(area, '`', 'code');
        else if (tool === 'link') richLink(area);
        else richLines(area, tool);
    }

    onPage(function () {
        document.querySelectorAll('[data-rich-editor]').forEach(function (editor) {
            const area = editor.querySelector('textarea');
            const preview = editor.querySelector('[data-rich-preview]');
            const tools = editor.querySelectorAll('[data-format]');

            tools.forEach(function (button) {
                button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                button.addEventListener('click', function () { richFormat(area, button.dataset.format); });
            });

            area.addEventListener('keydown', function (event) {
                if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
                const tool = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];
                if (!tool) return;
                event.preventDefault();
                richFormat(area, tool);
            });

            editor.querySelectorAll('[data-rich-mode]').forEach(function (tab) {
                tab.addEventListener('click', async function () {
                    const showing = tab.dataset.richMode === 'preview';
                    editor.querySelectorAll('[data-rich-mode]').forEach(function (t) { t.classList.toggle('active', t === tab); });
                    tools.forEach(function (b) { b.disabled = showing; });
                    area.hidden = showing;
                    preview.hidden = !showing;
                    if (!showing) {
                        area.focus();
                        return;
                    }

                    preview.innerHTML = '<span class="spinner"></span>';
                    const result = await postJson('/admin/notifications/preview', { text: area.value });
                    preview.innerHTML = result.ok ? result.html : '';
                    if (!result.ok) preview.textContent = result.error || '';
                });
            });
        });
    });

    /**
     * Screenshots for bug reports and replies ([data-shot-uploader]): chosen,
     * dropped or pasted (Ctrl+V anywhere on the page), shrunk in the browser
     * to at most 2560 pixels, uploaded right away and kept in the form as
     * images[]; a click on one opens it large to drop numbered pins on the
     * problem, each with a note (image_pins[<id>]).
     */
    function shotShrink(file) {
        return new Promise(function (resolve) {
            if (!/^image\/(png|jpeg|webp)$/.test(file.type) || !window.createImageBitmap) {
                resolve(file);
                return;
            }

            createImageBitmap(file).then(function (bitmap) {
                const scale = Math.min(1, 2560 / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) {
                    resolve(blob && blob.size < file.size ? blob : file);
                }, 'image/webp', 0.88);
            }, function () { resolve(file); });
        });
    }

    function shotUploader(box) {
        const form = box.closest('form');
        const input = box.querySelector('[data-shot-file]');
        const thumbs = box.querySelector('[data-shot-thumbs]');
        const board = box.querySelector('[data-shot-board]');
        const stage = box.querySelector('[data-shot-stage]');
        const pinList = box.querySelector('[data-shot-pins]');
        const error = box.querySelector('[data-shot-error]');
        const max = parseInt(box.dataset.max, 10) || 6;
        let open = null;

        const fail = function (message) {
            error.textContent = message || '';
            error.hidden = !message;
        };

        const count = function () { return thumbs.querySelectorAll('.shot-thumb').length; };

        const pinsOf = function (thumb) {
            try { return JSON.parse(thumb.querySelector('.shot-pins-input').value || '[]'); } catch (e) { return []; }
        };

        const savePins = function (thumb, pins) {
            thumb.querySelector('.shot-pins-input').value = JSON.stringify(pins);
            const badge = thumb.querySelector('.shot-thumb-pins');
            badge.textContent = pins.length ? String(pins.length) : '';
            badge.hidden = !pins.length;
        };

        const drawBoard = function () {
            if (!open) return;
            const pins = pinsOf(open);
            stage.innerHTML = '';
            pinList.innerHTML = '';

            const img = document.createElement('img');
            img.src = open.querySelector('img').src;
            img.alt = '';
            stage.append(img);

            pins.forEach(function (pin, i) {
                const dot = document.createElement('span');
                dot.className = 'shot-pin';
                dot.style.left = (pin.x * 100) + '%';
                dot.style.top = (pin.y * 100) + '%';
                dot.textContent = String(i + 1);
                stage.append(dot);

                const li = document.createElement('li');
                const note = document.createElement('input');
                note.type = 'text';
                note.maxLength = 200;
                note.placeholder = box.dataset.pinNote || '';
                note.value = pin.note || '';
                note.addEventListener('input', function () {
                    const current = pinsOf(open);
                    current[i].note = note.value;
                    savePins(open, current);
                });
                const drop = document.createElement('button');
                drop.type = 'button';
                drop.className = 'shot-pin-remove';
                drop.textContent = '×';
                drop.setAttribute('aria-label', box.dataset.remove || 'Remove');
                drop.addEventListener('click', function () {
                    const current = pinsOf(open);
                    current.splice(i, 1);
                    savePins(open, current);
                    drawBoard();
                });
                li.append(note, drop);
                pinList.append(li);
            });
        };

        stage.addEventListener('click', function (event) {
            if (!open || event.target.closest('.shot-pin')) return;
            const img = stage.querySelector('img');
            const rect = img.getBoundingClientRect();
            const pins = pinsOf(open);
            if (pins.length >= 12) return;
            pins.push({ x: Math.round((event.clientX - rect.left) / rect.width * 10000) / 10000, y: Math.round((event.clientY - rect.top) / rect.height * 10000) / 10000, note: '' });
            savePins(open, pins);
            drawBoard();
            const notes = pinList.querySelectorAll('input');
            if (notes.length) notes[notes.length - 1].focus();
        });

        const openBoard = function (thumb) {
            thumbs.querySelectorAll('.shot-thumb').forEach(function (t) { t.classList.toggle('open', t === thumb); });
            open = thumb;
            board.hidden = false;
            drawBoard();
        };

        const closeBoard = function () {
            open = null;
            board.hidden = true;
            thumbs.querySelectorAll('.shot-thumb').forEach(function (t) { t.classList.remove('open'); });
        };

        box.querySelector('[data-shot-close]').addEventListener('click', closeBoard);

        const add = async function (file) {
            if (!file || !/^image\//.test(file.type)) return;
            if (count() >= max) {
                fail(box.dataset.tooMany);
                return;
            }
            fail('');

            const thumb = document.createElement('div');
            thumb.className = 'shot-thumb uploading';
            thumb.title = box.dataset.uploading || '';
            const img = document.createElement('img');
            img.alt = '';
            const reader = new FileReader();
            reader.onload = function () { if (!thumb.dataset.id) img.src = reader.result; };
            reader.readAsDataURL(file);
            const pinsBadge = document.createElement('span');
            pinsBadge.className = 'shot-thumb-pins';
            pinsBadge.hidden = true;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'shot-thumb-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', box.dataset.remove || 'Remove');
            const point = document.createElement('button');
            point.type = 'button';
            point.className = 'shot-thumb-point';
            point.textContent = box.dataset.point || '';
            thumb.append(img, pinsBadge, point, remove);
            thumbs.append(thumb);

            remove.addEventListener('click', function () {
                if (open === thumb) closeBoard();
                thumb.remove();
            });
            point.addEventListener('click', function () { openBoard(thumb); });
            img.addEventListener('click', function () { if (!thumb.classList.contains('uploading')) openBoard(thumb); });

            const blob = await shotShrink(file);
            const body = new FormData();
            body.append('image', blob, (file.name || 'screenshot').replace(/\.[a-z0-9]+$/i, '') + (blob.type === 'image/webp' ? '.webp' : ''));
            body.append('_token', csrf());

            let result;
            try {
                const response = await fetch(url('/support/images'), { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' }, body: body });
                result = await response.json();
            } catch (e) {
                result = { ok: false, error: e.message };
            }

            if (!result.ok) {
                thumb.remove();
                fail(result.error);
                return;
            }

            thumb.classList.remove('uploading');
            thumb.title = '';
            thumb.dataset.id = result.id;
            img.src = result.url;
            const idField = document.createElement('input');
            idField.type = 'hidden';
            idField.name = 'images[]';
            idField.value = result.id;
            const pinField = document.createElement('input');
            pinField.type = 'hidden';
            pinField.name = 'image_pins[' + result.id + ']';
            pinField.className = 'shot-pins-input';
            pinField.value = '[]';
            thumb.append(idField, pinField);
        };

        box.querySelector('[data-shot-drop]').addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            Array.prototype.forEach.call(input.files, add);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            box.addEventListener(type, function (event) {
                if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') === -1) return;
                event.preventDefault();
                box.classList.add('dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            box.addEventListener(type, function () { box.classList.remove('dragging'); });
        });
        box.addEventListener('drop', function (event) {
            if (!event.dataTransfer || !event.dataTransfer.files.length) return;
            event.preventDefault();
            Array.prototype.forEach.call(event.dataTransfer.files, add);
        });

        box.shotAdd = add;

        if (form) {
            form.addEventListener('submit', function (event) {
                if (thumbs.querySelector('.uploading')) {
                    event.preventDefault();
                    fail(box.dataset.uploading);
                }
            });
        }
    }

    onPage(function () {
        document.querySelectorAll('[data-shot-uploader]').forEach(function (box) {
            if (box.dataset.ready) return;
            box.dataset.ready = '1';
            shotUploader(box);
        });
    });

    /** Pasting a picture anywhere on a page with a screenshot box adds it there (the one in focus, or the first). */
    document.addEventListener('paste', function (event) {
        const boxes = document.querySelectorAll('[data-shot-uploader]');
        if (!boxes.length || !event.clipboardData) return;

        const files = Array.prototype.filter.call(event.clipboardData.files || [], function (f) { return /^image\//.test(f.type); });
        if (!files.length) return;

        const active = document.activeElement && document.activeElement.closest('form');
        const box = (active && active.querySelector('[data-shot-uploader]')) || boxes[0];
        if (!box.shotAdd) return;

        event.preventDefault();
        files.forEach(box.shotAdd);
    });

    /**
     * The bug report form: steps added one per line (Enter adds the next),
     * and what the browser says about itself in the hidden fields.
     */
    onPage(function () {
        const form = document.querySelector('[data-bug-form]');
        if (!form || form.dataset.ready) return;
        form.dataset.ready = '1';

        const list = form.querySelector('[data-bug-steps]');

        const addStep = function (after) {
            const li = document.createElement('li');
            const field = document.createElement('input');
            field.type = 'text';
            field.name = 'steps[]';
            field.maxLength = 500;
            field.placeholder = list.dataset.placeholder || '';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'bug-step-remove';
            remove.setAttribute('data-step-remove', '');
            remove.setAttribute('aria-label', list.dataset.remove || 'Remove');
            remove.textContent = '×';
            li.append(field, remove);
            if (after && after.nextSibling) list.insertBefore(li, after.nextSibling);
            else list.append(li);
            field.focus();
        };

        form.addEventListener('click', function (event) {
            if (event.target.closest('[data-step-add]')) addStep(null);
            const remove = event.target.closest('[data-step-remove]');
            if (remove) {
                const li = remove.closest('li');
                if (list.children.length > 1) li.remove();
                else li.querySelector('input').value = '';
            }
        });

        list.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || !event.target.matches('input')) return;
            event.preventDefault();
            const li = event.target.closest('li');
            const next = li.nextElementSibling;
            if (next && !next.querySelector('input').value) next.querySelector('input').focus();
            else addStep(li);
        });

        const root = document.documentElement;
        const env = {
            screen: window.screen ? window.screen.width + '×' + window.screen.height + ' @' + (window.devicePixelRatio || 1) + 'x' : '',
            viewport: window.innerWidth + '×' + window.innerHeight,
            language: navigator.language || '',
            timezone: (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '',
            theme: [root.dataset.themeLight, root.dataset.themeDark, root.dataset.themeMode].filter(Boolean).join(' / '),
            platform: (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || ''
        };

        form.querySelectorAll('[data-env]').forEach(function (field) { field.value = env[field.dataset.env] || ''; });
    });

    /** Answering a questionnaire: a bar and a count of the questions answered so far. */
    onPage(function () {
        const form = document.querySelector('[data-survey-form]');
        if (!form) return;

        const bar = form.querySelector('[data-survey-bar]');
        const label = form.querySelector('[data-survey-count]');
        const total = parseInt(form.dataset.total, 10) || 0;

        const update = function () {
            let done = 0;
            form.querySelectorAll('[data-question]').forEach(function (q) {
                const filled = Array.prototype.some.call(q.querySelectorAll('input, textarea, select'), function (field) {
                    if (field.type === 'radio' || field.type === 'checkbox') return field.checked;
                    return field.value.trim() !== '';
                });
                q.classList.toggle('is-answered', filled);
                if (filled) done++;
            });
            if (bar) bar.style.width = (total ? done * 100 / total : 0) + '%';
            if (label) label.textContent = (label.dataset.template || '%d/%d').replace('%d', done).replace('%d', total);
        };

        form.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    });

    /** Administration → a bug report: quick status buttons, the duplicate field, and the reply box's label (reply, note or fix message). */
    onPage(function () {
        const form = document.querySelector('[data-bug-manage]');
        if (!form) return;

        const status = form.querySelector('[data-bug-status]');
        const internal = form.querySelector('[data-bug-internal]');
        const label = form.querySelector('[data-body-label]');
        const body = form.querySelector('[data-bug-body]');
        const notice = form.querySelector('[data-bug-notice]');
        const duplicate = form.querySelector('[data-duplicate-field]');
        const original = status.value;

        const refresh = function () {
            const changed = status.value !== original;
            if (changed) internal.checked = false;
            internal.disabled = changed;
            label.textContent = status.value === 'fixed' && changed ? label.dataset.fixed : (internal.checked ? label.dataset.note : label.dataset.reply);
            notice.hidden = internal.checked && !changed;
            duplicate.hidden = status.value !== 'duplicate';
            form.classList.toggle('is-note', internal.checked && !changed);
        };

        form.addEventListener('click', function (event) {
            const quick = event.target.closest('[data-bug-quick]');
            if (!quick) return;
            status.value = quick.dataset.bugQuick;
            refresh();
            body.focus();
        });

        status.addEventListener('change', refresh);
        internal.addEventListener('change', refresh);
        refresh();
    });

    /**
     * The questionnaire builder: settings, and sections of questions drawn
     * from the page's JSON and edited in place — kind, question, hint,
     * required, choices or scale; add, move, copy, remove — then saved as
     * a whole (and opened for answers, if asked). Every text is kept per
     * language: the bar on top picks the language being written and counts
     * the texts still missing in each; an empty box shows the text of
     * another language as a hint.
     */
    onPage(function () {
        const root = document.querySelector('[data-survey-builder]');
        if (!root || root.dataset.ready) return;
        root.dataset.ready = '1';

        const state = JSON.parse(root.querySelector('[data-survey-data]').textContent);
        const S = JSON.parse(root.querySelector('[data-survey-strings]').textContent);
        const sections = root.querySelector('[data-survey-sections]');
        const langBar = root.querySelector('[data-survey-langs]');
        const stateLabel = root.querySelector('[data-survey-state]');
        const errorBox = root.querySelector('[data-survey-error]');
        const usersBox = root.querySelector('[data-survey-users]');
        const picker = root.querySelector('[data-survey-picker]');
        const CHOICE_TYPES = ['radio', 'checkbox', 'select'];
        const LOCALES = Object.keys(S.locales);
        let lang = LOCALES.indexOf(S.locale) !== -1 ? S.locale : LOCALES[0];
        let dirty = false;

        const asMap = function (value) {
            if (value && typeof value === 'object' && !Array.isArray(value)) return value;
            const map = {};
            if (typeof value === 'string' && value !== '') map[lang] = value;
            return map;
        };

        const normalize = function () {
            state.title = asMap(state.title);
            state.description = asMap(state.description);
            if (!state.groups || !state.groups.length) state.groups = [{ id: null, title: {}, description: {}, questions: [] }];
            state.groups.forEach(function (g) {
                g.title = asMap(g.title);
                g.description = asMap(g.description);
                g.questions.forEach(function (q) {
                    q.label = asMap(q.label);
                    q.help = asMap(q.help);
                    q.options = q.options && !Array.isArray(q.options) ? q.options : {};
                    if (Array.isArray(q.options.choices)) {
                        q.options.choices = q.options.choices.map(function (c) {
                            return c && typeof c === 'object' && !Array.isArray(c) ? { key: c.key || null, text: asMap(c.text) } : { key: null, text: asMap(c) };
                        });
                    }
                    if (q.type === 'scale') {
                        q.options.min_label = asMap(q.options.min_label);
                        q.options.max_label = asMap(q.options.max_label);
                    }
                });
            });
        };
        normalize();

        const filled = function (map) {
            return Object.keys(map).some(function (k) { return String(map[k] || '') !== ''; });
        };

        const missingCounts = function () {
            const counts = {};
            LOCALES.forEach(function (l) { counts[l] = 0; });
            const count = function (map) {
                if (!filled(map)) return;
                LOCALES.forEach(function (l) { if (!map[l]) counts[l]++; });
            };
            count(state.title);
            count(state.description);
            state.groups.forEach(function (g) {
                count(g.title);
                count(g.description);
                g.questions.forEach(function (q) {
                    count(q.label);
                    count(q.help);
                    (q.options.choices || []).forEach(function (c) { count(c.text); });
                    if (q.type === 'scale') { count(q.options.min_label); count(q.options.max_label); }
                });
            });
            return counts;
        };

        const drawLangBar = function () {
            const counts = missingCounts();
            langBar.innerHTML = '';
            LOCALES.forEach(function (l) {
                const button = document.createElement('button');
                button.type = 'button';
                button.setAttribute('role', 'tab');
                button.setAttribute('aria-selected', l === lang ? 'true' : 'false');
                button.className = l === lang ? 'active' : '';
                const name = document.createElement('span');
                name.textContent = S.locales[l];
                const badge = document.createElement('span');
                badge.className = 'lang-count' + (counts[l] ? ' missing' : '');
                badge.textContent = counts[l] ? String(counts[l]) : '✓';
                badge.title = counts[l] ? S.missing.replace('%d', counts[l]) : S.complete;
                button.append(name, badge);
                button.addEventListener('click', function () {
                    lang = l;
                    drawSettings();
                    draw();
                });
                langBar.append(button);
            });
        };

        const touch = function () {
            dirty = true;
            stateLabel.textContent = S.unsaved;
            drawLangBar();
        };

        const hintFor = function (map, base) {
            if (map[lang]) return base || '';
            const other = LOCALES.find(function (l) { return l !== lang && map[l]; });
            return other ? S.locales[other] + ': ' + map[other] : (base || '');
        };

        const bindText = function (field, map, base) {
            field.value = map[lang] || '';
            field.placeholder = hintFor(map, base);
            field.classList.toggle('untranslated', !map[lang] && filled(map));
            field.oninput = function () {
                if (field.value === '') delete map[lang];
                else map[lang] = field.value;
                field.placeholder = hintFor(map, base);
                field.classList.toggle('untranslated', !map[lang] && filled(map));
                touch();
            };
            return field;
        };

        const el = function (tag, attrs, children) {
            const node = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (key) {
                if (key === 'text') node.textContent = attrs[key];
                else if (key === 'className') node.className = attrs[key];
                else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), attrs[key]);
                else node.setAttribute(key, attrs[key]);
            });
            (children || []).forEach(function (child) { if (child) node.append(child); });
            return node;
        };

        const textBox = function (map, attrs, base) {
            return bindText(el(attrs.rows ? 'textarea' : 'input', attrs.rows ? attrs : Object.assign({ type: 'text' }, attrs)), map, base);
        };

        const iconButton = function (label, glyph, handler, extra) {
            return el('button', { type: 'button', className: 'builder-icon' + (extra ? ' ' + extra : ''), title: label, 'aria-label': label, text: glyph, onclick: handler });
        };

        const move = function (list, index, delta) {
            const target = index + delta;
            if (target < 0 || target >= list.length) return;
            const item = list.splice(index, 1)[0];
            list.splice(target, 0, item);
            touch();
            draw();
        };

        const freshQuestion = function () {
            return { id: null, type: 'radio', label: {}, help: {}, required: false, options: { choices: [{ key: null, text: {} }, { key: null, text: {} }] } };
        };

        const questionCard = function (group, q, qi) {
            const typeSelect = el('select', { 'aria-label': S.type, onchange: function () {
                q.type = typeSelect.value;
                if (CHOICE_TYPES.indexOf(q.type) !== -1 && !Array.isArray(q.options.choices)) q.options = { choices: [{ key: null, text: {} }, { key: null, text: {} }] };
                if (q.type === 'scale') q.options = { min: 1, max: 5, min_label: {}, max_label: {} };
                if (CHOICE_TYPES.indexOf(q.type) === -1 && q.type !== 'scale') q.options = {};
                touch();
                draw();
            } });
            Object.keys(S.types).forEach(function (type) {
                const option = el('option', { value: type, text: S.types[type] });
                if (type === q.type) option.selected = true;
                typeSelect.append(option);
            });

            const required = el('input', { type: 'checkbox', onchange: function () { q.required = required.checked; touch(); } });
            required.checked = !!q.required;

            let extra = null;

            if (CHOICE_TYPES.indexOf(q.type) !== -1) {
                const list = el('ol', { className: 'builder-choices' });
                q.options.choices.forEach(function (choice, ci) {
                    const field = textBox(choice.text, { maxlength: '200' }, S.choice + ' ' + (ci + 1));
                    field.addEventListener('keydown', function (event) {
                        if (event.key !== 'Enter') return;
                        event.preventDefault();
                        q.options.choices.splice(ci + 1, 0, { key: null, text: {} });
                        touch();
                        draw();
                        const fields = sections.querySelectorAll('[data-q="' + group.key + '-' + qi + '"] .builder-choices input');
                        if (fields[ci + 1]) fields[ci + 1].focus();
                    });
                    list.append(el('li', {}, [
                        el('span', { className: 'builder-choice-mark ' + q.type }),
                        field,
                        iconButton(S.remove, '×', function () { q.options.choices.splice(ci, 1); touch(); draw(); }, 'small'),
                    ]));
                });
                extra = el('div', { className: 'builder-options' }, [
                    el('span', { className: 'builder-sub', text: S.choices }),
                    list,
                    el('button', { type: 'button', className: 'btn small ghost', text: '+ ' + S.add_choice, onclick: function () { q.options.choices.push({ key: null, text: {} }); touch(); draw(); } }),
                ]);
            } else if (q.type === 'scale') {
                const from = el('select', { onchange: function () { q.options.min = parseInt(from.value, 10); touch(); } });
                [0, 1].forEach(function (n) { const o = el('option', { value: String(n), text: String(n) }); if (n === q.options.min) o.selected = true; from.append(o); });
                const to = el('select', { onchange: function () { q.options.max = parseInt(to.value, 10); touch(); } });
                [2, 3, 4, 5, 6, 7, 8, 9, 10].forEach(function (n) { const o = el('option', { value: String(n), text: String(n) }); if (n === q.options.max) o.selected = true; to.append(o); });
                extra = el('div', { className: 'builder-options builder-scale' }, [
                    el('label', {}, [el('span', { text: S.scale_from }), from]),
                    el('label', {}, [el('span', { text: S.scale_to }), to]),
                    el('label', { className: 'grow' }, [el('span', { text: S.scale_min_label }), textBox(q.options.min_label, { maxlength: '60' }, S.scale_min_label)]),
                    el('label', { className: 'grow' }, [el('span', { text: S.scale_max_label }), textBox(q.options.max_label, { maxlength: '60' }, S.scale_max_label)]),
                ]);
            }

            return el('article', { className: 'builder-question', 'data-q': group.key + '-' + qi }, [
                el('div', { className: 'builder-question-head' }, [
                    el('span', { className: 'builder-num', text: String(qi + 1) }),
                    typeSelect,
                    el('span', { className: 'builder-tools' }, [
                        iconButton(S.move_up, '↑', function () { move(group.questions, qi, -1); }),
                        iconButton(S.move_down, '↓', function () { move(group.questions, qi, 1); }),
                        iconButton(S.duplicate, '⧉', function () {
                            const copy = JSON.parse(JSON.stringify(q));
                            copy.id = null;
                            group.questions.splice(qi + 1, 0, copy);
                            touch();
                            draw();
                        }),
                        iconButton(S.remove, '🗑', function () { group.questions.splice(qi, 1); touch(); draw(); }, 'danger'),
                    ]),
                ]),
                textBox(q.label, { maxlength: '300', className: 'builder-question-label' }, S.question_label),
                textBox(q.help, { maxlength: '500', className: 'builder-help' }, S.help),
                extra,
                el('label', { className: 'inline builder-required' }, [required, el('span', { text: S.required })]),
            ]);
        };

        const draw = function () {
            sections.innerHTML = '';
            drawLangBar();

            state.groups.forEach(function (group, gi) {
                group.key = gi;

                const questions = el('div', { className: 'builder-questions' });
                group.questions.forEach(function (q, qi) { questions.append(questionCard(group, q, qi)); });
                if (!group.questions.length) questions.append(el('p', { className: 'muted small builder-empty', text: S.empty_section }));

                sections.append(el('section', { className: 'card builder-section' }, [
                    el('div', { className: 'builder-section-head' }, [
                        el('span', { className: 'builder-section-label', text: S.section + ' ' + (gi + 1) }),
                        el('span', { className: 'builder-tools' }, [
                            iconButton(S.move_up, '↑', function () { move(state.groups, gi, -1); }),
                            iconButton(S.move_down, '↓', function () { move(state.groups, gi, 1); }),
                            state.groups.length > 1 ? iconButton(S.remove_section, '🗑', function () {
                                if (group.questions.length && !window.confirm(S.remove_section_confirm)) return;
                                state.groups.splice(gi, 1);
                                touch();
                                draw();
                            }, 'danger') : null,
                        ]),
                    ]),
                    textBox(group.title, { maxlength: '140', className: 'builder-section-title' }, S.section_title),
                    textBox(group.description, { rows: '1', maxlength: '1000', className: 'builder-section-desc' }, S.section_desc),
                    questions,
                    el('button', { type: 'button', className: 'btn small', text: '+ ' + S.add_question, onclick: function () {
                        group.questions.push(freshQuestion());
                        touch();
                        draw();
                        const labels = sections.querySelectorAll('.builder-section')[gi].querySelectorAll('.builder-question-label');
                        if (labels.length) labels[labels.length - 1].focus();
                    } }),
                ]));
            });
        };

        const drawSettings = function () {
            root.querySelectorAll('[data-field][data-i18n]').forEach(function (field) {
                if (!field.dataset.base) field.dataset.base = field.placeholder;
                bindText(field, state[field.dataset.field], field.dataset.base);
            });
        };

        root.querySelectorAll('[data-field]:not([data-i18n])').forEach(function (field) {
            const key = field.dataset.field;
            if (field.type === 'radio') field.checked = state[key] === field.value;
            else field.value = state[key] || '';

            field.addEventListener(field.type === 'radio' ? 'change' : 'input', function () {
                if (field.type === 'radio' && !field.checked) return;
                state[key] = field.value;
                if (key === 'audience') usersBox.hidden = state.audience !== 'users';
                touch();
            });
        });

        usersBox.hidden = state.audience !== 'users';

        const preset = function () {
            if (!picker.tomselect) return;
            (state.users || []).forEach(function (u) {
                picker.tomselect.addOption({ id: u.id, name: u.name });
                picker.tomselect.addItem(String(u.id), true);
            });
            picker.tomselect.on('change', touch);
        };
        if (picker.tomselect) preset();
        else setTimeout(function () { initPicker(picker); preset(); }, 0);

        root.querySelector('[data-add-section]').addEventListener('click', function () {
            state.groups.push({ id: null, title: {}, description: {}, questions: [freshQuestion()] });
            touch();
            draw();
        });

        root.querySelectorAll('[data-survey-save]').forEach(function (button) {
            button.addEventListener('click', async function () {
                errorBox.hidden = true;
                const payload = JSON.parse(JSON.stringify(state));
                payload.users = picker.tomselect ? picker.tomselect.getValue().map(Number) : [];
                payload.groups.forEach(function (g) { delete g.key; });

                root.querySelectorAll('[data-survey-save]').forEach(function (b) { b.disabled = true; });
                stateLabel.textContent = S.saving;

                const result = await postJson('/admin/surveys/save', { survey: JSON.stringify(payload), status: button.dataset.status || '' });

                root.querySelectorAll('[data-survey-save]').forEach(function (b) { b.disabled = false; });

                if (!result.ok) {
                    errorBox.textContent = result.error || 'Error';
                    errorBox.hidden = false;
                    stateLabel.textContent = S.unsaved;
                    return;
                }

                dirty = false;
                if (window.Turbo) window.Turbo.visit(result.redirect, { action: 'replace' });
                else window.location.href = result.redirect;
            });
        });

        window.addEventListener('beforeunload', function (event) {
            if (!dirty || !document.body.contains(root)) return;
            event.preventDefault();
            event.returnValue = '';
        });

        drawSettings();
        draw();
    });

    /** Error pages: "Back" shows only when there is a page of this site to go back to. */
    onPage(function () {
        document.querySelectorAll('[data-history-back]').forEach(function (button) {
            let sameSite = false;
            try { sameSite = document.referrer !== '' && new URL(document.referrer).origin === window.location.origin; } catch (e) { }
            button.hidden = !(sameSite && window.history.length > 1);
            button.onclick = function () { window.history.back(); };
        });
    });

    /**
     * "Add a streamer" inside a collab form ([data-cast-add]): a Twitch
     * search whose "+" adds the person to the streamers list and ticks them
     * in this collab's cast, without leaving the form; people who use
     * StreamOrg are marked (they can plan together).
     */
    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-cast-add-toggle]');
        if (!toggle) return;
        const panel = toggle.closest('[data-cast-add]').querySelector('[data-cast-add-panel]');
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        if (!panel.hidden) panel.querySelector('[data-cast-add-search]').focus();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches('[data-cast-add-search]')) event.preventDefault();
    });

    function castAddRow(box, item, id) {
        const fieldset = box.closest('fieldset') || box.parentNode;
        const list = fieldset.querySelector('.cast-list');
        const existing = list.querySelector('input[name="streamers[]"][value="' + id + '"]');

        if (existing) {
            existing.checked = true;
            existing.dispatchEvent(new Event('change', { bubbles: true }));
            existing.closest('.cast-row').scrollIntoView({ block: 'nearest' });
            return;
        }

        const template = document.querySelector('template[data-cast-template]');
        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML.replace(/__ID__/g, String(id));
        const row = holder.firstElementChild;
        row.querySelector('[data-cast-name]').textContent = item.name;
        row.querySelector('[data-cast-streamorg]').hidden = !item.on_streamorg;
        row.classList.add('just-added');
        list.prepend(row);
        row.querySelector('input').dispatchEvent(new Event('change', { bubbles: true }));
    }

    document.addEventListener('input', function (event) {
        const search = event.target.closest('[data-cast-add-search]');
        if (!search) return;

        const box = search.closest('[data-cast-add]');
        const results = box.querySelector('[data-cast-add-results]');
        clearTimeout(search._timer);

        search._timer = setTimeout(async function () {
            const term = search.value.trim();
            if (term.length < 2) { results.innerHTML = ''; return; }

            const mine = (search._seq = (search._seq || 0) + 1);
            results.innerHTML = skeletonRows(2);
            const response = await fetch(url('/streamers/search') + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json' } });
            const data = await response.json().catch(function () { return { ok: false }; });
            if (mine !== search._seq) return;

            results.innerHTML = '';

            if (!data.ok || !data.results.length) {
                const empty = document.createElement('p');
                empty.className = 'muted small';
                empty.textContent = data.ok ? results.dataset.empty : (data.error || 'Error');
                results.append(empty);
                return;
            }

            data.results.slice(0, 8).forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'cast-add-result';

                const img = document.createElement('img');
                img.alt = '';
                img.loading = 'lazy';
                if (item.avatar) img.src = item.avatar;

                const name = document.createElement('span');
                name.className = 'cast-add-name';
                const strong = document.createElement('strong');
                strong.textContent = item.name;
                const login = document.createElement('small');
                login.className = 'muted';
                login.textContent = '@' + item.login;
                name.append(strong, ' ', login);

                if (item.on_streamorg) {
                    const badge = document.createElement('span');
                    badge.className = 'badge on-streamorg';
                    badge.textContent = results.dataset.streamorg;
                    badge.title = results.dataset.streamorgHint;
                    name.append(' ', badge);
                }

                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'btn small';
                add.textContent = '+';
                add.addEventListener('click', async function () {
                    add.disabled = true;
                    add.textContent = '…';
                    const result = await postJson('/streamers/import', { ref: item.ref });

                    if (!result.ok) {
                        add.disabled = false;
                        add.textContent = '+';
                        window.alert(result.error || 'Error');
                        return;
                    }

                    add.textContent = '✓';
                    row.classList.add('done');
                    login.textContent = '@' + item.login + ' · ' + results.dataset.added;
                    castAddRow(box, item, result.id);
                });

                row.append(img, name, add);
                results.append(row);
            });
        }, 320);
    });

    function userMenu(open) {
        const button = document.getElementById('usermenu-button');
        const panel = document.getElementById('usermenu-panel');
        if (!button || !panel) return;

        const show = open === undefined ? panel.hidden : open;
        panel.hidden = !show;
        button.setAttribute('aria-expanded', show ? 'true' : 'false');
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('#usermenu-button')) {
            userMenu();
            return;
        }

        if (!event.target.closest('#usermenu-panel')) userMenu(false);

        if (event.target.closest('#nav-backdrop, [data-close-nav]')) setNavOpen(false);
    });

    /** Light / dark / auto from the user menu: applied at once, saved in the background. */
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-set-mode]');
        if (!button) return;

        const mode = button.dataset.setMode;

        button.parentElement.querySelectorAll('[data-set-mode]').forEach(function (b) {
            b.setAttribute('aria-checked', b === button ? 'true' : 'false');
        });

        if (window.StreamOrgTheme) window.StreamOrgTheme.setMode(mode);

        const meta = document.querySelector('meta[name="streamorg-theme"]');
        if (meta) {
            const parts = meta.content.split(' ');
            parts[2] = mode;
            meta.content = parts.join(' ');
        }

        postJson('/profile/theme-mode', { mode: mode });
    });

    /** Appearance tab: preview the chosen family and mode before saving. */
    document.addEventListener('change', function (event) {
        const form = event.target.closest('[data-appearance]');

        if (form) {
            const family = form.querySelector('input[name="theme"]:checked');
            const mode = form.querySelector('input[name="theme_mode"]:checked');
            const root = document.documentElement;

            if (family) {
                root.dataset.themeLight = family.dataset.light;
                root.dataset.themeDark = family.dataset.dark;
            }

            if (mode) root.dataset.themeMode = mode.value;
            if (window.StreamOrgTheme) window.StreamOrgTheme.apply();
            return;
        }

        const file = event.target.closest('input[data-autosubmit-file]');
        if (file && file.files.length) file.form.requestSubmit();
    });

    function closeNavGroups(except) {
        document.querySelectorAll('.navgroup[data-open]').forEach(function (group) {
            if (group === except) return;
            delete group.dataset.open;
            const top = group.querySelector('.navtop');
            if (top) top.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function (event) {
        const top = event.target.closest('.navtop');

        if (top) {
            if (window.matchMedia('(max-width: 900px)').matches) return;

            const group = top.closest('.navgroup');
            const open = 'open' in group.dataset;

            closeNavGroups(group);

            if (open) {
                delete group.dataset.open;
                top.setAttribute('aria-expanded', 'false');
            } else {
                group.dataset.open = '';
                top.setAttribute('aria-expanded', 'true');
            }

            return;
        }

        if (!event.target.closest('.navmenu')) {
            closeNavGroups(null);
        }

        const navToggle = navToggleEl();

        if (navToggle && !event.target.closest('#mainnav') && !event.target.closest('#nav-toggle')
            && document.body.classList.contains('nav-open')) {
            setNavOpen(false);
        }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#nav-toggle')) return;

        setNavOpen(!document.body.classList.contains('nav-open'));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;

        closeNavGroups(null);

        const panel = document.getElementById('usermenu-panel');

        if (panel && !panel.hidden) {
            userMenu(false);
            document.getElementById('usermenu-button').focus();
        }

        const navToggle = navToggleEl();

        if (navToggle && document.body.classList.contains('nav-open')) {
            setNavOpen(false);
            navToggle.focus();
        }
    });

    window.matchMedia('(max-width: 900px)').addEventListener('change', function (e) {
        if (!e.matches) setNavOpen(false);
    });

    function applyDocumentMeta() {
        const theme = document.querySelector('meta[name="streamorg-theme"]');
        const lang  = document.querySelector('meta[name="streamorg-lang"]');

        if (theme) {
            const parts = theme.content.split(' ');
            const root = document.documentElement;
            root.dataset.themeLight = parts[0];
            root.dataset.themeDark = parts[1];
            root.dataset.themeMode = parts[2];
            if (window.StreamOrgTheme) window.StreamOrgTheme.apply();
        }
        if (lang) document.documentElement.lang = lang.content;
    }

    /** Stream-safe secret fields (overlay links): shown only while focused. */
    document.addEventListener('focusin', function (event) {
        const field = event.target.closest && event.target.closest('input[data-reveal-on-focus]');
        if (!field) return;
        field.type = 'text';
        field.select();
    });
    document.addEventListener('focusout', function (event) {
        const field = event.target.closest && event.target.closest('input[data-reveal-on-focus]');
        if (field) field.type = 'password';
    });

    let studioSound = null;
    let studioPlayback = null;

    /** Plays a sound (or a part of it) through the shared SoundLib, stopping the one before. */
    function studioPlay(src, options) {
        if (typeof window.SoundLib !== 'function') return null;
        if (!studioSound) studioSound = new window.SoundLib('');
        if (studioPlayback) studioPlayback.stop();
        studioPlayback = studioSound.play(src, options || {});
        return studioPlayback;
    }

    function studioStop() {
        if (studioPlayback) studioPlayback.stop();
        studioPlayback = null;
    }

    document.addEventListener('turbo:before-cache', studioStop);

    function readJson(root, selector, fallback) {
        const el = root.querySelector(selector);
        if (!el) return fallback;
        try { return JSON.parse(el.textContent); } catch (e) { return fallback; }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function seconds(value) {
        const n = Math.max(0, Number(value) || 0);
        const m = Math.floor(n / 60);
        const s = n - m * 60;
        return m + ':' + (s < 10 ? '0' : '') + s.toFixed(2);
    }

    function megabytes(bytes) {
        return (bytes / 1048576).toFixed(1);
    }

    /** A sound file's length, measured in the browser before upload (null when it cannot be read). */
    async function soundLength(file) {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context) return null;

        try {
            const context = new Context();
            const decoded = await context.decodeAudioData(await file.arrayBuffer());
            context.close();
            return decoded.duration;
        } catch (e) {
            return null;
        }
    }

    /**
     * Media library: uploads (drop, paste a pick), and per asset a player,
     * rename, the sprite segment editor (start and end marked while
     * listening) and delete. Shared media in a user's library is read-only.
     */
    function mediaLibrary(box) {
        const S = readJson(box, '[data-media-strings]', {});
        const sharedLibrary = box.dataset.shared === '1';
        const list = box.querySelector('[data-media-list]');
        const empty = box.querySelector('[data-media-empty]');
        const input = box.querySelector('[data-media-input]');
        const drop = box.querySelector('[data-media-drop]');
        const error = box.querySelector('[data-media-error]');
        const usage = box.querySelector('[data-media-usage]');
        let assets = readJson(box, '[data-media-assets]', []);

        const fail = function (message) {
            error.textContent = message || '';
            error.hidden = !message;
        };

        const setUsage = function (bytes) {
            if (!usage || bytes === null || bytes === undefined) return;
            usage.textContent = (S.usage || '%s / %s MB').replace('%s', megabytes(bytes)).replace('%s', megabytes(parseInt(usage.dataset.quota, 10) || 0));
        };

        const segmentRow = function (segment, audio, rows, onChange) {
            const row = el('div', 'segment-row');
            row.dataset.key = segment.key || '';

            const name = el('input');
            name.type = 'text';
            name.maxLength = 40;
            name.value = segment.name || '';
            name.placeholder = S.segment_name;
            name.setAttribute('aria-label', S.segment_name);
            name.dataset.part = 'name';

            const timeField = function (value, label, part) {
                const wrap = el('span', 'segment-time');
                const field = el('input');
                field.type = 'number';
                field.min = '0';
                field.step = '0.01';
                field.value = Number(value || 0).toFixed(2);
                field.setAttribute('aria-label', label);
                field.dataset.part = part;
                const mark = el('button', 'btn small ghost', S.mark_short);
                mark.type = 'button';
                mark.title = S.mark + ' (' + label + ')';
                mark.addEventListener('click', function () {
                    field.value = audio.currentTime.toFixed(2);
                    onChange();
                });
                wrap.append(el('small', 'muted', label), field, mark);
                return wrap;
            };

            const start = timeField(segment.start, S.start, 'start');
            const end = timeField((segment.start || 0) + (segment.duration || 0), S.end, 'end');

            const play = el('button', 'btn small', '▶');
            play.type = 'button';
            play.title = S.play;
            play.addEventListener('click', function () {
                const from = Number(start.querySelector('input').value) || 0;
                const to = Number(end.querySelector('input').value) || 0;
                audio.pause();
                if (to > from) studioPlay(audio.currentSrc || audio.src, { start: from, duration: to - from });
            });

            const remove = el('button', 'btn small danger-btn', '×');
            remove.type = 'button';
            remove.title = S.remove;
            remove.addEventListener('click', function () {
                row.remove();
                onChange();
            });

            row.addEventListener('input', onChange);
            row.append(name, start, end, play, remove);
            rows.append(row);
        };

        const readSegments = function (rows) {
            return Array.prototype.map.call(rows.querySelectorAll('.segment-row'), function (row) {
                const start = Number(row.querySelector('[data-part="start"]').value) || 0;
                const end = Number(row.querySelector('[data-part="end"]').value) || 0;
                return { key: row.dataset.key, name: row.querySelector('[data-part="name"]').value, start: start, duration: Math.max(0, end - start) };
            });
        };

        const card = function (asset) {
            const editable = sharedLibrary || !asset.shared;
            const item = el('article', 'media-item kind-' + asset.kind);
            const head = el('div', 'media-head');

            if (asset.kind === 'image') {
                const img = el('img', 'media-thumb');
                img.src = asset.url;
                img.alt = '';
                img.loading = 'lazy';
                head.append(img);
            } else {
                const icon = el('span', 'media-icon', '♪');
                icon.setAttribute('aria-hidden', 'true');
                head.append(icon);
            }

            const titleBox = el('div', 'media-title');
            let nameField = null;

            if (editable) {
                nameField = el('input');
                nameField.type = 'text';
                nameField.maxLength = 80;
                nameField.value = asset.name;
                nameField.setAttribute('aria-label', S.name);
                titleBox.append(nameField);
            } else {
                titleBox.append(el('strong', '', asset.name));
            }

            const meta = [];
            if (asset.duration) meta.push(seconds(asset.duration));
            if (asset.width) meta.push(asset.width + '×' + asset.height);
            meta.push(megabytes(asset.bytes) + ' MB');
            const metaLine = el('small', 'muted', meta.join(' · '));
            if (asset.shared && !sharedLibrary) metaLine.prepend(el('span', 'badge', S.shared), ' ');
            titleBox.append(metaLine);
            head.append(titleBox);
            item.append(head);

            let rows = null;
            let audio = null;

            if (asset.kind === 'sound') {
                audio = el('audio', 'media-audio');
                audio.controls = true;
                audio.preload = 'none';
                audio.src = asset.url;
                item.append(audio);

                const details = el('details', 'media-segments');
                const summary = el('summary', '', S.segments + ' (' + asset.segments.length + ')');
                details.append(summary);
                rows = el('div', 'segment-rows');

                if (editable) {
                    details.append(el('p', 'muted small', S.segments_hint));
                    asset.segments.forEach(function (segment) { segmentRow(segment, audio, rows, changed); });
                    details.append(rows);
                    const add = el('button', 'btn small', '+ ' + S.add_segment);
                    add.type = 'button';
                    add.addEventListener('click', function () {
                        const at = audio.currentTime || 0;
                        segmentRow({ key: '', name: '', start: at, duration: Math.min(1, (asset.duration || at + 1) - at) }, audio, rows, changed);
                        changed();
                    });
                    details.append(add);
                } else {
                    asset.segments.forEach(function (segment) {
                        const row = el('div', 'segment-row readonly');
                        const play = el('button', 'btn small', '▶');
                        play.type = 'button';
                        play.addEventListener('click', function () { studioPlay(asset.url, { start: segment.start, duration: segment.duration }); });
                        row.append(play, el('span', '', segment.name), el('small', 'muted', seconds(segment.start) + ' – ' + seconds(segment.start + segment.duration)));
                        rows.append(row);
                    });
                    details.append(rows);
                }

                if (editable || asset.segments.length) item.append(details);
            }

            let save = null;

            function changed() {
                if (save) save.disabled = false;
            }

            if (editable) {
                const actions = el('div', 'card-actions');
                save = el('button', 'btn small primary', S.save);
                save.type = 'button';
                save.disabled = true;
                nameField.addEventListener('input', changed);

                save.addEventListener('click', async function () {
                    save.disabled = true;
                    const result = await postJson('/media-library/update', {
                        id: asset.id,
                        name: nameField.value,
                        segments: JSON.stringify(rows ? readSegments(rows) : [])
                    });

                    if (!result.ok) {
                        save.disabled = false;
                        fail(result.error);
                        return;
                    }

                    fail('');
                    assets = assets.map(function (a) { return a.id === asset.id ? result.asset : a; });
                    item.replaceWith(card(result.asset));
                });

                const remove = el('button', 'btn small danger-btn', S.delete);
                remove.type = 'button';
                remove.addEventListener('click', async function () {
                    if (!(await confirmModal(S.delete, S.delete_confirm))) return;
                    const result = await postJson('/media-library/delete', { id: asset.id });

                    if (!result.ok) {
                        fail(result.error);
                        return;
                    }

                    assets = assets.filter(function (a) { return a.id !== asset.id; });
                    item.remove();
                    if (!sharedLibrary) setUsage(result.usage);
                    empty.hidden = assets.length > 0;
                });

                actions.append(save, remove);
                item.append(actions);
            }

            return item;
        };

        const render = function () {
            list.replaceChildren.apply(list, assets.map(card));
            empty.hidden = assets.length > 0;
        };

        const upload = async function (file) {
            const pending = el('article', 'media-item uploading', S.uploading + ' ' + file.name);
            list.prepend(pending);
            empty.hidden = true;

            const body = new FormData();
            body.append('file', file, file.name);
            body.append('_token', csrf());
            if (sharedLibrary) body.append('shared', '1');

            if (/^audio\//.test(file.type) || /\.(mp3|ogg|wav)$/i.test(file.name)) {
                const length = await soundLength(file);
                if (length) body.append('duration', String(length));
            }

            let result;
            try {
                const response = await fetch(url('/media-library'), { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' }, body: body });
                result = await response.json();
            } catch (e) {
                result = { ok: false, error: e.message };
            }

            pending.remove();

            if (!result.ok) {
                fail(file.name + ': ' + result.error);
                empty.hidden = assets.length > 0;
                return;
            }

            fail('');
            assets.unshift(result.asset);
            list.prepend(card(result.asset));
            if (!sharedLibrary) setUsage(result.usage);
        };

        const uploadAll = async function (files) {
            for (const file of Array.prototype.slice.call(files)) {
                await upload(file);
            }
        };

        drop.addEventListener('click', function () { input.click(); });
        drop.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });
        input.addEventListener('change', function () {
            uploadAll(input.files);
            input.value = '';
        });
        ['dragenter', 'dragover'].forEach(function (type) {
            drop.addEventListener(type, function (event) {
                if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') === -1) return;
                event.preventDefault();
                drop.classList.add('dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            drop.addEventListener(type, function () { drop.classList.remove('dragging'); });
        });
        drop.addEventListener('drop', function (event) {
            if (!event.dataTransfer || !event.dataTransfer.files.length) return;
            event.preventDefault();
            uploadAll(event.dataTransfer.files);
        });

        render();
    }

    onPage(function () {
        document.querySelectorAll('[data-media-library]').forEach(mediaLibrary);
    });

    /**
     * Tabs inside a form (the overlay's settings and its sounds per viewer):
     * every panel stays in the form, only one shows; the open tab is
     * remembered for the page while the browser tab lasts.
     */
    onPage(function () {
        document.querySelectorAll('[data-form-tabs]').forEach(function (tabs) {
            const form = tabs.closest('form');
            const show = function (name) {
                tabs.querySelectorAll('[data-form-tab]').forEach(function (tab) {
                    const on = tab.dataset.formTab === name;
                    tab.classList.toggle('active', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                form.querySelectorAll('[data-form-panel]').forEach(function (panel) { panel.hidden = panel.dataset.formPanel !== name; });
                try { sessionStorage.setItem(tabs.dataset.key, name); } catch (e) { }
            };

            tabs.addEventListener('click', function (event) {
                const tab = event.target.closest('[data-form-tab]');
                if (tab) show(tab.dataset.formTab);
            });

            let saved = null;
            try { saved = sessionStorage.getItem(tabs.dataset.key); } catch (e) { }
            if (saved && tabs.querySelector('[data-form-tab="' + saved + '"]')) show(saved);
        });
    });

    /**
     * Overlay settings page: the sound and image pickers, and the preview
     * (the overlay page in a frame, scaled down). Every change, in the
     * simple form or the advanced text and CSS, is read by StreamOrg as it
     * would be saved and sent to the preview, with what the text had
     * wrong; the preview's shoutout lookups go through this page. The
     * other test reaches the overlay open in OBS.
     */
    onPage(function () {
        const editor = document.querySelector('[data-overlay-editor]');
        if (!editor) return;

        const form = editor.querySelector('[data-overlay-form]');
        const frame = editor.querySelector('[data-preview]');
        const box = editor.querySelector('[data-preview-box]');
        const result = editor.querySelector('[data-test-result]');
        const unsaved = editor.querySelector('[data-unsaved]');
        const warningsBox = editor.querySelector('[data-ini-warnings]');
        const S = readJson(editor, '[data-overlay-strings]', {});
        const media = {};
        readJson(editor, '[data-overlay-media]', []).forEach(function (m) { media[m.id] = m; });

        let current = null;
        let pending = null;
        let asked = 0;

        const fillSegments = function (wrap) {
            const asset = media[wrap.querySelector('[data-sound-asset]').value];
            const select = wrap.querySelector('[data-sound-segment]');
            const chosen = select.value || select.dataset.chosen || '';
            const segments = asset ? asset.segments : [];
            select.replaceChildren(new Option(S.whole, ''));
            segments.forEach(function (s) { select.add(new Option(s.name + ' (' + seconds(s.start) + ')', s.key, false, s.key === chosen)); });
            select.dataset.chosen = '';
            wrap.querySelector('[data-sound-segment-wrap]').hidden = segments.length === 0;
        };

        const showThumb = function (wrap) {
            const asset = media[wrap.querySelector('[data-image-asset]').value];
            const img = wrap.querySelector('[data-image-thumb]');
            img.hidden = !asset;
            if (asset) img.src = asset.url;
        };

        const soundOf = function (field) {
            const asset = media[field.querySelector('[data-sound-asset]').value];
            if (!asset) return null;
            const key = field.querySelector('[data-sound-segment]').value;
            const segment = asset.segments.filter(function (s) { return s.key === key; })[0];
            return {
                url: asset.url,
                start: segment ? segment.start : 0,
                duration: segment ? segment.duration : null,
                volume: Number(field.querySelector('input[type="range"]').value)
            };
        };

        const send = function (message) {
            if (frame.contentWindow) frame.contentWindow.postMessage(message, '*');
        };

        const sendSettings = function () {
            if (current) send({ type: 'streamorg-preview-settings', settings: current.settings, css: current.css, channel: S.channel, channel_id: S.channel_id });
        };

        const showWarnings = function (warnings) {
            if (!warningsBox) return;
            const list = warningsBox.querySelector('ul');
            list.replaceChildren.apply(list, (warnings || []).map(function (w) {
                return el('li', '', (w.line > 0 ? S.line.replace('%d', w.line) + ': ' : '') + w.message);
            }));
            warningsBox.hidden = !(warnings || []).length;
        };

        /** Asks StreamOrg what the form or text gives now (the latest answer wins). */
        const refresh = async function () {
            const body = new URLSearchParams(new FormData(form));
            body.delete('switch_to');
            const ticket = ++asked;
            let answer;
            try { answer = await postJson('/overlays/preview', body); } catch (e) { return; }
            if (ticket !== asked || !answer.ok) return;
            current = answer;
            showWarnings(answer.warnings);
            sendSettings();
        };

        const scale = function () {
            const width = parseInt(box.dataset.width, 10) || 1920;
            frame.style.transform = 'scale(' + (box.clientWidth / width) + ')';
        };

        const initSound = function (field) {
            fillSegments(field);
            field.querySelector('[data-sound-asset]').addEventListener('change', function () { fillSegments(field); });
            field.querySelector('[data-sound-play]').addEventListener('click', function () {
                const sound = soundOf(field);
                if (sound) studioPlay(sound.url, { start: sound.start, duration: sound.duration, volume: sound.volume });
            });
        };

        editor.querySelectorAll('.kind-sound').forEach(initSound);

        editor.querySelectorAll('[data-rules]').forEach(function (rules) {
            const rows = rules.querySelector('[data-rule-rows]');
            const template = rules.querySelector('[data-rule-template]');
            let next = parseInt(rules.dataset.next, 10) || 0;

            rules.querySelector('[data-rule-add]').addEventListener('click', function () {
                const row = template.content.firstElementChild.cloneNode(true);
                const index = String(next++);
                row.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace('__i__', index); });
                rows.append(row);
                row.querySelectorAll('.kind-sound').forEach(initSound);
                row.querySelector('input[type="text"]').focus();
                changed(60);
            });

            rules.addEventListener('click', function (event) {
                const remove = event.target.closest('[data-rule-remove]');
                if (!remove) return;
                remove.closest('[data-rule-row]').remove();
                changed(60);
            });
        });

        editor.querySelectorAll('.kind-image').forEach(function (field) {
            showThumb(field);
            field.querySelector('[data-image-asset]').addEventListener('change', function () { showThumb(field); });
        });

        const changed = function (delay) {
            unsaved.hidden = false;
            clearTimeout(pending);
            pending = setTimeout(refresh, delay);
        };

        form.addEventListener('input', function () { changed(300); });
        form.addEventListener('change', function () { changed(60); });

        frame.addEventListener('load', function () {
            if (current) sendSettings(); else refresh();
        });
        refresh();
        scale();
        if (window.ResizeObserver) new ResizeObserver(scale).observe(box);

        window.addEventListener('message', async function (event) {
            if (event.source !== frame.contentWindow || !event.data || event.data.type !== 'streamorg-preview-lookup') return;
            let channel = null;
            try {
                const response = await fetch(url('/overlays/lookup?id=' + encodeURIComponent(editor.dataset.id) + '&login=' + encodeURIComponent(event.data.login)), { headers: { 'Accept': 'application/json' } });
                channel = (await response.json()).channel || null;
            } catch (e) { }
            send({ type: 'streamorg-preview-lookup-result', id: event.data.id, channel: channel });
        });

        editor.querySelectorAll('[data-test-preview]').forEach(function (button) {
            button.addEventListener('click', function () {
                sendSettings();
                send({ type: 'streamorg-preview-test', payload: { event: button.dataset.testPreview, user: S.sample, label: S.test_label } });
            });
        });

        editor.querySelectorAll('[data-test-live]').forEach(function (button) {
            button.addEventListener('click', async function () {
                button.disabled = true;
                const answer = await postJson('/overlays/test', { id: editor.dataset.id, event: button.dataset.testLive });
                button.disabled = false;
                result.textContent = answer.ok ? S.sent : answer.error;
                result.hidden = false;
            });
        });
    });

    function runPageInits() {
        loadGlobals();
        applyDocumentMeta();
        pageInits.forEach(function (fn) { fn(); });
    }

    if (window.Turbo) {
        if (window.Turbo.config && window.Turbo.config.drive) {
            window.Turbo.config.drive.progressBarDelay = 150;
        }

        document.addEventListener('turbo:load', runPageInits);

        document.addEventListener('turbo:before-render', function () {
            closeModal(true);
            destroyPlanner();
        });

        document.addEventListener('turbo:render', function () {
            loadGlobals();
            modal = document.getElementById('modal');
            hostedNode = null;
            hostedHome = null;
            applyDocumentMeta();
        });

        document.addEventListener('turbo:before-cache', function () {
            closeModal(true);
            destroyPlanner();

            document.querySelectorAll('select.tomselected').forEach(function (select) {
                if (select.tomselect) select.tomselect.destroy();
            });

            undoTableExtras();

            document.querySelectorAll('.key-field').forEach(function (field) {
                if (field.dataset.id) {
                    field.value = '';
                    delete field.dataset.loaded;
                }
                field.classList.add('masked');
            });
        });
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runPageInits);
    } else {
        runPageInits();
    }
})();
