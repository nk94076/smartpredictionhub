(function () {
    'use strict';
    var body = document.body;

    // ---------- Sidebar toggle (desktop: collapse to icons, mobile: drawer) ----------
    var toggle = document.getElementById('sbToggle');
    var overlay = document.getElementById('sbOverlay');
    var isMobile = function () { return window.matchMedia('(max-width: 900px)').matches; };
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isMobile()) {
                body.classList.toggle('sb-open');
            } else {
                var c = body.classList.toggle('sb-collapsed');
                document.cookie = 'sph_sb=' + (c ? 'c' : 'o') + '; path=/; max-age=31536000; samesite=lax';
            }
        });
    }
    if (overlay) { overlay.addEventListener('click', function () { body.classList.remove('sb-open'); }); }

    // Close the user menu when clicking outside
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.user-menu[open]').forEach(function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
    });

    // ---------- Confirm dialogs: <form data-confirm="..."> ----------
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
        });
    });

    // ---------- Password show/hide ----------
    document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
        var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
        if (!input) { return; }
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
        });
    });

    // ---------- Submit button disable (prevent double submit) ----------
    document.querySelectorAll('form[data-once]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type=submit]');
            if (b) { setTimeout(function () { b.disabled = true; }, 0); }
        });
    });

    // ---------- Permission matrix ----------
    // Group "select all"
    document.querySelectorAll('[data-group-toggle]').forEach(function (master) {
        var g = master.getAttribute('data-group-toggle');
        var boxes = document.querySelectorAll('input[data-group="' + g + '"]');
        var sync = function () {
            var all = Array.prototype.every.call(boxes, function (b) { return b.checked; });
            master.checked = all && boxes.length > 0;
        };
        master.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = master.checked; });
        });
        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        master.addEventListener('sync', sync);
        sync();
    });

    // Admin form: super admin / role / custom switching
    var permBox = document.getElementById('permBox');
    var roleBox = document.getElementById('roleBox');
    var roleSel = document.getElementById('roleSelect');
    function refreshAdminForm() {
        var typeEl = document.querySelector('input[name=admin_type]:checked');
        var modeEl = document.querySelector('input[name=perm_mode]:checked');
        if (!typeEl) { return; }
        var isSuper = typeEl.value === 'super';
        if (roleBox) { roleBox.classList.toggle('perm-disabled', isSuper); }
        if (permBox) {
            var custom = modeEl && modeEl.value === 'custom';
            permBox.classList.toggle('perm-disabled', isSuper || !custom);
            if (!custom && roleSel && window.SPH_ROLE_PERMS) {
                var perms = window.SPH_ROLE_PERMS[roleSel.value] || [];
                permBox.querySelectorAll('input[name="perms[]"]').forEach(function (b) { b.checked = perms.indexOf(b.value) !== -1; });
            }
            permBox.querySelectorAll('[data-group-toggle]').forEach(function (m) { m.dispatchEvent(new Event('sync')); });
        }
        if (roleSel) { roleSel.disabled = isSuper; }
    }
    document.querySelectorAll('input[name=admin_type], input[name=perm_mode]').forEach(function (r) { r.addEventListener('change', refreshAdminForm); });
    if (roleSel) { roleSel.addEventListener('change', refreshAdminForm); }
    var rp = document.getElementById('rolePermsData');
    if (rp) { try { window.SPH_ROLE_PERMS = JSON.parse(rp.textContent); } catch (e) {} }
    refreshAdminForm();
})();
