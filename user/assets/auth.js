/* Smart Prediction Hub — auth pages: password toggle + client-side validation (server validates again) */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    document.querySelectorAll('.password-toggle').forEach(function (b) {
        b.addEventListener('click', function () {
            var input = document.getElementById(b.dataset.target);
            var icon = b.querySelector('i');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            icon.className = showing ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
            b.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });
    });

    function msg(text, kind) {
        var box = document.getElementById('authMessage');
        if (!box) { return; }
        box.className = 'auth-message ' + kind;
        box.textContent = text;
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function lock(form) {
        var btn = form.querySelector('.auth-submit');
        if (btn) { setTimeout(function () { btn.disabled = true; }, 0); }
    }

    var login = document.getElementById('loginForm');
    if (login) {
        login.addEventListener('submit', function (e) {
            var em = document.getElementById('loginEmail');
            var pw = document.getElementById('loginPassword');
            if (!em.value.trim() || !em.checkValidity()) { e.preventDefault(); msg('Please enter a valid email address.', 'error'); em.focus(); return; }
            if (!pw.value) { e.preventDefault(); msg('Please enter your password.', 'error'); pw.focus(); return; }
            lock(login);
        });
    }

    var signup = document.getElementById('signupForm');
    if (signup) {
        signup.addEventListener('submit', function (e) {
            var p = document.getElementById('signupPassword');
            var c = document.getElementById('confirmPassword');
            var t = document.getElementById('termsCheck');
            var fn = document.getElementById('firstName');
            var ln = document.getElementById('lastName');
            var em = document.getElementById('signupEmail');
            if (!fn.value.trim() || !ln.value.trim()) { e.preventDefault(); msg('Please enter your first and last name.', 'error'); return; }
            if (!em.value.trim() || !em.checkValidity()) { e.preventDefault(); msg('Please enter a valid email address.', 'error'); return; }
            if (p.value.length < 8) { e.preventDefault(); msg('Password must be at least 8 characters.', 'error'); return; }
            if (p.value !== c.value) { e.preventDefault(); msg('Passwords do not match.', 'error'); return; }
            if (!t.checked) { e.preventDefault(); msg('Please accept the Terms and Privacy Policy.', 'error'); return; }
            lock(signup);
        });
    }
});
