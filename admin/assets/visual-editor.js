/* Visual page editor. Runs in the admin page and edits the same-origin iframe (page-render.php).
 * Every editable tag carries data-sph-i="N"; changes are sent as {i, html|attrs|op} and applied to the source on the server. */
(function () {
    'use strict';
    var bar = document.querySelector('[data-ve]');
    if (!bar) return;
    document.body.classList.add('ve-body');
    var frame = document.querySelector('[data-ve-frame]');
    var P = bar.getAttribute('data-p'), CSRF = bar.getAttribute('data-csrf');
    var saveBtn = bar.querySelector('[data-ve-save]'), countEl = bar.querySelector('[data-ve-count]'), status = bar.querySelector('[data-ve-status]');
    var changes = {};          // i -> {i, html?, attrs?}
    var mode = 'edit', doc = null, win = null, cur = null, snapshot = '', tb = null, scrollKeep = 0;

    var BLOCK = /^(P|H[1-6]|LI|DIV|BLOCKQUOTE|TD|TH|DD|DT|FIGCAPTION|SECTION|LABEL|SUMMARY)$/;
    var count = function () { return Object.keys(changes).length; };
    var refresh = function () {
        var n = count();
        countEl.textContent = n ? '(' + n + ')' : '';
        saveBtn.disabled = !n;
    };
    var say = function (t, err) { status.textContent = t; status.style.color = err ? '#DC2626' : ''; };
    var idx = function (el) { return el && el.getAttribute ? el.getAttribute('data-sph-i') : null; };

    function clean(el) {
        var c = el.cloneNode(true);
        [c].concat([].slice.call(c.querySelectorAll('*'))).forEach(function (n) {
            n.removeAttribute('contenteditable');
            n.removeAttribute('data-sph-hov');
            n.removeAttribute('data-sph-changed');
            if (n !== c) n.removeAttribute('data-sph-i');
        });
        return c.innerHTML;
    }
    function hasText(el) {
        for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3 && n.nodeValue.trim()) return true;
        return false;
    }
    /** The element to edit for a click/hover target: nearest marked element that holds text itself. */
    function pick(t) {
        if (!t || !t.closest) return null;
        if (t.closest('[data-sph-ui]')) return null;
        var img = t.closest('img[data-sph-i]');
        if (img) return img;
        var first = t.closest('[data-sph-i]'), el = first;
        while (el && !hasText(el)) {
            el = el.parentElement && el.parentElement.closest('[data-sph-i]');
            if (el && el.tagName === 'SECTION') { el = null; break; }
        }
        if (el) return el;
        return first && first.tagName === 'A' ? first : (first && first.closest('a[data-sph-i]')) || null;
    }
    function setChange(i, patch) {
        var c = changes[i] || { i: +i };
        Object.keys(patch).forEach(function (k) {
            if (k === 'attrs') { c.attrs = c.attrs || {}; Object.keys(patch.attrs).forEach(function (a) { c.attrs[a] = patch.attrs[a]; }); }
            else c[k] = patch[k];
        });
        changes[i] = c;
        var el = doc.querySelector('[data-sph-i="' + i + '"]');
        if (el) el.setAttribute('data-sph-changed', '1');
        refresh();
    }

    /* ---------- toolbar inside the frame ---------- */
    function toolbar(el) {
        if (!tb) {
            tb = doc.createElement('div');
            tb.setAttribute('data-sph-ui', '1');
            tb.style.cssText = 'position:absolute;z-index:2147483647;display:flex;gap:2px;background:#0F0B2E;border-radius:10px;padding:4px;box-shadow:0 6px 24px rgba(0,0,0,.3);font:600 12px Inter,Arial,sans-serif';
            var btns = [['B', 'bold', 'Bold'], ['I', 'italic', 'Italic'], ['🔗', 'link', 'Link address'], ['⧉', 'dup', 'Duplicate this block'], ['🗑', 'del', 'Delete this block'], ['✓ Done', 'done', 'Finish editing']];
            btns.forEach(function (b) {
                var x = doc.createElement('button');
                x.type = 'button';
                x.textContent = b[0];
                x.title = b[2];
                x.setAttribute('data-sph-ui', '1');
                x.style.cssText = 'all:unset;cursor:pointer;color:#fff;padding:6px 9px;border-radius:7px;' + (b[1] === 'bold' ? 'font-weight:800' : b[1] === 'italic' ? 'font-style:italic' : '');
                x.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); });
                x.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); tool(b[1]); });
                tb.appendChild(x);
            });
            doc.body.appendChild(tb);
        }
        var r = el.getBoundingClientRect();
        tb.style.display = 'flex';
        tb.style.top = Math.max(0, r.top + win.scrollY - 44) + 'px';
        tb.style.left = Math.max(4, Math.min(r.left + win.scrollX, win.innerWidth + win.scrollX - 330)) + 'px';
    }
    function hideToolbar() { if (tb) tb.style.display = 'none'; }

    function tool(cmd) {
        if (!cur) return;
        if (cmd === 'bold' || cmd === 'italic') { doc.execCommand(cmd, false, null); return; }
        if (cmd === 'done') { finish(); return; }
        if (cmd === 'link') {
            var a = cur.tagName === 'A' ? cur : cur.closest('a[data-sph-i]') || cur.querySelector('a[data-sph-i]');
            if (!a) { alert('This text is not a link. To add a link, use the HTML button (top right).'); return; }
            editLink(a);
            return;
        }
        var target = cur;
        if (cmd === 'dup' || cmd === 'del') {
            var label = cmd === 'dup' ? 'Duplicate' : 'Delete';
            // a card/list item is usually what people mean: offer the nearest repeated block
            var blk = target.closest('li[data-sph-i]') || target;
            if (!confirm(label + ' this ' + (blk.tagName === 'LI' ? 'list item' : '<' + blk.tagName.toLowerCase() + '> block') + '?\nUnsaved text changes are saved first.')) return;
            finish();
            save({ i: +idx(blk), op: cmd === 'dup' ? 'duplicate' : 'delete' });
        }
    }
    function editLink(a) {
        var href = prompt('Link address (e.g. /markets, /blog/my-post or https://…)\nLeave empty to keep it.', a.getAttribute('href') || '');
        if (href === null || href === '') return;
        if (/^\s*javascript:/i.test(href)) { alert('That link type is not allowed.'); return; }
        var ext = /^https?:\/\//i.test(href) && href.indexOf(location.host) === -1;
        var attrs = { href: href.trim() };
        if (ext) { attrs.target = '_blank'; attrs.rel = 'noopener'; }
        a.setAttribute('href', attrs.href);
        setChange(idx(a), { attrs: attrs });
        say('Link changed — press Save.');
    }

    /* ---------- edit lifecycle ---------- */
    function start(el) {
        if (cur === el) return;
        finish();
        cur = el;
        snapshot = clean(el);
        el.setAttribute('contenteditable', 'true');
        el.focus();
        toolbar(el);
    }
    function finish() {
        if (!cur) return;
        var el = cur;
        cur = null;
        el.removeAttribute('contenteditable');
        hideToolbar();
        var now = clean(el);
        if (now !== snapshot) setChange(idx(el), { html: now });
    }
    function cancel() {
        if (!cur) return;
        cur.innerHTML = snapshot;
        cur.removeAttribute('contenteditable');
        cur = null;
        hideToolbar();
    }

    function replaceImage(img) {
        window.sphPickImage(function (url, alt) {
            var a = prompt('Image description (alt text)', alt || img.getAttribute('alt') || '');
            var attrs = { src: url, srcset: null };
            if (a !== null) attrs.alt = a;
            img.setAttribute('src', url);
            img.removeAttribute('srcset');
            if (a !== null) img.setAttribute('alt', a);
            setChange(idx(img), { attrs: attrs });
            say('Image replaced — press Save.');
        });
    }

    /* ---------- frame wiring ---------- */
    function onFrameLoad() {
        doc = frame.contentDocument;
        win = frame.contentWindow;
        cur = null; tb = null;
        if (!doc || !doc.body) return;
        var st = doc.createElement('style');
        st.setAttribute('data-sph-ui', '1');
        st.textContent = 'html.sph-edit [data-sph-hov]{outline:2px dashed #8B5CF6!important;outline-offset:2px;cursor:text}'
            + 'html.sph-edit img[data-sph-hov]{cursor:pointer}'
            + '[contenteditable=true]{outline:2px solid #6D4AFF!important;outline-offset:3px;background:rgba(109,74,255,.07)!important;cursor:text}'
            + 'html.sph-edit [data-sph-changed]{box-shadow:0 0 0 2px rgba(245,165,36,.75)!important}';
        doc.head.appendChild(st);
        doc.documentElement.classList.toggle('sph-edit', mode === 'edit');
        if (scrollKeep) { win.scrollTo(0, scrollKeep); scrollKeep = 0; }
        // re-apply unsaved changes is not needed: we always save before reloading

        var hov = null;
        doc.addEventListener('mouseover', function (e) {
            if (mode !== 'edit') return;
            var el = pick(e.target);
            if (hov && hov !== el) hov.removeAttribute('data-sph-hov');
            hov = el;
            if (el && el !== cur) el.setAttribute('data-sph-hov', '1');
        }, true);
        doc.addEventListener('click', function (e) {
            if (mode !== 'edit') return;
            if (e.target.closest && e.target.closest('[data-sph-ui]')) return;
            if (cur && cur.contains(e.target)) { e.preventDefault(); e.stopPropagation(); return; }
            e.preventDefault();
            e.stopPropagation();
            var el = pick(e.target);
            if (!el) {
                finish();
                if (e.target.closest && e.target.closest('#siteHeader, footer.foot, #mobileMenu, .top-market-bar') && P !== '@chrome') {
                    say('The header & footer are shared by every page — edit them under “Header & Footer”.', true);
                }
                return;
            }
            if (hov) hov.removeAttribute('data-sph-hov');
            if (el.tagName === 'IMG') { finish(); replaceImage(el); return; }
            start(el);
            if (el.tagName === 'A' && !hasText(el)) say('Icon link — use 🔗 in the toolbar to change where it goes.');
        }, true);
        ['mousedown', 'mouseup', 'submit', 'touchstart'].forEach(function (ev) {
            doc.addEventListener(ev, function (e) {
                if (mode !== 'edit') return;
                if (e.target.closest && e.target.closest('[data-sph-ui]')) return;
                if (cur && cur.contains(e.target)) return;
                if (ev === 'submit') e.preventDefault();
                e.stopPropagation();
            }, true);
        });
        doc.addEventListener('keydown', function (e) {
            if (!cur) return;
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return; }
            if (e.key === 'Enter' && !BLOCK.test(cur.tagName)) { e.preventDefault(); if (e.shiftKey) doc.execCommand('insertLineBreak'); return; }
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doc.execCommand('insertLineBreak'); }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); finish(); save(); }
        }, true);
        doc.addEventListener('paste', function (e) {
            if (!cur) return;
            e.preventDefault();
            var t = (e.clipboardData || win.clipboardData).getData('text/plain');
            doc.execCommand('insertText', false, t);
        }, true);
        win.addEventListener('scroll', function () { if (cur) toolbar(cur); });
        say('Click any text to edit · click an image to replace it · click a link, then 🔗 to change its address');
    }
    frame.addEventListener('load', onFrameLoad);

    /* ---------- save ---------- */
    function save(extraOp) {
        finish();
        var list = Object.keys(changes).map(function (k) { return changes[k]; });
        // drop edits nested inside another edited element (the outer html already contains them)
        var htmlEls = list.filter(function (c) { return c.html !== undefined; }).map(function (c) { return doc.querySelector('[data-sph-i="' + c.i + '"]'); });
        list = list.filter(function (c) {
            var el = doc.querySelector('[data-sph-i="' + c.i + '"]');
            return !htmlEls.some(function (p) { return p && el && p !== el && p.contains(el); });
        });
        if (extraOp) list.push(extraOp);
        if (!list.length) return;
        saveBtn.disabled = true;
        say('Saving…');
        var fd = new FormData();
        fd.append('p', P);
        fd.append('csrf', CSRF);
        fd.append('changes', JSON.stringify(list));
        fetch('page-save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { say(d.error, true); refresh(); return; }
                changes = {};
                refresh();
                say('Saved — live on the website now.' + (d.skipped ? ' (' + d.skipped + ' overlapping change(s) skipped)' : ''));
                scrollKeep = win.scrollY;
                frame.contentWindow.location.reload();
            })
            .catch(function () { say('Save failed — check your connection and try again.', true); refresh(); });
    }
    saveBtn.addEventListener('click', function () { save(); });
    window.addEventListener('beforeunload', function (e) { if (count() || cur) { e.preventDefault(); e.returnValue = ''; } });

    /* ---------- modes + device width ---------- */
    [].forEach.call(bar.querySelectorAll('[data-ve-mode]'), function (b) {
        b.addEventListener('click', function () {
            finish();
            mode = b.getAttribute('data-ve-mode');
            [].forEach.call(bar.querySelectorAll('[data-ve-mode]'), function (x) { x.classList.toggle('on', x === b); });
            if (doc) doc.documentElement.classList.toggle('sph-edit', mode === 'edit');
            say(mode === 'edit' ? 'Edit mode — click text to change it.' : 'Browse mode — open menus, tabs and dropdowns, then switch back to edit mode.');
        });
    });
    [].forEach.call(bar.querySelectorAll('[data-ve-dev]'), function (b) {
        b.addEventListener('click', function () {
            frame.style.width = b.getAttribute('data-ve-dev');
            [].forEach.call(bar.querySelectorAll('[data-ve-dev]'), function (x) { x.classList.toggle('on', x === b); });
        });
    });
})();
