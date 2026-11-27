/* CMS admin: rich text editor, media library picker, image fields, slug + counters. No dependencies. */
(function () {
    'use strict';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    /* ---------------- Media library picker ---------------- */
    var modal = $('#mediaModal');
    var pickCb = null, page = 1;
    var csrf = modal ? modal.getAttribute('data-csrf') : '';

    function mmLoad(reset) {
        if (reset) { page = 1; $('[data-mm-grid]', modal).innerHTML = ''; }
        fetch('media-api.php?action=list&page=' + page, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert(d.error); return; }
                var g = $('[data-mm-grid]', modal);
                d.items.forEach(function (m) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'mm-item';
                    b.innerHTML = '<img src="' + esc(m.url) + '" alt="" loading="lazy"><span>' + esc(m.name) + '</span>';
                    b.addEventListener('click', function () { mmPick(m.url, m.alt || ''); });
                    g.appendChild(b);
                });
                if (!g.children.length) g.innerHTML = '<p class="muted">No images yet — upload one.</p>';
                $('[data-mm-more]', modal).hidden = !d.more;
            });
    }
    function mmOpen(cb) {
        if (!modal || modal.getAttribute('data-disabled')) {
            var u = prompt('Image URL (https://… or /uploads/…)');
            if (u) cb(u, '');
            return;
        }
        pickCb = cb;
        modal.hidden = false;
        mmLoad(true);
    }
    function mmPick(url, alt) {
        modal.hidden = true;
        if (pickCb) pickCb(url, alt);
        pickCb = null;
    }
    function upload(files, done) {
        var list = Array.prototype.slice.call(files);
        var next = function () {
            var f = list.shift();
            if (!f) { done(); return; }
            var fd = new FormData();
            fd.append('file', f);
            fd.append('csrf', csrf);
            fetch('media-api.php?action=upload', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.error) alert(f.name + ': ' + d.error); next(); })
                .catch(function () { alert(f.name + ': upload failed'); next(); });
        };
        next();
    }
    window.sphUpload = upload;
    window.sphPickImage = mmOpen;
    if (modal && !modal.getAttribute('data-disabled')) {
        $('[data-mm-close]', modal).addEventListener('click', function () { modal.hidden = true; });
        modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });
        $('[data-mm-more]', modal).addEventListener('click', function () { page++; mmLoad(false); });
        $('[data-mm-upload]', modal).addEventListener('change', function () {
            var inp = this;
            upload(inp.files, function () { inp.value = ''; mmLoad(true); });
        });
        $('[data-mm-use-url]', modal).addEventListener('click', function () {
            var u = $('[data-mm-url]', modal).value.trim();
            if (u) mmPick(u, '');
        });
    }

    /* ---------------- Image fields ---------------- */
    $$('[data-img-field]').forEach(function (f) {
        var inp = $('[data-img-input]', f), prev = $('[data-img-prev]', f);
        var show = function () { prev.innerHTML = inp.value ? '<img src="' + esc(inp.value) + '" alt="">' : '<span class="muted small">No image</span>'; };
        $('[data-img-pick]', f).addEventListener('click', function () { mmOpen(function (u) { inp.value = u; show(); }); });
        $('[data-img-clear]', f).addEventListener('click', function () { inp.value = ''; show(); });
        inp.addEventListener('change', show);
    });

    /* ---------------- Rich text editor ---------------- */
    $$('[data-rte]').forEach(function (box) {
        var area = $('[data-rte-area]', box), code = $('[data-rte-code]', box), srcMode = false;
        area.innerHTML = code.value;
        var sync = function () { if (!srcMode) code.value = area.innerHTML; };
        var form = box.closest('form');
        if (form) form.addEventListener('submit', sync);
        area.addEventListener('input', sync);
        document.execCommand('defaultParagraphSeparator', false, 'p');

        var savedRange = null;
        var save = function () { var s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) savedRange = s.getRangeAt(0); };
        var restore = function () { area.focus(); if (savedRange) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(savedRange); } };
        area.addEventListener('keyup', save);
        area.addEventListener('mouseup', save);
        var insert = function (html) { restore(); document.execCommand('insertHTML', false, html); sync(); };

        $$('[data-cmd]', box).forEach(function (el) {
            var cmd = el.getAttribute('data-cmd');
            if (el.tagName === 'SELECT') {
                el.addEventListener('change', function () { restore(); document.execCommand('formatBlock', false, '<' + el.value + '>'); el.selectedIndex = 0; sync(); });
                return;
            }
            el.addEventListener('mousedown', function (e) { e.preventDefault(); });
            el.addEventListener('click', function () {
                if (cmd === 'source') {
                    srcMode = !srcMode;
                    if (srcMode) { code.value = area.innerHTML; code.hidden = false; area.hidden = true; }
                    else { area.innerHTML = code.value; code.hidden = true; area.hidden = false; }
                    el.classList.toggle('on', srcMode);
                    return;
                }
                if (srcMode) return;
                if (cmd === 'link') {
                    save();
                    var u = prompt('Link URL', 'https://');
                    if (!u) return;
                    restore();
                    document.execCommand('createLink', false, u);
                    if (/^https?:/.test(u) && u.indexOf(location.host) === -1) {
                        var a = window.getSelection().anchorNode;
                        a = a && (a.nodeType === 1 ? a : a.parentElement);
                        a = a && a.closest('a');
                        if (a) { a.target = '_blank'; a.rel = 'noopener'; }
                    }
                    sync();
                } else if (cmd === 'image') {
                    save();
                    mmOpen(function (url, alt) {
                        var a = prompt('Image description (alt text, for SEO)', alt || '') || '';
                        insert('<figure><img src="' + esc(url) + '" alt="' + esc(a) + '" loading="lazy"></figure><p><br></p>');
                    });
                } else if (cmd === 'embed') {
                    save();
                    var v = prompt('YouTube or Vimeo link');
                    if (!v) return;
                    var m = v.match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([\w-]{11})/), src = '';
                    if (m) src = 'https://www.youtube-nocookie.com/embed/' + m[1];
                    else if ((m = v.match(/vimeo\.com\/(\d+)/))) src = 'https://player.vimeo.com/video/' + m[1];
                    if (!src) { alert('Only YouTube and Vimeo links are supported.'); return; }
                    insert('<div class="video-embed"><iframe src="' + src + '" width="560" height="315" frameborder="0" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div><p><br></p>');
                } else if (cmd === 'table') {
                    save();
                    var r = parseInt(prompt('Rows', '3'), 10) || 3, c = parseInt(prompt('Columns', '3'), 10) || 3, h = '<table><thead><tr>';
                    for (var j = 0; j < c; j++) h += '<th>Heading</th>';
                    h += '</tr></thead><tbody>';
                    for (var i = 0; i < r - 1; i++) { h += '<tr>'; for (j = 0; j < c; j++) h += '<td>&nbsp;</td>'; h += '</tr>'; }
                    insert(h + '</tbody></table><p><br></p>');
                } else {
                    restore();
                    document.execCommand(cmd, false, null);
                    sync();
                }
            });
        });
        // paste: keep structure, drop foreign styling
        area.addEventListener('paste', function (e) {
            var html = e.clipboardData && e.clipboardData.getData('text/html');
            if (!html) return;
            e.preventDefault();
            var d = document.createElement('div');
            d.innerHTML = html;
            $$('style,script,meta,link', d).forEach(function (n) { n.remove(); });
            $$('*', d).forEach(function (n) {
                ['style', 'class', 'id', 'dir'].forEach(function (a) { n.removeAttribute(a); });
                if (n.tagName === 'SPAN' || n.tagName === 'FONT') { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); }
            });
            document.execCommand('insertHTML', false, d.innerHTML);
            sync();
        });
    });

    /* ---------------- Slug from title, character counters ---------------- */
    var title = $('[data-slug-src]'), slug = $('[data-slug]');
    if (title && slug) {
        var touched = slug.value !== '';
        slug.addEventListener('input', function () { touched = true; });
        title.addEventListener('input', function () {
            if (touched) return;
            slug.value = title.value.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
                .replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
        });
    }
    $$('[data-count]').forEach(function (inp) {
        var max = +inp.getAttribute('data-count');
        var c = document.createElement('small');
        c.className = 'counter';
        inp.parentNode.appendChild(c);
        var upd = function () { var n = inp.value.length; c.textContent = n + ' / ' + max; c.classList.toggle('over', n > max); };
        inp.addEventListener('input', upd);
        upd();
    });

    /* ---------------- Media page: drag & drop + copy URL ---------------- */
    var drop = $('[data-dropzone]');
    if (drop) {
        var inp = $('input[type=file]', drop);
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
        var go = function (files) { drop.classList.add('busy'); upload(files, function () { location.reload(); }); };
        drop.addEventListener('drop', function (e) { go(e.dataTransfer.files); });
        inp.addEventListener('change', function () { go(inp.files); });
    }
    $$('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = new URL(b.getAttribute('data-copy'), location.origin).href;
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
                var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1200);
            }, function () { prompt('Copy this URL', t); });
        });
    });
})();
