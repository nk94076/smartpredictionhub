<?php
/** Shared filter logic for the users list and CSV export */
function user_filters(): array
{
    $f = [
        'q'       => trim((string)($_GET['q'] ?? '')),
        'status'  => in_array($_GET['status'] ?? '', ['active', 'blocked', 'online'], true) ? $_GET['status'] : '',
        'country' => preg_match('/^[A-Z]{2}$/', (string)($_GET['country'] ?? '')) ? $_GET['country'] : '',
        'source'  => mb_substr(trim((string)($_GET['source'] ?? '')), 0, 100),
        'device'  => in_array($_GET['device'] ?? '', ['Desktop', 'Mobile', 'Tablet', 'Bot'], true) ? $_GET['device'] : '',
        'from'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '',
        'to'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '',
        'sort'    => in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'last_login', 'logins', 'name'], true) ? $_GET['sort'] : 'newest',
    ];

    $where = [];
    $params = [];
    if ($f['q'] !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.reg_ip = ? OR u.last_login_ip = ?)';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        array_push($params, $like, $like, $like, $f['q'], $f['q']);
    }
    if ($f['status'] === 'online') {
        $where[] = 'u.last_seen_at >= NOW() - INTERVAL ' . (int)ONLINE_WINDOW_MIN . ' MINUTE';
    } elseif ($f['status']) {
        $where[] = 'u.status = ?'; $params[] = $f['status'];
    }
    if ($f['country']) { $where[] = 'u.reg_country_code = ?'; $params[] = $f['country']; }
    if ($f['source'] !== '') { $where[] = 'u.reg_source = ?'; $params[] = $f['source']; }
    if ($f['device']) { $where[] = 'u.reg_device = ?'; $params[] = $f['device']; }
    if ($f['from']) { $where[] = 'u.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to']) { $where[] = 'u.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }

    $order = match ($f['sort']) {
        'oldest' => 'u.id ASC',
        'last_login' => 'u.last_login_at IS NULL, u.last_login_at DESC',
        'logins' => 'u.login_count DESC',
        'name' => 'u.name ASC',
        default => 'u.id DESC',
    };

    return [$f, $where ? 'WHERE ' . implode(' AND ', $where) : '', $params, $order];
}
