<?php
require __DIR__ . '/includes/user_auth.php';
send_user_headers();
user_session_start();

$user = current_user();
if (!$user) { redirect('login.php'); }

$sessions = q('SELECT login_at, logout_at, city, country, device, browser, os FROM user_sessions
               WHERE user_id = ? AND impersonated_by IS NULL ORDER BY id DESC LIMIT 5', [$user['id']])->fetchAll();
$firstName = explode(' ', trim($user['name']))[0];

$banner = '';
if ($user['impersonated_by']) {
    $adm = q('SELECT username FROM admin_users WHERE id = ?', [$user['impersonated_by']])->fetchColumn();
    $banner = '<div class="imp-bar"><span><i class="fa-solid fa-user-secret"></i> <strong>Admin view:</strong> You are signed in as <strong>'
        . e($user['name']) . '</strong> (by @' . e($adm ?: 'admin') . '). This session is being logged.</span>'
        . '<form method="post" action="logout.php">' . csrf_field() . '<button type="submit">Exit &amp; return to admin</button></form></div>';
}

auth_page_start('My Account', 'Your Smart Prediction Hub account.', ['label' => 'Log out', 'logout' => true], $banner);
?>
<section class="auth-section">
    <div class="auth-wrap">
        <div class="auth-panel">
            <span class="auth-kicker"><i class="fa-solid fa-chart-line"></i> YOUR ACCOUNT</span>
            <h1>Welcome<?= isset($_GET['welcome']) ? '' : ' back' ?>, <?= e($firstName) ?>.</h1>
            <p><?= isset($_GET['welcome'])
                ? 'Your account is ready. Start saving reviews, guides and platforms you want to compare.'
                : 'Your saved research, platform shortlist and updates are all in one place.' ?></p>
            <div class="acc-stats">
                <div class="acc-stat"><small>Member since</small><strong><?= e(fmt_dt($user['created_at'], 'd M Y')) ?></strong></div>
                <div class="acc-stat"><small>Total sign-ins</small><strong><?= (int)$user['login_count'] ?></strong></div>
                <div class="acc-stat"><small>Last sign-in</small><strong><?= e(fmt_dt($user['last_login_at'], 'd M, h:i A')) ?></strong></div>
            </div>
            <div class="auth-mini-card"><small>SMARTER RESEARCH</small><strong>One account. Your saved prediction-market research.</strong></div>
        </div>

        <div class="auth-form-card">
            <div class="auth-form-head">
                <div style="display:flex;gap:12px;align-items:center">
                    <span class="acc-avatar"><?= e(initials($user['name'])) ?></span>
                    <div>
                        <h2 style="margin:0"><?= e($user['name']) ?></h2>
                        <p><?= e($user['email']) ?></p>
                    </div>
                </div>
                <a class="auth-switch" href="/">Go to homepage →</a>
            </div>

            <?php if (isset($_GET['welcome'])): ?>
                <div class="auth-message success">Welcome aboard! Your account has been created successfully.</div>
            <?php endif; ?>

            <h3 class="acc-title">Profile</h3>
            <div class="acc-list">
                <div class="acc-row"><span>Full name</span><strong><?= e($user['name']) ?></strong></div>
                <div class="acc-row"><span>Email address</span><strong><?= e($user['email']) ?></strong></div>
                <div class="acc-row"><span>Research updates</span><strong><?= !empty($user['marketing_opt_in']) ? 'Subscribed' : 'Not subscribed' ?></strong></div>
                <div class="acc-row"><span>Account status</span><strong><?= $user['status'] === 'active' ? 'Active' : 'Suspended' ?></strong></div>
            </div>

            <h3 class="acc-title">Recent sign-ins</h3>
            <div class="acc-list">
                <?php if (!$sessions): ?><div class="acc-row"><span>No sign-ins yet.</span></div><?php endif; ?>
                <?php foreach ($sessions as $s): ?>
                    <div class="sess-row">
                        <span class="when"><?= e(fmt_dt($s['login_at'])) ?></span>
                        <span class="pill <?= $s['logout_at'] ? 'pill-off' : 'pill-on' ?>"><?= $s['logout_at'] ? 'Ended' : 'Active' ?></span>
                        <span class="meta"><?= e(location_str($s['city'], $s['country'])) ?> · <?= e($s['browser'] . ' on ' . $s['os']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-note">Not you, or signed in on a shared device? Use <strong>Log out</strong> at the top of the page.</div>
        </div>
    </div>
</section>
<?php auth_page_end();
