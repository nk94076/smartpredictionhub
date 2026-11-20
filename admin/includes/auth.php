<?php
/**
 * Admin bootstrap: headers, hardened session, IP blocking, auth, permissions, audit log.
 * Include at the top of every admin page: require __DIR__ . '/includes/auth.php';
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/icons.php';

/* ---------------- Headers ---------------- */
send_security_headers("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ---------------- Session ---------------- */
$sessDir = __DIR__ . '/../../app/sessions';
if (is_dir($sessDir) && is_writable($sessDir)) {
    session_save_path($sessDir); // keep shared-hosting GC from expiring our sessions early
}
ini_set('session.gc_maxlifetime', (string)SESSION_ABSOLUTE_TIMEOUT);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name(ADMIN_SESSION_NAME);
session_set_cookie_params(['lifetime' => 0, 'path' => ADMIN_PATH, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

/* ---------------- Flash messages ---------------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- Admin IP blocking ---------------- */
function ip_blocked_until(string $ip): ?string
{
    $v = q('SELECT blocked_until FROM admin_ip_blocks WHERE ip = ? AND blocked_until > NOW()', [$ip])->fetchColumn();
    return $v ?: null;
}

/** Register a failed attempt. Returns attempts left (0 = now blocked). Row lock makes it race-safe. */
function register_failed_attempt(string $ip): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT IGNORE INTO admin_ip_blocks (ip, fail_count, last_fail_at) VALUES (?, 0, NOW())', [$ip]);
        $row = q('SELECT fail_count,
                         (blocked_until IS NOT NULL AND blocked_until <= NOW()) AS block_expired,
                         (last_fail_at < NOW() - INTERVAL ' . (int)BLOCK_DURATION_HOURS . ' HOUR) AS stale
                  FROM admin_ip_blocks WHERE ip = ? FOR UPDATE', [$ip])->fetch();
        $count = ($row['block_expired'] || $row['stale']) ? 1 : (int)$row['fail_count'] + 1;

        if ($count >= MAX_LOGIN_ATTEMPTS) {
            q('UPDATE admin_ip_blocks SET fail_count = ?, last_fail_at = NOW(), blocked_until = NOW() + INTERVAL ' . (int)BLOCK_DURATION_HOURS . ' HOUR WHERE ip = ?', [$count, $ip]);
        } else {
            q('UPDATE admin_ip_blocks SET fail_count = ?, last_fail_at = NOW(), blocked_until = NULL WHERE ip = ?', [$count, $ip]);
        }
        $pdo->commit();
        return max(0, MAX_LOGIN_ATTEMPTS - $count);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('register_failed_attempt: ' . $e->getMessage());
        return 0;
    }
}

function clear_failed_attempts(string $ip): void
{
    q('DELETE FROM admin_ip_blocks WHERE ip = ?', [$ip]);
}

function log_admin_login(string $ip, string $username, bool $success): void
{
    q('INSERT INTO admin_login_log (ip, username, success, user_agent) VALUES (?, ?, ?, ?)',
        [$ip, mb_substr($username, 0, 100), $success ? 1 : 0, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
}

/* ---------------- Audit log ---------------- */
function audit(string $action, ?string $targetType = null, $targetId = null, string $details = ''): void
{
    $a = current_admin();
    q('INSERT INTO admin_activity_log (admin_id, admin_username, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $a['id'] ?? ($_SESSION['admin_id'] ?? null), $a['username'] ?? ($_SESSION['admin_user'] ?? null),
        $action, $targetType, $targetId !== null ? (string)$targetId : null, mb_substr($details, 0, 500), client_ip(),
    ]);
}

/* ---------------- Session auth ---------------- */
function session_fingerprint(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ADMIN_SESSION_NAME);
}

function destroy_session(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
        'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
    session_destroy();
}

function is_logged_in(): bool
{
    if (empty($_SESSION['admin_id'])) { return false; }
    $now = time();
    $expired = ($now - ($_SESSION['last_activity'] ?? 0)) > SESSION_IDLE_TIMEOUT
            || ($now - ($_SESSION['login_at'] ?? 0)) > SESSION_ABSOLUTE_TIMEOUT
            || !hash_equals($_SESSION['fp'] ?? '', session_fingerprint());
    if ($expired) {
        audit('auth.session_expired', 'admin', $_SESSION['admin_id']);
        destroy_session();
        return false;
    }
    if ($now - ($_SESSION['rotated_at'] ?? 0) > 900) {
        session_regenerate_id(true);
        $_SESSION['rotated_at'] = $now;
    }
    $_SESSION['last_activity'] = $now;
    return true;
}

/** Logged-in admin row + effective permissions (loaded once per request) */
function current_admin(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    if (empty($_SESSION['admin_id'])) { return null; }

    $row = q('SELECT a.*, r.name AS role_name FROM admin_users a LEFT JOIN admin_roles r ON r.id = a.role_id
              WHERE a.id = ? AND a.is_active = 1', [$_SESSION['admin_id']])->fetch();
    // session_version changes on password change / deactivate / "logout everywhere" -> old sessions become invalid
    if (!$row || (int)$row['session_version'] !== (int)($_SESSION['sv'] ?? -1)) { return $cache = null; }
    $row['perms'] = effective_perms($row);
    return $cache = $row;
}

function effective_perms(array $admin): array
{
    if (!empty($admin['is_super'])) { return all_perm_keys(); }
    if ($admin['perm_mode'] === 'custom') {
        $p = q('SELECT perm FROM admin_user_permissions WHERE admin_id = ?', [$admin['id']])->fetchAll(PDO::FETCH_COLUMN);
    } elseif (!empty($admin['role_id'])) {
        $p = q('SELECT perm FROM admin_role_permissions WHERE role_id = ?', [$admin['role_id']])->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $p = [];
    }
    return array_values(array_intersect(all_perm_keys(), $p));
}

function is_super(): bool
{
    return !empty(current_admin()['is_super']);
}

function can(string $perm): bool
{
    $a = current_admin();
    return $a && (in_array($perm, $a['perms'], true));
}

function require_admin(): void
{
    $had = !empty($_SESSION['admin_id']);
    if (!is_logged_in() || !current_admin()) {
        if ($had && session_status() === PHP_SESSION_ACTIVE) { destroy_session(); }
        redirect('login.php' . ($had ? '?expired=1' : ''));
    }
}

function deny_page(string $msg = 'You do not have permission to access this page.'): void
{
    http_response_code(403);
    layout_start('Access denied', '');
    echo '<div class="empty-state card">' . icon('lock', 40) . '<h2>Access denied</h2><p>' . e($msg) . '</p>
          <p class="muted">Ask a Super Admin for access.</p><a class="btn btn-primary" href="dashboard.php">Back to Dashboard</a></div>';
    layout_end();
    exit;
}

function require_perm(string $perm): void
{
    require_admin();
    if (!can($perm)) { deny_page(); }
}

function require_super(): void
{
    require_admin();
    if (!is_super()) { deny_page('This section is only available to Super Admins.'); }
}

/** Require a POST with a valid CSRF token, otherwise flash + redirect */
function require_post_csrf(string $back): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
        flash('error', 'Your request expired. Please try again.');
        redirect($back);
    }
}

/* ---------------- Pagination ---------------- */
function paginate(int $total, int $perPage = 25): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    return ['total' => $total, 'per' => $perPage, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}

function page_url(int $page): string
{
    $qs = $_GET;
    $qs['page'] = $page;
    return '?' . http_build_query($qs);
}

function render_pagination(array $p): string
{
    if ($p['pages'] <= 1) {
        return '<div class="pager"><span class="muted">' . number_format($p['total']) . ' results</span></div>';
    }
    $from = $p['offset'] + 1;
    $to = min($p['total'], $p['offset'] + $p['per']);
    $h = '<div class="pager"><span class="muted">Showing ' . $from . '–' . $to . ' of ' . number_format($p['total']) . '</span><div class="pager-links">';
    $h .= $p['page'] > 1 ? '<a href="' . e(page_url($p['page'] - 1)) . '">‹ Prev</a>' : '<span class="disabled">‹ Prev</span>';
    $start = max(1, $p['page'] - 2);
    $end = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $h .= $i === $p['page'] ? '<span class="current">' . $i . '</span>' : '<a href="' . e(page_url($i)) . '">' . $i . '</a>';
    }
    $h .= $p['page'] < $p['pages'] ? '<a href="' . e(page_url($p['page'] + 1)) . '">Next ›</a>' : '<span class="disabled">Next ›</span>';
    return $h . '</div></div>';
}

/** Mark user sessions past the idle timeout as "expired" (logout time = last seen) */
function close_stale_user_sessions(): void
{
    q("UPDATE user_sessions SET logout_at = last_seen_at, logout_type = 'expired'
       WHERE logout_at IS NULL AND (last_seen_at < NOW() - INTERVAL " . (int)USER_IDLE_HOURS . " HOUR OR expires_at < NOW())");
}

require_once __DIR__ . '/layout.php';
