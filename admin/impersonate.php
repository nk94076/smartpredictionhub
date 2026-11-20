<?php
/**
 * Admin -> "Login as user"
 * Creates a single-use token (valid 60 seconds) and hands off to the user side,
 * which starts a user session (impersonated_by = admin id). Everything is logged.
 */
require __DIR__ . '/includes/auth.php';
require_perm('users.impersonate');
require_post_csrf('users.php');

$userId = (int)($_POST['user_id'] ?? 0);
$user = q('SELECT id, name, email FROM users WHERE id = ?', [$userId])->fetch();
if (!$user) {
    flash('error', 'User not found.');
    redirect('users.php');
}

$token = bin2hex(random_bytes(32));
q('DELETE FROM impersonation_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL');
q('INSERT INTO impersonation_tokens (token_hash, user_id, admin_id, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 60 SECOND)',
    [hash('sha256', $token), $userId, current_admin()['id']]);
audit('user.impersonate', 'user', $userId, 'Logged in as ' . $user['email']);

redirect(USER_PATH . '/impersonate.php?t=' . $token);
