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
            plugins: select.required ? [] : { clear_button: { title: L.picker_clear || '' } },
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

                    return kind === 'games' && item.cover
                        ? '<div class="picker-item"><img class="picker-cover" src="' + escape(item.cover) + '" alt="">' + label + '</div>'
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
                close.addEventListener('click', closeModal);
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
        title.textContent = (arg.timeText ? arg.timeText + ' ' : '') + arg.event.title;
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
        const defaultHour = parseInt(root.dataset.defaultHour || '20', 10);

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

        async function save(id, start) {
            const result = await postJson('/content/schedule', { id: id, start: start });

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
            eventDurationEditable: false,
            defaultTimedEventDuration: '02:00',
            droppable: true,
            dayMaxEvents: 3,
            navLinks: true,
            nowIndicator: true,
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
                if (!await save(info.event.id, wallTime(info.event.start))) info.revert();
            },

            eventReceive: async function (info) {
                const dragged = info.draggedEl;
                let start = info.event.start;

                if (info.event.allDay) {
                    start = new Date(start.getTime() + defaultHour * 3600 * 1000);
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
                item.duration = '02:00';
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
        const payload = { id: form.dataset.id };

        new FormData(form).forEach(function (value, name) { payload[name] = value; });

        submit.disabled = true;
        output.textContent = '…';

        let result = await postJson(form.dataset.endpoint, payload);

        if (!result.ok && result.confirm) {
            submit.disabled = false;
            output.textContent = '';

            if (!await confirmModal(result.title || '', result.error || '')) return;

            payload.confirm = '1';
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

    onPage(function () {
        document.querySelectorAll('table[data-table]').forEach(function (table) {
            const name = table.dataset.table;
            const prefKey = 'columns.' + name;
            const prefs = window.STREAMORG_PREFS || {};
            let hidden = Array.isArray(prefs[prefKey]) ? prefs[prefKey].slice() : [];

            const headers = Array.prototype.slice.call(table.querySelectorAll('thead th[data-col]'));
            if (!headers.length) return;

            applyColumns(table, hidden);

            const wrap = document.createElement('details');
            wrap.className = 'columns-picker';

            const summary = document.createElement('summary');
            summary.textContent = (window.STREAMORG_L || {}).columns || 'Columns';
            wrap.append(summary);

            const list = document.createElement('div');
            list.className = 'columns-list';

            headers.forEach(function (th) {
                const col = th.dataset.col;
                const label = document.createElement('label');
                label.className = 'inline';

                const box = document.createElement('input');
                box.type = 'checkbox';
                box.checked = hidden.indexOf(col) === -1;

                const text = document.createElement('span');
                text.textContent = th.textContent.trim() || col;

                box.addEventListener('change', async function () {
                    hidden = box.checked
                        ? hidden.filter(function (c) { return c !== col; })
                        : hidden.concat([col]);

                    applyColumns(table, hidden);
                    await postJson('/preferences', { key: prefKey, value: JSON.stringify(hidden) });
                });

                label.append(box, text);
                list.append(label);
            });

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
            }

            /** "ft. @A, @B" sits before the hashtags, where it reads naturally. */
            function featureText(names) {
                if (!names.length) return '';

                return 'ft. ' + names.map(function (n) {
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

        if (navToggle && !event.target.closest('#mainnav') && !event.target.closest('#nav-toggle')) {
            document.body.classList.remove('nav-open');
            navToggle.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('click', function (event) {
        const navToggle = event.target.closest('#nav-toggle');
        if (!navToggle) return;

        const open = document.body.classList.toggle('nav-open');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;

        closeNavGroups(null);

        const navToggle = navToggleEl();

        if (navToggle && document.body.classList.contains('nav-open')) {
            document.body.classList.remove('nav-open');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.focus();
        }
    });

    window.matchMedia('(max-width: 900px)').addEventListener('change', function (e) {
        if (!e.matches) {
            const navToggle = navToggleEl();
            document.body.classList.remove('nav-open');
            if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
        }
    });

    function applyDocumentMeta() {
        const theme = document.querySelector('meta[name="streamorg-theme"]');
        const lang  = document.querySelector('meta[name="streamorg-lang"]');

        if (theme) document.documentElement.dataset.theme = theme.content;
        if (lang) document.documentElement.lang = lang.content;
    }

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
