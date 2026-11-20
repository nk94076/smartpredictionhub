/*!
 * Smart Prediction Hub — first-touch source tracker
 * Add this before </body> on EVERY page of your site:
 *   <script src="/user/assets/track.js" defer></script>
 * Stores where the visitor first came from (Google, Facebook, UTM campaign, referrer) in a cookie for 30 days.
 * On registration, this data is saved to the user's profile.
 */
(function () {
    try {
        if (document.cookie.indexOf('sph_ft=') !== -1) { return; }
        var p = new URLSearchParams(location.search);
        var ref = document.referrer || '';
        try { if (ref && new URL(ref).host === location.host) { ref = ''; } } catch (e) { ref = ''; }
        var data = {
            us: (p.get('utm_source') || '').slice(0, 100),
            um: (p.get('utm_medium') || '').slice(0, 100),
            uc: (p.get('utm_campaign') || '').slice(0, 150),
            gc: p.has('gclid') ? 1 : 0,
            fc: p.has('fbclid') ? 1 : 0,
            ref: ref.slice(0, 500),
            land: (location.pathname + location.search).slice(0, 500),
            ts: Math.floor(Date.now() / 1000)
        };
        var secure = location.protocol === 'https:' ? '; secure' : '';
        document.cookie = 'sph_ft=' + encodeURIComponent(JSON.stringify(data)) + '; path=/; max-age=2592000; samesite=lax' + secure;
    } catch (e) { /* tracking failures never affect the site */ }
})();
