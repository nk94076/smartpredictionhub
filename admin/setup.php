<?php
/**
 * ONE-TIME SETUP / UPGRADE
 * - Creates all tables (or upgrades from v1)
 * - Creates the first SUPER ADMIN if no admin exists
 * DELETE this file from the server once setup is complete.
 */
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/schema.php';

$errors = [];
$log = [];
$done = false;

// Check whether tables already exist
$hasTable = (bool)q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'")->fetchColumn();
$adminExists = $hasTable && (int)q('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Form expired. Please refresh and try again.';
    } elseif (!hash_equals(SETUP_KEY, (string)($_POST['setup_key'] ?? ''))) {
        $errors[] = 'Invalid setup key.';
        usleep(500000);
    } else {
        $log = run_migrations();
        $adminExists = (int)q('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;

        if (!$adminExists) {
            $name     = trim((string)($_POST['name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email    = trim((string)($_POST['email'] ?? ''));
            $pass     = (string)($_POST['password'] ?? '');
            $pass2    = (string)($_POST['password2'] ?? '');

            if ($name === '' || mb_strlen($name) > 100) { $errors[] = 'Please enter a name.'; }
            if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) { $errors[] = 'Username 4–50 characters (letters, numbers, _ . -).'; }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
            if (strlen($pass) < 12 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/\d/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
                $errors[] = 'Password must be at least 12 characters and include uppercase, lowercase, a number and a symbol.';
            }
            if ($pass !== $pass2) { $errors[] = 'Passwords do not match.'; }

            if (!$errors) {
                q("INSERT INTO admin_users (name, username, email, password_hash, is_super, perm_mode) VALUES (?, ?, ?, ?, 1, 'role')",
                    [$name, $username, $email ?: null, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])]);
                $log[] = "Super Admin '$username' created";
                $done = true;
            }
        } else {
            $done = true;
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
    <title>Setup · <?= e(SITE_NAME) ?> Admin</title>
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
    <section class="auth-card" style="max-width:480px">
        <span class="eyebrow">SETUP / UPGRADE</span>
        <?php if ($done): ?>
            <h1>Setup complete ✅</h1>
            <div class="alert alert-success" style="margin-top:12px"><?= icon('check', 16) ?><span>Database is ready. Now DELETE <strong>setup.php</strong> from the server.</span></div>
            <ul class="small muted" style="margin:0 0 18px 18px;line-height:1.8"><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
            <a class="btn btn-primary btn-block" href="login.php">Go to Login</a>
        <?php else: ?>
            <h1><?= $adminExists ? 'Upgrade database' : 'Create Super Admin' ?></h1>
            <p class="muted" style="margin-bottom:18px"><?= $adminExists ? 'An admin already exists. Enter the setup key to add new tables and columns.' : 'This will be the top-level account that creates all other admins.' ?></p>
            <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('x', 16) ?><span><?= e($er) ?></span></div><?php endforeach; ?>
            <form method="post" action="setup.php" class="form" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field"><label class="lbl">Setup key <span class="muted">(SETUP_KEY in app/config.php)</span></label><input type="password" name="setup_key" required></div>
                <?php if (!$adminExists): ?>
                    <div class="form-row">
                        <div class="field"><label class="lbl">Full name</label><input type="text" name="name" maxlength="100" required value="<?= e($_POST['name'] ?? '') ?>"></div>
                        <div class="field"><label class="lbl">Username</label><input type="text" name="username" maxlength="50" required value="<?= e($_POST['username'] ?? '') ?>"></div>
                    </div>
                    <div class="field"><label class="lbl">Email (optional)</label><input type="email" name="email" maxlength="190" value="<?= e($_POST['email'] ?? '') ?>"></div>
                    <div class="field"><label class="lbl">Password</label><input type="password" name="password" required autocomplete="new-password"><p class="hint">12+ characters with A-Z, a-z, 0-9 and a symbol</p></div>
                    <div class="field"><label class="lbl">Confirm password</label><input type="password" name="password2" required autocomplete="new-password"></div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-block"><?= $adminExists ? 'Run upgrade' : 'Create Super Admin' ?></button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
