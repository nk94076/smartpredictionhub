<?php
/** Admin layout: header (logo + sidebar toggle) + collapsible sidebar + content */

function nav_items(): array
{
    return [
        ['section' => 'Main'],
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard', 'show' => true],
        ['section' => 'Content'],
        ['key' => 'posts-blog', 'label' => 'Blog Posts', 'href' => 'posts.php?type=blog', 'icon' => 'edit', 'show' => can('posts.write') || can('posts.publish')],
        ['key' => 'posts-news', 'label' => 'News', 'href' => 'posts.php?type=news', 'icon' => 'radio', 'show' => can('posts.write') || can('posts.publish')],
        ['key' => 'categories', 'label' => 'Categories', 'href' => 'categories.php', 'icon' => 'list', 'show' => can('posts.categories')],
        ['key' => 'media', 'label' => 'Media Library', 'href' => 'media.php', 'icon' => 'image', 'show' => can('media.manage')],
        ['section' => 'Website'],
        ['key' => 'pages', 'label' => 'Pages', 'href' => 'pages.php', 'icon' => 'file', 'show' => can('pages.edit') || can('seo.manage')],
        ['key' => 'chrome', 'label' => 'Header & Footer', 'href' => 'page-edit.php?p=' . rawurlencode('@chrome'), 'icon' => 'panel', 'show' => can('pages.edit')],
        ['key' => 'markets', 'label' => 'Markets', 'href' => 'markets.php', 'icon' => 'trend', 'show' => can('markets.manage')],
        ['key' => 'redirects', 'label' => 'Redirects', 'href' => 'redirects.php', 'icon' => 'link', 'show' => can('seo.manage')],
        ['key' => 'settings', 'label' => 'Site Settings', 'href' => 'settings.php', 'icon' => 'settings', 'show' => can('settings.manage')],
        ['section' => 'Users'],
        ['key' => 'users', 'label' => 'All Users', 'href' => 'users.php', 'icon' => 'users', 'show' => can('users.view')],
        ['key' => 'sessions', 'label' => 'Login Logs', 'href' => 'sessions.php', 'icon' => 'clock', 'show' => can('sessions.view')],
        ['section' => 'Administration', 'super' => true],
        ['key' => 'admins', 'label' => 'Admins', 'href' => 'admins.php', 'icon' => 'shield', 'show' => is_super()],
        ['key' => 'roles', 'label' => 'Roles & Permissions', 'href' => 'roles.php', 'icon' => 'key', 'show' => is_super()],
        ['section' => 'Security'],
        ['key' => 'activity', 'label' => 'Activity Log', 'href' => 'activity.php', 'icon' => 'list', 'show' => can('audit.view')],
        ['key' => 'ipblocks', 'label' => 'Blocked IPs', 'href' => 'ip-blocks.php', 'icon' => 'ban', 'show' => can('security.view')],
        ['section' => 'Account'],
        ['key' => 'profile', 'label' => 'My Profile', 'href' => 'profile.php', 'icon' => 'settings', 'show' => true],
    ];
}

function layout_start(string $title, string $active, string $subtitle = '', string $actions = ''): void
{
    $a = current_admin();
    $collapsed = ($_COOKIE['sph_sb'] ?? '') === 'c';
    $roleLabel = $a['is_super'] ? 'Super Admin' : ($a['perm_mode'] === 'custom' ? 'Admin · Custom' : ($a['role_name'] ?: 'Admin'));
    $display = $a['name'] ?: $a['username'];

    // Sidebar: hide sections whose items are all hidden
    $items = nav_items();
    $visible = [];
    $pendingSection = null;
    foreach ($items as $it) {
        if (isset($it['section'])) { $pendingSection = $it; continue; }
        if (!$it['show']) { continue; }
        if ($pendingSection) { $visible[] = $pendingSection; $pendingSection = null; }
        $visible[] = $it;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(SITE_NAME) ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="app<?= $collapsed ? ' sb-collapsed' : '' ?>">
<header class="hdr">
    <div class="hdr-left">
        <a class="hdr-brand" href="dashboard.php">
            <span class="brand-icon"><?= icon('logo', 20) ?></span>
            <span class="brand-text"><strong>SMART</strong><small>PREDICTION HUB</small></span>
        </a>
        <button type="button" class="icon-btn" id="sbToggle" aria-label="Toggle sidebar" title="Toggle sidebar"><?= icon('menu', 20) ?></button>
    </div>
    <div class="hdr-right">
        <a class="hdr-link" href="/" target="_blank" rel="noopener"><?= icon('globe', 16) ?><span>View site</span></a>
        <span class="role-pill <?= $a['is_super'] ? 'role-super' : '' ?>"><?= $a['is_super'] ? icon('crown', 13) : icon('shield', 13) ?><?= e($roleLabel) ?></span>
        <details class="user-menu">
            <summary>
                <span class="avatar"><?= e(initials($display)) ?></span>
                <span class="um-name"><?= e($display) ?></span>
                <?= icon('chevron-down', 16) ?>
            </summary>
            <div class="um-pop">
                <div class="um-head">
                    <span class="avatar avatar-lg"><?= e(initials($display)) ?></span>
                    <div><strong><?= e($display) ?></strong><small>@<?= e($a['username']) ?></small></div>
                </div>
                <a href="profile.php"><?= icon('settings', 16) ?>My Profile</a>
                <form method="post" action="logout.php"><?= csrf_field() ?>
                    <button type="submit" class="danger"><?= icon('logout', 16) ?>Logout</button>
                </form>
            </div>
        </details>
    </div>
</header>

<aside class="sidebar" id="sidebar">
    <nav>
        <?php foreach ($visible as $it): ?>
            <?php if (isset($it['section'])): ?>
                <div class="nav-section"><?= e($it['section']) ?></div>
            <?php else: ?>
                <a class="nav-link<?= $active === $it['key'] ? ' active' : '' ?>" href="<?= e($it['href']) ?>" data-tip="<?= e($it['label']) ?>">
                    <?= icon($it['icon'], 19) ?><span><?= e($it['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="logout.php" class="sb-logout"><?= csrf_field() ?>
        <button type="submit" class="nav-link" data-tip="Logout"><?= icon('logout', 19) ?><span>Logout</span></button>
    </form>
</aside>
<div class="sb-overlay" id="sbOverlay"></div>

<main class="content">
    <div class="page-head">
        <div>
            <h1><?= e($title) ?></h1>
            <?php if ($subtitle): ?><p class="muted"><?= $subtitle ?></p><?php endif; ?>
        </div>
        <?php if ($actions): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php foreach (take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>"><?= icon($type === 'success' ? 'check' : 'x', 16) ?><span><?= e($msg) ?></span></div>
    <?php endforeach; ?>
<?php
}

function layout_end(): void
{
    ?>
</main>
<script src="assets/admin.js?v=2"></script>
</body>
</html>
<?php
}
