/* Smart Prediction Hub — live markets front end.
 * Starts itself on whichever of these it finds on the page:
 *   #mk-ticker     top bar stats              #mk-dashboard  full markets page
 *   #mk-home       homepage widget            #mk-event      single event page
 * Data comes from /api/markets.php and /api/event.php.
 */
(function () {
    'use strict';
    const API = '/api/markets.php';
    const EVENT_API = '/api/event.php';
    const PLATFORMS = {
        polymarket: { name: 'Polymarket', color: '#2E5CFF', letter: 'P' },
        kalshi: { name: 'Kalshi', color: '#00D991', letter: 'K' },
        limitless: { name: 'Limitless', color: '#DCF20A', letter: 'L' },
        predictit: { name: 'PredictIt', color: '#0B5FA5', letter: 'Pi' },
    };
    const CATEGORIES = {
        politics: 'Politics', elections: 'Elections', sports: 'Sports', crypto: 'Crypto', economics: 'Economics',
        financials: 'Financials', companies: 'Companies', tech: 'Science & Tech', entertainment: 'Entertainment',
        weather: 'Weather', other: 'Other',
    };
    const CAT_ICONS = {
        politics: 'fa-landmark', elections: 'fa-check-to-slot', sports: 'fa-futbol', crypto: 'fa-bitcoin-sign',
        economics: 'fa-chart-line', financials: 'fa-sack-dollar', companies: 'fa-building', tech: 'fa-microchip',
        entertainment: 'fa-film', weather: 'fa-cloud-sun', other: 'fa-circle-question',
    };
    const SERIES_COLORS = ['#4F46E5', '#10B981', '#F59E0B', '#EC4899'];

    /* ---------------------------------------------------------------- helpers */
    const $ = (sel, root = document) => root.querySelector(sel);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => {
        if (v === null || v === undefined || !isFinite(v) || v <= 0) return '<span class="mk-dash">– –</span>';
        const a = Math.abs(v);
        if (a >= 1e9) return '$' + (v / 1e9).toFixed(a >= 1e10 ? 1 : 2).replace(/\.?0+$/, '') + 'B';
        if (a >= 1e6) return '$' + (v / 1e6).toFixed(a >= 1e8 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (a >= 1e3) return '$' + Math.round(v / 1e3) + 'K';
        return '$' + Math.round(v);
    };
    const moneyText = v => money(v).replace(/<[^>]+>/g, '');
    const pct = p => {
        if (p === null || p === undefined) return '–';
        const v = p * 100;
        if (v > 0 && v < 1) return '<1%';
        if (v > 99 && v < 100) return '>99%';
        return Math.round(v) + '%';
    };
    const cents = p => p === null || p === undefined ? '–' : (Math.round(p * 1000) / 10).toString() + '¢';
    const change = (c, min = 1) => {
        if (c === null || c === undefined || Math.abs(c) < min) return '';
        const n = Math.round(Math.abs(c));
        return `<span class="mk-chg ${c > 0 ? 'up' : 'down'}">${c > 0 ? '↑' : '↓'} ${n} pt${n === 1 ? '' : 's'}</span>`;
    };
    const endsIn = iso => {
        if (!iso) return '<span class="mk-dash">– –</span>';
        let s = (new Date(iso) - Date.now()) / 1000;
        if (s <= 0) return 'Closing';
        const y = Math.floor(s / 31536000); s -= y * 31536000;
        const mo = Math.floor(s / 2592000); s -= mo * 2592000;
        const w = Math.floor(s / 604800); s -= w * 604800;
        const d = Math.floor(s / 86400); s -= d * 86400;
        const h = Math.floor(s / 3600); s -= h * 3600;
        const m = Math.floor(s / 60);
        const parts = [[y, 'y'], [mo, 'mo'], [w, 'w'], [d, 'd'], [h, 'h'], [m, 'm']].filter(x => x[0] > 0);
        return parts.slice(0, 3).map(x => x[0] + x[1]).join(' ') || '<1m';
    };
    const ago = iso => {
        if (!iso) return '–';
        const m = Math.round((Date.now() - new Date(iso)) / 60000);
        if (m < 1) return 'just now';
        if (m < 60) return m + ' min ago';
        return Math.round(m / 60) + 'h ago';
    };
    const eventUrl = id => '/event/' + encodeURIComponent(id);
    const platIcon = (k, title) => {
        const p = PLATFORMS[k];
        if (!p) return '';
        return `<span class="mk-plat ${k}" style="background:${p.color}" title="${esc(title || p.name)}">${p.letter}</span>`;
    };
    const platIcons = (plats, max = 4) => {
        const keys = Object.keys(plats || {});
        let h = keys.slice(0, max).map(k => platIcon(k)).join('');
        if (keys.length > max) h += `<span class="mk-matched">+${keys.length - max}</span>`;
        return `<div class="mk-plats">${h}</div>`;
    };
    const catPill = c => `<span class="mk-cat ${esc(c)}">${esc(CATEGORIES[c] || c)}</span>`;

    function spark(values, w = 96, h = 30) {
        if (!values || values.length < 2) return '<span class="mk-dash">– –</span>';
        let min = Math.min(...values), max = Math.max(...values);
        if (max - min < 0.02) { const mid = (max + min) / 2; min = mid - 0.01; max = mid + 0.01; }
        const x = i => (i / (values.length - 1)) * (w - 2) + 1;
        const y = v => h - 2 - ((v - min) / (max - min)) * (h - 4);
        const pts = values.map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1)).join(' ');
        const diff = values[values.length - 1] - values[0];
        const col = Math.abs(diff) < 0.01 ? '#94A3B8' : diff > 0 ? '#10B981' : '#EF4444';
        const area = `M1,${h} L${pts.replace(/ /g, ' L')} L${w - 1},${h} Z`;
        return `<svg class="mk-spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true">
            <path d="${area}" fill="${col}" opacity=".10"/><polyline points="${pts}" fill="none" stroke="${col}" stroke-width="1.6" stroke-linejoin="round"/></svg>`;
    }

    async function getJSON(url) {
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    const store = {
        get(k, d) { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch { return d; } },
        set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* private mode */ } },
    };
    const watch = {
        list() { return store.get('sph_watchlist', []); },
        has(id) { return this.list().includes(id); },
        toggle(id) {
            const l = this.list();
            const i = l.indexOf(id);
            i >= 0 ? l.splice(i, 1) : l.unshift(id);
            store.set('sph_watchlist', l.slice(0, 200));
            return i < 0;
        },
    };

    /* ---------------------------------------------------------------- top bar */
    function paintTicker(stats) {
        const el = document.getElementById('mk-ticker');
        if (!el || !stats) return;
        const share = stats.share ? `<span class="mk-tk-share"><span class="mk-tk-label">Polymarket</span><b class="pm">${stats.share.polymarket}%</b> vs <span class="mk-tk-label" style="margin-left:4px">Kalshi</span><b class="ks">${stats.share.kalshi}%</b></span>` : '';
        el.innerHTML = [
            `<span><span class="mk-tk-label">Total volume</span><b>${moneyText(stats.volume)}</b></span>`,
            `<span><span class="mk-tk-label">24h vol</span><b>${moneyText(stats.volume24)}</b></span>`,
            `<span><span class="mk-tk-label">Liquidity</span><b>${moneyText(stats.liquidity)}</b></span>`,
            `<span><b>${stats.markets.toLocaleString()}</b> markets across <b>${stats.events.toLocaleString()}</b> events</span>`,
            `<span><span class="mk-tk-label">Matched events</span><b>${stats.matched.toLocaleString()}</b></span>`,
            `<span><span class="mk-tk-label">Platforms</span><b>${stats.platforms}</b></span>`,
            share,
        ].join('');
        const up = document.getElementById('mk-updated');
        if (up) up.textContent = ago(stats.updated);
    }

    /* ---------------------------------------------------------------- cards */
    function paintCards(root, cards, stats) {
        if (!root || !cards) return;
        const t = cards.trending || [];
        const trending = `<div class="mk-card"><div class="mk-card-head"><h3><i class="fa-solid fa-arrow-trend-up text-[#4F46E5]"></i> Trending</h3><a href="/markets?tab=trending">View more ›</a></div>
            <ol class="mk-trend-list">${t.map((e, i) => `<li><span class="n">${i + 1}</span><a href="${eventUrl(e.id)}" title="${esc(e.title)}">${esc(e.lead ? e.lead : e.title)}</a><b>${pct(e.consensus)}</b>${change(e.change) || '<span></span>'}</li>`).join('')}</ol>
            <div class="mk-card-foot"><span>By 24h volume</span></div></div>`;
        const b = cards.breaking;
        const breaking = b ? `<div class="mk-card dark"><div class="mk-card-head"><h3><i class="fa-solid fa-bolt"></i> Live · Breaking</h3><a href="/markets?tab=breaking">View more ›</a></div>
            <a class="mk-card-title" href="${eventUrl(b.id)}">${esc(b.title)}${b.lead ? ' — ' + esc(b.lead) : ''}</a>
            <div class="mk-card-foot"><span class="mk-big" style="color:${b.change > 0 ? '#34D399' : '#FCA5A5'}">${b.change > 0 ? '+' : ''}${Math.round(b.change)} pts</span><span>last 24 hours · now ${pct(b.consensus)}</span></div></div>` : '';
        const g = cards.gap;
        const gap = g ? `<div class="mk-card"><div class="mk-card-head"><h3><i class="fa-solid fa-code-compare text-[#7C3AED]"></i> Biggest price gap</h3><a href="/markets?tab=matched">Matched ›</a></div>
            <a class="mk-card-title" href="${eventUrl(g.id)}">${esc(g.title)}${g.outcome ? ' — ' + esc(g.outcome) : ''}</a>
            <div class="mk-card-foot"><span>${platIcon(g.low[0])} ${pct(g.low[1])} &nbsp;vs&nbsp; ${platIcon(g.high[0])} ${pct(g.high[1])}</span><span class="mk-big">${Math.round(g.spread * 100)} pts</span></div></div>` : '';
        let cover = '';
        if (stats) {
            const counts = stats.platform_counts || {};
            const s = stats.share || { polymarket: 0, kalshi: 0 };
            cover = `<div class="mk-card"><div class="mk-card-head"><h3><i class="fa-solid fa-layer-group text-[#10B981]"></i> Coverage</h3><a href="/markets">All events ›</a></div>
                <div class="mk-kv"><span>Polymarket <b>${s.polymarket}%</b></span><span style="text-align:right">Kalshi <b>${s.kalshi}%</b></span></div>
                <div class="mk-share-bar"><span style="width:${s.polymarket}%;background:#2E5CFF"></span><span style="width:${s.kalshi}%;background:#00D991"></span></div>
                <div class="mk-kv">${Object.keys(PLATFORMS).map(k => `<span>${PLATFORMS[k].name} <b>${(counts[k] || 0).toLocaleString()}</b></span>`).join('')}</div>
                <div class="mk-card-foot"><span>24h volume share · events tracked</span></div></div>`;
        }
        root.innerHTML = trending + breaking + gap + cover;
    }

    /* ---------------------------------------------------------------- rows */
    function rowHtml(e, i, opts = {}) {
        const img = e.image ? `<img src="${esc(e.image)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : `<span class="ph"><i class="fa-solid ${CAT_ICONS[e.category] || 'fa-chart-simple'}"></i></span>`;
        const matched = Object.keys(e.platforms).length > 1;
        return `<tr class="mk-row" data-id="${esc(e.id)}">
            <td class="c-star"><button class="mk-star ${watch.has(e.id) ? 'on' : ''}" data-star="${esc(e.id)}" aria-label="Add to watchlist"><i class="fa-${watch.has(e.id) ? 'solid' : 'regular'} fa-star"></i></button></td>
            <td class="c-idx mk-idx">${i}</td>
            ${opts.noExpand ? '' : `<td class="c-exp"><button class="mk-exp" data-exp="${esc(e.id)}" aria-label="Show outcomes"><i class="fa-solid fa-chevron-down"></i></button></td>`}
            <td class="c-ev"><div class="mk-ev">${img}<div><a class="t" href="${eventUrl(e.id)}">${esc(e.title)}</a><div class="s">${esc(e.summary)}</div></div></div></td>
            <td class="c-cat">${catPill(e.category)}</td>
            <td class="c-spark">${spark(e.spark)}</td>
            <td class="c-cons num"><span class="mk-cons">${pct(e.consensus)}</span>${change(e.change)}</td>
            <td class="c-vol num"><b>${money(e.volume)}</b></td>
            <td class="c-liq num">${money(e.liquidity)}</td>
            <td class="c-ends num">${endsIn(e.end)}</td>
            <td class="c-plats">${platIcons(e.platforms)}${matched ? '' : ''}</td>
        </tr>`;
    }

    function detailHtml(e, cols) {
        const plats = Object.keys(e.platforms);
        const outs = e.outcomes.slice(0, 6);
        const head = `<tr><th>Outcome</th><th>Consensus</th>${plats.map(p => `<th>${PLATFORMS[p] ? PLATFORMS[p].name : p}</th>`).join('')}</tr>`;
        const body = outs.map(o => `<tr><td>${esc(e.binary ? 'Yes' : o.name)}</td><td><b>${pct(o.prob)}</b> ${change(o.d1)}</td>${plats.map(p => {
            const q = o.quotes ? o.quotes[p] : undefined;
            const best = o.best && o.best.platform === p;
            return `<td class="${best ? 'best' : ''}">${q === undefined ? '<span class="mk-dash">–</span>' : pct(q)}</td>`;
        }).join('')}</tr>`).join('');
        return `<tr class="mk-detail"><td colspan="${cols}"><div class="mk-detail-in"><table class="mk-mini">${head}${body}</table>
            ${e.markets > outs.length ? `<div class="s" style="font-size:12px;color:#64748B;margin-top:6px">+ ${e.markets - outs.length} more outcomes</div>` : ''}
            <a class="mk-go" href="${eventUrl(e.id)}">Compare all prices <i class="fa-solid fa-arrow-right"></i></a></div></td></tr>`;
    }

    function tableHead(sort, dir, sortable = true) {
        const th = (key, label, cls = '', title = '') => {
            if (!sortable || !key) return `<th class="${cls}" title="${title}">${label}</th>`;
            const on = sort === key;
            const arrow = on ? (dir === 'asc' ? '↑' : '↓') : '';
            return `<th class="${cls} ${on ? 'sorted' : ''}" title="${title}"><button data-sort="${key}">${label} ${arrow}</button></th>`;
        };
        return `<thead><tr><th class="c-star"><i class="fa-regular fa-star"></i></th><th class="mk-idx">#</th><th></th>
            ${th(null, 'Event')}${th(null, 'Category')}${th(null, 'Last 7 days')}
            ${th('consensus', 'Consensus', 'num', 'Volume-weighted probability of the leading outcome across platforms')}
            ${th('volume', 'Total volume', 'num')}${th('liquidity', 'Liquidity', 'num', 'Polymarket liquidity plus Kalshi open interest')}
            ${th('ends', 'Ends in', 'num')}${th('platforms', 'Platforms')}</tr></thead>`;
    }

    function bindRowEvents(tbody, getEvent, cols) {
        tbody.addEventListener('click', ev => {
            const star = ev.target.closest('[data-star]');
            if (star) {
                const on = watch.toggle(star.dataset.star);
                star.classList.toggle('on', on);
                star.innerHTML = `<i class="fa-${on ? 'solid' : 'regular'} fa-star"></i>`;
                return;
            }
            const exp = ev.target.closest('[data-exp]');
            if (exp) {
                const tr = exp.closest('tr');
                const next = tr.nextElementSibling;
                if (next && next.classList.contains('mk-detail')) {
                    next.remove();
                    exp.classList.remove('on');
                } else {
                    const e = getEvent(exp.dataset.exp);
                    if (e) { tr.insertAdjacentHTML('afterend', detailHtml(e, cols)); exp.classList.add('on'); }
                }
            }
        });
    }

    /* ---------------------------------------------------------------- dashboard */
    function initDashboard(root) {
        const url = new URL(location.href);
        const state = {
            tab: url.searchParams.get('tab') || 'all',
            category: (location.pathname.match(/^\/markets\/([a-z]+)/) || [])[1] || url.searchParams.get('category') || '',
            platform: url.searchParams.get('platform') || '',
            ends: url.searchParams.get('ends') || '',
            q: url.searchParams.get('q') || '',
            sort: url.searchParams.get('sort') || '',
            dir: url.searchParams.get('dir') || '',
            watch: url.searchParams.get('watch') === '1',
            page: 1,
        };
        const events = new Map();
        const COLS = 11;
        let loading = false;
        let total = 0;

        const tabs = [['all', 'All'], ['trending', 'Trending'], ['breaking', 'Breaking'], ['new', 'New'], ['ending', 'Ending soon'], ['matched', 'Matched']];
        root.innerHTML = `
            <div class="mk-cards" id="mk-cards">${'<div class="mk-card"><span class="mk-skel" style="width:40%"></span><span class="mk-skel" style="margin-top:14px"></span><span class="mk-skel" style="margin-top:8px;width:80%"></span></div>'.repeat(4)}</div>
            <nav class="mk-tabs" id="mk-tabs" aria-label="Market views"></nav>
            <div class="mk-head-row"><h2 id="mk-title">All events</h2><span class="mk-sub" id="mk-sub"></span></div>
            <div class="mk-toolbar">
                <label class="mk-search"><i class="fa-solid fa-magnifying-glass"></i><input id="mk-q" type="search" placeholder="Search events or outcomes" value="${esc(state.q)}" aria-label="Search events"></label>
                <button class="mk-btn ${state.watch ? 'on' : ''}" id="mk-watch"><i class="fa-regular fa-star"></i> Watchlist</button>
                <div class="mk-pop" id="mk-plat-pop"><button class="mk-btn" id="mk-plat-btn"><i class="fa-solid fa-layer-group"></i> <span>Platform: All</span> <i class="fa-solid fa-chevron-down" style="font-size:10px"></i></button>
                    <div class="mk-pop-menu">${Object.keys(PLATFORMS).map(k => `<label><input type="checkbox" value="${k}" ${state.platform.split(',').includes(k) ? 'checked' : ''}> ${platIcon(k)} ${PLATFORMS[k].name}</label>`).join('')}</div></div>
                <select class="mk-select" id="mk-ends" aria-label="Ends in">
                    <option value="">Ends: Any time</option><option value="24h">Ends in 24 hours</option><option value="7d">Ends in 7 days</option><option value="30d">Ends in 30 days</option><option value="1y">Ends in 1 year</option></select>
                <span class="grow"></span>
                <select class="mk-select" id="mk-sort" aria-label="Sort by">
                    <option value="">Sort: Default</option><option value="volume">Total volume</option><option value="volume24">24h volume</option><option value="liquidity">Liquidity</option>
                    <option value="consensus">Consensus</option><option value="move">Biggest move</option><option value="ends">Ending soonest</option><option value="new">Newest</option><option value="platforms">Most platforms</option></select>
            </div>
            <div class="mk-table-wrap"><div class="mk-scroll"><table class="mk-table" id="mk-table"></table></div>
                <div class="mk-more" id="mk-more" hidden><button class="mk-btn">Load more events</button></div>
                <div class="mk-note">Consensus is the volume-weighted probability across platforms. Prices update every few minutes and are not trading advice. <span id="mk-upd"></span></div></div>`;

        $('#mk-ends', root).value = state.ends;
        $('#mk-sort', root).value = ['volume', 'volume24', 'liquidity', 'consensus', 'move', 'ends', 'new', 'platforms'].includes(state.sort) ? state.sort : '';
        const table = $('#mk-table', root);
        const tbody = document.createElement('tbody');

        function paintTabs(stats) {
            const cats = stats ? stats.categories : {};
            $('#mk-tabs', root).innerHTML = tabs.map(([k, l]) => `<button data-tab="${k}" class="${state.tab === k && !state.category ? 'on' : ''}">${l}</button>`).join('')
                + '<span class="sep"></span>'
                + Object.keys(CATEGORIES).filter(c => !stats || cats[c]).map(c => `<button data-cat="${c}" class="${state.category === c ? 'on' : ''}">${CATEGORIES[c]}${cats[c] ? `<span class="count">${cats[c]}</span>` : ''}</button>`).join('');
        }

        function syncUrl() {
            const u = new URL(location.href);
            u.pathname = state.category ? '/markets/' + state.category : '/markets';
            u.searchParams.delete('category');
            ['tab', 'platform', 'ends', 'q', 'sort', 'dir'].forEach(k => {
                const v = state[k];
                (v && !(k === 'tab' && v === 'all')) ? u.searchParams.set(k, v) : u.searchParams.delete(k);
            });
            state.watch ? u.searchParams.set('watch', '1') : u.searchParams.delete('watch');
            history.replaceState(null, '', u);
            const title = state.watch ? 'Your watchlist' : state.category ? CATEGORIES[state.category] + ' markets' : (tabs.find(t => t[0] === state.tab) || tabs[0])[1] + (state.tab === 'all' ? ' events' : '');
            $('#mk-title', root).textContent = title;
            const n = $('#mk-plat-btn span', root);
            const sel = state.platform ? state.platform.split(',') : [];
            n.textContent = 'Platform: ' + (sel.length ? sel.map(k => PLATFORMS[k] ? PLATFORMS[k].name : k).join(', ') : 'All');
        }

        async function load(reset = true) {
            if (loading) return;
            loading = true;
            if (reset) {
                state.page = 1;
                events.clear();
                tbody.innerHTML = Array.from({ length: 8 }, () => `<tr><td colspan="${COLS}"><span class="mk-skel"></span></td></tr>`).join('');
            }
            syncUrl();
            const p = new URLSearchParams({ page: state.page, per: 50 });
            if (state.page === 1) p.set('include', 'stats,cards');
            ['tab', 'category', 'platform', 'ends', 'q', 'sort', 'dir'].forEach(k => state[k] && p.set(k, state[k]));
            if (state.category) p.delete('tab');
            if (state.watch) {
                const ids = watch.list();
                p.set('ids', ids.join(',') || 'none');
            }
            try {
                const d = await getJSON(API + '?' + p);
                if (d.stats) { paintTicker(d.stats); paintTabs(d.stats); paintCards($('#mk-cards', root), d.cards, d.stats); }
                total = d.total;
                table.innerHTML = tableHead(d.sort, d.dir);
                table.appendChild(tbody);
                const start = (d.page - 1) * d.per;
                const html = d.events.map((e, i) => { events.set(e.id, e); return rowHtml(e, start + i + 1); }).join('');
                if (reset) tbody.innerHTML = html || `<tr><td colspan="${COLS}" class="mk-empty">${state.watch ? 'Your watchlist is empty. Tap ☆ on any event to add it.' : 'No events match these filters.'}</td></tr>`;
                else tbody.insertAdjacentHTML('beforeend', html);
                $('#mk-sub', root).textContent = `Showing ${Math.min(d.page * d.per, total).toLocaleString()} of ${total.toLocaleString()} events · sorted by ${({ volume: 'total volume', volume24: '24h volume', liquidity: 'liquidity', consensus: 'consensus', change: 'change', move: 'biggest move', ends: 'end date', new: 'newest', platforms: 'platforms' })[d.sort] || d.sort}`;
                $('#mk-more', root).hidden = d.page * d.per >= total;
                $('#mk-upd', root).textContent = 'Updated ' + ago(d.updated) + '.';
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="${COLS}" class="mk-empty">Live market data is temporarily unavailable. Please try again in a minute.</td></tr>`;
                table.innerHTML = tableHead(state.sort, state.dir);
                table.appendChild(tbody);
            } finally {
                loading = false;
            }
        }

        bindRowEvents(tbody, id => events.get(id), COLS);
        table.addEventListener('click', ev => {
            const b = ev.target.closest('[data-sort]');
            if (!b) return;
            const k = b.dataset.sort;
            if (state.sort === k) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            else { state.sort = k; state.dir = k === 'ends' ? 'asc' : 'desc'; }
            $('#mk-sort', root).value = k;
            load();
        });
        $('#mk-tabs', root).addEventListener('click', ev => {
            const b = ev.target.closest('button');
            if (!b) return;
            if (b.dataset.tab) { state.tab = b.dataset.tab; state.category = ''; }
            if (b.dataset.cat) { state.category = state.category === b.dataset.cat ? '' : b.dataset.cat; state.tab = 'all'; }
            state.sort = ''; state.dir = ''; $('#mk-sort', root).value = '';
            root.querySelectorAll('#mk-tabs button').forEach(x => x.classList.toggle('on', x === b && (!!b.dataset.tab || state.category === b.dataset.cat)));
            load();
        });
        let qTimer;
        $('#mk-q', root).addEventListener('input', ev => { clearTimeout(qTimer); qTimer = setTimeout(() => { state.q = ev.target.value.trim(); load(); }, 300); });
        $('#mk-ends', root).addEventListener('change', ev => { state.ends = ev.target.value; load(); });
        $('#mk-sort', root).addEventListener('change', ev => { state.sort = ev.target.value; state.dir = ''; load(); });
        $('#mk-watch', root).addEventListener('click', ev => { state.watch = !state.watch; ev.currentTarget.classList.toggle('on', state.watch); load(); });
        const pop = $('#mk-plat-pop', root);
        $('#mk-plat-btn', root).addEventListener('click', () => pop.classList.toggle('open'));
        document.addEventListener('click', ev => { if (!pop.contains(ev.target)) pop.classList.remove('open'); });
        pop.addEventListener('change', () => {
            state.platform = [...pop.querySelectorAll('input:checked')].map(i => i.value).join(',');
            load();
        });
        $('#mk-more button', root).addEventListener('click', () => { state.page++; load(false); });

        paintTabs(null);
        load();
        setInterval(() => { if (!document.hidden && state.page === 1) load(); }, 5 * 60 * 1000);
    }

    /* ---------------------------------------------------------------- homepage widget */
    async function initHome(root) {
        const COLS = 11;
        const events = new Map();
        root.innerHTML = `<div class="mk-cards" id="mk-cards">${'<div class="mk-card"><span class="mk-skel" style="width:40%"></span><span class="mk-skel" style="margin-top:14px"></span></div>'.repeat(4)}</div>
            <div class="mk-table-wrap"><div class="mk-scroll"><table class="mk-table"><tbody><tr><td><span class="mk-skel"></span></td></tr></tbody></table></div>
            <div class="mk-more"><a class="mk-btn" href="/markets" style="background:#4F46E5;color:#fff;border-color:#4F46E5">View all events <i class="fa-solid fa-arrow-right"></i></a></div>
            <div class="mk-note">Consensus is the volume-weighted probability across Polymarket, Kalshi, Limitless and PredictIt. <span id="mk-upd"></span></div></div>`;
        const table = $('table', root);
        try {
            const d = await getJSON(API + '?per=10&include=stats,cards');
            paintTicker(d.stats);
            paintCards($('#mk-cards', root), d.cards, d.stats);
            table.innerHTML = tableHead('volume', 'desc', false) + '<tbody>' + d.events.map((e, i) => { events.set(e.id, e); return rowHtml(e, i + 1); }).join('') + '</tbody>';
            bindRowEvents($('tbody', table), id => events.get(id), COLS);
            $('#mk-upd', root).textContent = 'Updated ' + ago(d.updated) + '.';
        } catch (err) {
            $('#mk-cards', root).remove();
            table.innerHTML = '<tbody><tr><td class="mk-empty">Live market data is temporarily unavailable.</td></tr></tbody>';
        }
    }

    /* ---------------------------------------------------------------- event page */
    function lineChart(series, w = 760, h = 280) {
        const all = series.flatMap(s => s.points);
        if (all.length < 2) return '<div class="mk-empty">Price history is not available for this event yet.</div>';
        const t0 = Math.min(...all.map(p => p[0])), t1 = Math.max(...all.map(p => p[0]));
        let max = Math.max(...all.map(p => p[1])), min = Math.min(...all.map(p => p[1]));
        max = Math.min(1, Math.ceil((max + 0.02) * 10) / 10); min = Math.max(0, Math.floor((min - 0.02) * 10) / 10);
        if (max - min < 0.1) max = Math.min(1, min + 0.1);
        const L = 44, R = 10, T = 12, B = 26;
        const x = t => L + (t - t0) / Math.max(1, t1 - t0) * (w - L - R);
        const y = v => T + (1 - (v - min) / (max - min)) * (h - T - B);
        let grid = '';
        const steps = 4;
        for (let i = 0; i <= steps; i++) {
            const v = min + (max - min) * i / steps;
            grid += `<line x1="${L}" x2="${w - R}" y1="${y(v)}" y2="${y(v)}" stroke="#EEF0F6"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="#94A3B8">${Math.round(v * 100)}%</text>`;
        }
        const fmt = t => new Date(t * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        for (let i = 0; i <= 4; i++) {
            const t = t0 + (t1 - t0) * i / 4;
            grid += `<text x="${x(t)}" y="${h - 6}" text-anchor="${i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}" font-size="11" fill="#94A3B8">${fmt(t)}</text>`;
        }
        const lines = series.map((s, i) => `<polyline points="${s.points.map(p => x(p[0]).toFixed(1) + ',' + y(p[1]).toFixed(1)).join(' ')}" fill="none" stroke="${SERIES_COLORS[i]}" stroke-width="2" stroke-linejoin="round"/>`).join('');
        return `<svg class="mk-chart" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="30-day price history">${grid}${lines}</svg>`;
    }

    async function initEvent(root) {
        const id = root.dataset.id;
        try {
            const e = await getJSON(EVENT_API + '?id=' + encodeURIComponent(id));
            const plats = Object.keys(e.platforms);
            const lead = e.outcomes[0];
            $('#mk-ev-cons').innerHTML = `<div class="lbl">Consensus${e.binary ? '' : ' leader'}</div><div class="val">${pct(e.consensus)}</div>
                <div class="who">${e.binary ? 'chance of Yes' : esc(lead.name)} ${change(e.change)}</div>`;
            $('#mk-ev-meta').innerHTML = `<span>${catPill(e.category)}</span><span>Total volume <b>${moneyText(e.volume)}</b></span><span>24h <b>${moneyText(e.volume24)}</b></span>
                <span>Liquidity <b>${moneyText(e.liquidity)}</b></span><span>Ends in <b>${endsIn(e.end)}</b></span><span>${plats.length} platform${plats.length > 1 ? 's' : ''} ${platIcons(e.platforms)}</span>`;

            const hist = e.history || [];
            $('#mk-ev-chart').innerHTML = lineChart(hist) + `<div class="mk-legend">${hist.map((s, i) => `<span><i style="background:${SERIES_COLORS[i]}"></i>${esc(s.name)}</span>`).join('')}</div>`;

            const rows = e.outcomes.slice(0, 100).map((o, i) => {
                const cells = plats.map(p => {
                    const q = o.quotes[p];
                    if (!q) return '<td><span class="mk-dash">–</span></td>';
                    const best = o.best && o.best.platform === p && plats.length > 1;
                    return `<td class="${best ? 'best' : ''}" title="${q.ask ? 'Buy Yes at ' + cents(q.ask) : ''}">${pct(q.p)}</td>`;
                }).join('');
                return `<tr ${i >= 15 ? 'class="mk-extra" hidden' : ''}><td>${esc(e.binary ? 'Yes' : o.name)}<div class="mk-bar"><span style="width:${Math.max(1, o.prob * 100)}%"></span></div></td>
                    <td><b>${pct(o.prob)}</b>${change(o.d1)}</td>${cells}<td>${o.best ? cents(o.best.price) + ' <span style="color:#64748B;font-size:12px">' + (PLATFORMS[o.best.platform] || {}).name + '</span>' : '–'}</td></tr>`;
            }).join('');
            $('#mk-ev-cmp').innerHTML = `<div class="mk-scroll"><table class="mk-cmp"><thead><tr><th>Outcome</th><th>Consensus</th>${plats.map(p => `<th>${PLATFORMS[p].name}</th>`).join('')}<th>Best price</th></tr></thead><tbody>${rows}</tbody></table></div>
                ${e.outcomes.length > 15 ? `<div class="mk-more"><button class="mk-btn" id="mk-ev-all">Show all ${Math.min(100, e.outcomes.length)} outcomes</button></div>` : ''}`;
            const all = $('#mk-ev-all');
            if (all) all.addEventListener('click', () => { document.querySelectorAll('.mk-extra').forEach(r => r.hidden = false); all.parentNode.remove(); });

            $('#mk-ev-venues').innerHTML = plats.map(p => {
                const x = e.platforms[p];
                return `<div class="mk-venue">${platIcon(p)}<div><div class="nm">${PLATFORMS[p].name}</div><div class="sm">${moneyText(x.volume)} volume · ${x.markets} market${x.markets > 1 ? 's' : ''}</div></div>
                    <a class="go" href="${esc(x.url)}" target="_blank" rel="noopener nofollow sponsored">Trade <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px"></i></a></div>`;
            }).join('');
            $('#mk-ev-upd').textContent = 'Prices updated ' + ago(e.updated) + '.';

            const rel = await getJSON(API + '?per=7&category=' + encodeURIComponent(e.category) + '&include=stats');
            paintTicker(rel.stats);
            $('#mk-ev-related').innerHTML = rel.events.filter(x => x.id !== e.id).slice(0, 6)
                .map(x => `<a href="${eventUrl(x.id)}">${esc(x.title)}<span>${pct(x.consensus)}</span></a>`).join('') || '<div class="pad">No related events.</div>';
        } catch (err) {
            root.querySelector('.mk-grid').innerHTML = '<div class="mk-empty">This event could not be loaded. It may have closed. <a href="/markets" style="color:#4F46E5;font-weight:600">Browse all events</a></div>';
        }
    }

    /* ---------------------------------------------------------------- boot */
    function boot() {
        const dash = document.getElementById('mk-dashboard');
        const home = document.getElementById('mk-home');
        const ev = document.getElementById('mk-event');
        if (dash) initDashboard(dash);
        if (home) initHome(home);
        if (ev) initEvent(ev);
        if (!dash && !home && !ev && document.getElementById('mk-ticker')) {
            getJSON(API + '?per=1&include=stats').then(d => paintTicker(d.stats)).catch(() => {});
        }
    }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
})();
