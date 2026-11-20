<?php
/** Single-use token from the admin panel -> user session (impersonated). */
require __DIR__ . '/includes/user_auth.php';
send_user_headers();
header('Referrer-Policy: no-referrer');
user_session_start();

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    exit('Invalid link.');
}

$pdo = db();
$pdo->beginTransaction();
$row = q('SELECT * FROM impersonation_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE', [hash('sha256', $token)])->fetch();
if (!$row) {
    $pdo->rollBack();
    http_response_code(410);
    exit('This link has expired or was already used. Use "Login as" again from the admin panel.');
}
q('UPDATE impersonation_tokens SET used_at = NOW() WHERE token_hash = ?', [hash('sha256', $token)]);
$pdo->commit();

session_regenerate_id(true);
start_user_session((int)$row['user_id'], (int)$row['admin_id']);
redirect('account.php');
