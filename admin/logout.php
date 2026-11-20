<?php
require __DIR__ . '/includes/auth.php';

// Sign out only via POST with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    if (!empty($_SESSION['admin_id'])) {
        audit('auth.logout', 'admin', $_SESSION['admin_id'], 'Admin logged out');
    }
    destroy_session();
    redirect('login.php?logout=1');
}
redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
