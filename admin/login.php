<?php
require __DIR__ . '/includes/auth.php';

if (is_logged_in() && current_admin()) {
    redirect('dashboard.php');
}

$ip           = client_ip();
$error        = '';
$info         = isset($_GET['expired']) ? 'Your session expired. Please sign in again.' : '';
$info         = isset($_GET['logout']) ? 'You have been signed out.' : $info;
$blockedUntil = ip_blocked_until($ip);
$left         = null;

if (!$blockedUntil && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_check()) {
        $error = 'Form expired. Please refresh the page and try again.';
    } elseif (($_POST['website'] ?? '') !== '' || $username === '' || $password === '' || strlen($password) > 256) {
        $left = register_failed_attempt($ip);
        log_admin_login($ip, $username, false);
        $error = 'Invalid username or password.';
    } else {
        $user = q('SELECT id, username, password_hash, session_version FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1', [$username])->fetch();

        // Verify a hash even when the user doesn't exist (prevents username timing leaks)
        $hash = $user['password_hash'] ?? '$2y$12$QNOwP7YTqFT6QQDvETbetOWeWPzhiPXe/h.okzHIrtMGKdTZebX5i';
        $ok = password_verify($password, $hash) && $user;

        if ($ok) {
            clear_failed_attempts($ip);
            log_admin_login($ip, $username, true);
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                q('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
            }
            q('UPDATE admin_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, $user['id']]);

            session_regenerate_id(true);
            $now = time();
            $_SESSION = [
                'admin_id' => (int)$user['id'], 'admin_user' => $user['username'], 'login_at' => $now,
                'last_activity' => $now, 'rotated_at' => $now, 'fp' => session_fingerprint(), 'csrf' => bin2hex(random_bytes(32)), 'sv' => (int)$user['session_version'],
            ];
            audit('auth.login', 'admin', $user['id'], 'Admin logged in');
            redirect('dashboard.php');
        }

        $left = register_failed_attempt($ip);
        log_admin_login($ip, $username, false);
        usleep(random_int(300000, 700000));
        $error = 'Invalid username or password.';
    }

    if ($left !== null) {
        if ($left <= 0) {
            $blockedUntil = ip_blocked_until($ip);
        } else {
            $error .= ' ' . $left . ' attempt' . ($left > 1 ? 's' : '') . ' left.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login · <?= e(SITE_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="auth-page">
<div class="auth-grid"></div>
<main class="auth-wrap">
    <div class="auth-brand">
        <span class="brand-icon"><?= icon('logo', 20) ?></span>
        <span class="brand-text"><strong>SMART</strong><small>PREDICTION HUB</small></span>
    </div>

    <section class="auth-card">
        <?php if ($blockedUntil): ?>
            <div class="blocked">
                <div class="blocked-icon"><?= icon('lock', 28) ?></div>
                <h1>Access Blocked</h1>
                <p>Too many failed sign-in attempts. Your IP <strong><?= e($ip) ?></strong> is temporarily blocked.</p>
                <p class="muted">Unblock time: <strong><?= e(fmt_dt($blockedUntil)) ?></strong> (IST)</p>
            </div>
        <?php else: ?>
            <span class="eyebrow">ADMIN PANEL</span>
            <h1>Welcome back</h1>
            <p class="muted" style="margin-bottom:22px">Sign in to access the dashboard.</p>

            <?php if ($error): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($error) ?></span></div><?php endif; ?>
            <?php if ($info && !$error): ?><div class="alert alert-info"><?= icon('check', 16) ?><span><?= e($info) ?></span></div><?php endif; ?>

            <form method="post" action="login.php" class="form" autocomplete="off" data-once>
                <?= csrf_field() ?>
                <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                <div class="field">
                    <label class="lbl" for="username">Username</label>
                    <input type="text" id="username" name="username" maxlength="50" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="lbl" for="password">Password</label>
                    <div class="pw-wrap">
                        <input type="password" id="password" name="password" maxlength="256" required autocomplete="current-password">
                        <button type="button" class="pw-toggle" data-pw-toggle="password">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>
            <p class="note">🔒 Your IP will be blocked for <?= (int)BLOCK_DURATION_HOURS ?> hours after <?= (int)MAX_LOGIN_ATTEMPTS ?> failed attempts.</p>
        <?php endif; ?>
    </section>
    <p class="foot">© <?= date('Y') ?> <?= e(SITE_NAME) ?> · Authorized access only</p>
</main>
<script src="assets/admin.js?v=2"></script>
</body>
</html>
