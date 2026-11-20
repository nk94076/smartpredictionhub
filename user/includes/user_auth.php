<?php
/**
 * Site user authentication + tracking.
 *
 * To get the signed-in user on any PHP page of your site:
 *   require $_SERVER['DOCUMENT_ROOT'] . '/user/includes/user_auth.php';
 *   $user = current_user();   // null = signed out
 * (This also keeps the user's "last seen" / online status up to date.)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

function send_user_headers(): void
{
    send_security_headers("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('Cache-Control: no-store, max-age=0');
}

/** Small PHP session used only for CSRF tokens on form pages */
function user_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SPH_USESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** $expires = 0 creates a browser-session cookie (cleared when the browser closes) */
function user_cookie_set(string $token, int $expires): void
{
    setcookie(USER_COOKIE, $token, ['expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Signed-in user, or null. Sessions live in the database, so admin force-logout / block takes effect instantly. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }

    $token = $_COOKIE[USER_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) { return $cache = null; }

    $s = q('SELECT s.id AS session_id, s.login_at, s.last_seen_at, s.logout_at, s.expires_at, s.impersonated_by,
                   (s.expires_at < NOW() OR s.last_seen_at < NOW() - INTERVAL ' . (int)USER_IDLE_HOURS . ' HOUR) AS is_expired,
                   (s.last_seen_at < NOW() - INTERVAL 60 SECOND) AS needs_touch, u.*
            FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?', [hash('sha256', $token)])->fetch();

    if (!$s || $s['logout_at']) {
        user_cookie_set('', time() - 3600);
        return $cache = null;
    }
    if ($s['is_expired']) {
        q("UPDATE user_sessions SET logout_at = last_seen_at, logout_type = 'expired' WHERE id = ?", [$s['session_id']]);
        user_cookie_set('', time() - 3600);
        return $cache = null;
    }
    if ($s['status'] === 'blocked' && !$s['impersonated_by']) {
        q("UPDATE user_sessions SET logout_at = NOW(), logout_type = 'forced' WHERE id = ?", [$s['session_id']]);
        user_cookie_set('', time() - 3600);
        return $cache = null;
    }
    // Update "last seen" at most once every 60 seconds to keep DB load low
    if ($s['needs_touch']) {
        q('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?', [$s['session_id']]);
        if (!$s['impersonated_by']) { q('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$s['id']]); }
    }
    unset($s['password_hash']);
    return $cache = $s;
}

/**
 * Start a session after sign-in (records IP, location and device).
 * $remember = true  -> stays signed in for USER_SESSION_DAYS
 * $remember = false -> signed out when the browser closes (max 24 hours)
 */
function start_user_session(int $userId, ?int $impersonatedBy = null, bool $remember = true): void
{
    // Close any session tied to the current cookie first
    end_user_session($impersonatedBy ? 'admin_exit' : 'manual', false);

    $ip = client_ip();
    $geo = geo_lookup($ip);
    $ua = parse_ua($_SERVER['HTTP_USER_AGENT'] ?? '');
    $token = bin2hex(random_bytes(32));

    if ($impersonatedBy) {
        $expires = time() + 3600;              // admin "Login as" sessions last 1 hour max
        $cookieExpires = 0;
    } elseif ($remember) {
        $expires = time() + USER_SESSION_DAYS * 86400;
        $cookieExpires = $expires;
    } else {
        $expires = time() + 86400;
        $cookieExpires = 0;
    }

    q('INSERT INTO user_sessions (user_id, token_hash, login_at, last_seen_at, expires_at, ip, country, country_code, city, device, browser, os, user_agent, impersonated_by)
       VALUES (?, ?, NOW(), NOW(), FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, hash('sha256', $token), $expires, $ip, $geo['country'], $geo['country_code'], $geo['city'],
         $ua['device'], $ua['browser'], $ua['os'], $ua['ua'], $impersonatedBy]);

    if (!$impersonatedBy) {
        q('UPDATE users SET last_login_at = NOW(), last_login_ip = ?, last_seen_at = NOW(), login_count = login_count + 1 WHERE id = ?', [$ip, $userId]);
    }
    user_cookie_set($token, $cookieExpires);
}

function end_user_session(string $type = 'manual', bool $clearCookie = true): ?array
{
    $token = $_COOKIE[USER_COOKIE] ?? '';
    $row = null;
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $row = q('SELECT id, user_id, impersonated_by FROM user_sessions WHERE token_hash = ? AND logout_at IS NULL', [hash('sha256', $token)])->fetch() ?: null;
        if ($row) {
            q('UPDATE user_sessions SET logout_at = NOW(), logout_type = ? WHERE id = ?', [$row['impersonated_by'] ? 'admin_exit' : $type, $row['id']]);
        }
    }
    if ($clearCookie) { user_cookie_set('', time() - 3600); }
    return $row;
}

/* ---------------- Rate limits ---------------- */
function user_login_rate_limited(string $ip): bool
{
    return (int)q('SELECT COUNT(*) FROM user_login_attempts WHERE ip = ? AND success = 0 AND created_at > NOW() - INTERVAL 15 MINUTE', [$ip])->fetchColumn()
        >= USER_MAX_FAILED_LOGINS;
}

function log_user_attempt(string $ip, string $email, bool $success): void
{
    q('INSERT INTO user_login_attempts (ip, email, success) VALUES (?, ?, ?)', [$ip, mb_substr($email, 0, 190), $success ? 1 : 0]);
}

function registration_rate_limited(string $ip): bool
{
    return (int)q('SELECT COUNT(*) FROM users WHERE reg_ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip])->fetchColumn() >= USER_MAX_REG_PER_HOUR;
}

/* ---------------- Page layout (matches the site's auth design) ---------------- */

/**
 * $headerAction: ['label' => 'Register', 'href' => 'register.php']  -> link button
 *                ['label' => 'Log out', 'logout' => true]            -> sign-out form button
 */
function auth_page_start(string $title, string $description, array $headerAction, string $beforeHeader = ''): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> — <?= e(SITE_NAME) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="robots" content="noindex">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="assets/auth.css?v=1">
</head>
<body>
<?= $beforeHeader ?>
<header class="simple-header">
    <div class="simple-header-inner">
        <a href="/" class="simple-logo" aria-label="<?= e(SITE_NAME) ?> home">
            <span class="simple-logo-icon"><i class="fa-solid fa-chart-column"></i></span>
            <span class="simple-logo-text"><strong>SMART</strong><span>Prediction Hub</span></span>
        </a>
        <?php if (!empty($headerAction['logout'])): ?>
            <form method="post" action="logout.php" class="header-auth-form"><?= csrf_field() ?>
                <button type="submit" class="header-auth-btn"><?= e($headerAction['label']) ?> <i class="fa-solid fa-arrow-right-from-bracket"></i></button>
            </form>
        <?php else: ?>
            <a href="<?= e($headerAction['href']) ?>" class="header-auth-btn"><?= e($headerAction['label']) ?> <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
    </div>
</header>
<?php
}

function auth_page_end(): void
{
    ?>
<footer class="simple-footer">
    <div class="simple-footer-inner">
        <span>© <?= date('Y') ?> <?= e(SITE_NAME) ?></span>
        <div class="simple-footer-links">
            <a href="/coming-soon.html">Privacy</a>
            <a href="/coming-soon.html">Terms</a>
            <a href="/coming-soon.html">Contact</a>
        </div>
    </div>
</footer>
<script src="assets/auth.js?v=1"></script>
</body>
</html>
<?php
}

/** Renders the server-side message box (same style as the design's #authMessage) */
function auth_message(string $text, string $type = 'error'): string
{
    return '<div id="authMessage" class="auth-message ' . ($text !== '' ? e($type) : '') . '">' . e($text) . '</div>';
}
