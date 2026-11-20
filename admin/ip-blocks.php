<?php
require __DIR__ . '/includes/auth.php';
require_perm('security.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('ip-blocks.php');
    if (!can('security.manage')) { deny_page(); }
    $ip = (string)($_POST['ip'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        clear_failed_attempts($ip);
        audit('ip.unblock', 'ip', $ip, 'Admin panel IP unblocked');
        flash('success', "IP $ip has been unblocked.");
    }
    redirect('ip-blocks.php');
}

$blocked = q('SELECT * FROM admin_ip_blocks WHERE blocked_until > NOW() ORDER BY blocked_until DESC')->fetchAll();
$watch = q('SELECT * FROM admin_ip_blocks WHERE (blocked_until IS NULL OR blocked_until <= NOW()) AND last_fail_at > NOW() - INTERVAL ' . (int)BLOCK_DURATION_HOURS . ' HOUR ORDER BY last_fail_at DESC')->fetchAll();
$fails24 = (int)q('SELECT COUNT(*) FROM admin_login_log WHERE success = 0 AND created_at > NOW() - INTERVAL 24 HOUR')->fetchColumn();

layout_start('Blocked IPs', 'ipblocks', 'Admin login: ' . (int)MAX_LOGIN_ATTEMPTS . ' failed attempts = ' . (int)BLOCK_DURATION_HOURS . '-hour IP block');
?>
<div class="stats">
    <div class="card stat"><div class="stat-ic si-red"><?= icon('ban', 20) ?></div><div><div class="stat-label">Currently blocked</div><div class="stat-value"><?= count($blocked) ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-amber"><?= icon('x', 20) ?></div><div><div class="stat-label">Failed admin logins (24h)</div><div class="stat-value"><?= $fails24 ?></div></div></div>
    <div class="card stat"><div class="stat-ic si-blue"><?= icon('eye', 20) ?></div><div><div class="stat-label">IPs on watch</div><div class="stat-value"><?= count($watch) ?></div></div></div>
</div>

<div class="card mb">
    <div class="card-head"><h2>Blocked right now</h2><span class="muted small">Auto-unblock after <?= (int)BLOCK_DURATION_HOURS ?>h</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>IP address</th><th>Failed attempts</th><th>Last attempt</th><th>Blocked until</th><th></th></tr></thead>
        <tbody>
        <?php if (!$blocked): ?><tr><td colspan="5" class="empty">No blocked IPs 🎉</td></tr><?php endif; ?>
        <?php foreach ($blocked as $b): ?>
            <tr>
                <td class="mono"><?= e($b['ip']) ?></td>
                <td><?= (int)$b['fail_count'] ?></td>
                <td><?= e(fmt_dt($b['last_fail_at'])) ?></td>
                <td><?= e(fmt_dt($b['blocked_until'])) ?><span class="sub"><?= e(fmt_duration(strtotime($b['blocked_until']) - time())) ?> left</span></td>
                <td class="text-right">
                    <?php if (can('security.manage')): ?>
                    <form method="post" class="inline" data-confirm="IP <?= e($b['ip']) ?> — unblock this IP?"><?= csrf_field() ?>
                        <input type="hidden" name="ip" value="<?= e($b['ip']) ?>"><button class="btn btn-soft btn-sm"><?= icon('unlock', 14) ?>Unblock</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-head"><h2>Watch list</h2><span class="muted small">IPs with failed attempts that are not blocked yet</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>IP address</th><th>Failed attempts</th><th>Last attempt</th><th>Attempts left</th></tr></thead>
        <tbody>
        <?php if (!$watch): ?><tr><td colspan="4" class="empty">All clear.</td></tr><?php endif; ?>
        <?php foreach ($watch as $w): ?>
            <tr><td class="mono"><?= e($w['ip']) ?></td><td><?= (int)$w['fail_count'] ?></td><td><?= e(time_ago($w['last_fail_at'])) ?></td>
                <td><span class="badge b-amber"><?= max(0, MAX_LOGIN_ATTEMPTS - (int)$w['fail_count']) ?> left</span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php layout_end();
