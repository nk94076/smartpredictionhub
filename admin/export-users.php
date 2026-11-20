<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/user_filters.php';
require_perm('users.export');

[$f, $whereSql, $params, $order] = user_filters();
$st = q("SELECT u.id, u.name, u.email, u.phone, u.status, u.marketing_opt_in, u.created_at, u.reg_ip, u.reg_country, u.reg_region, u.reg_city,
                u.reg_source, u.reg_medium, u.reg_campaign, u.reg_referrer, u.reg_landing_page, u.reg_device, u.reg_browser, u.reg_os,
                u.last_login_at, u.last_login_ip, u.last_seen_at, u.login_count
         FROM users u $whereSql ORDER BY $order LIMIT 100000", $params);

audit('users.export', 'user', null, 'CSV export: ' . http_build_query($_GET));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users-' . date('Y-m-d-His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Status', 'Research Updates Opt-in', 'Registered At', 'Reg IP', 'Country', 'Region', 'City', 'Source', 'Medium', 'Campaign',
    'Referrer', 'Landing Page', 'Device', 'Browser', 'OS', 'Last Login', 'Last Login IP', 'Last Seen', 'Login Count'], ',', '"', '\\');

// Guard against CSV injection (values starting with =, +, -, @)
$safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
while ($row = $st->fetch(PDO::FETCH_NUM)) {
    fputcsv($out, array_map($safe, $row), ',', '"', '\\');
}
fclose($out);
