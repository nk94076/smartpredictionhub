<?php
require __DIR__ . '/includes/user_auth.php';
send_user_headers();
user_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    redirect(current_user() ? 'account.php' : 'login.php');
}

$ended = end_user_session('manual');
session_regenerate_id(true);

// Came from admin "Login as" -> return to the admin panel
if ($ended && $ended['impersonated_by']) {
    redirect(ADMIN_PATH . '/user-view.php?id=' . (int)$ended['user_id']);
}
redirect('login.php?logout=1');
