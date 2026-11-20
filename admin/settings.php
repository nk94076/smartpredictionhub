<?php
require __DIR__ . '/includes/cms-ui.php';
require_perm('settings.manage');

$fields = [
    'General' => [
        'site_name'     => ['Site name', 'text', 'Used in share previews and the browser title of blog posts.'],
        'contact_email' => ['Contact email', 'email', ''],
        'default_og_image' => ['Default share image', 'image', 'Shown when a page is shared on Facebook, X, WhatsApp… and has no image of its own. 1200×630.'],
        'site_noindex'  => ['Hide the whole site from Google (noindex)', 'switch', 'Only for a staging copy — never turn this on for the live site.'],
    ],
    'Announcement bar' => [
        'announce_on'   => ['Show the announcement bar', 'switch', 'A thin bar at the very top of every page.'],
        'announce_text' => ['Text', 'text', 'e.g. “New: compare live odds across 4 platforms”'],
        'announce_link' => ['Link (optional)', 'text', '/markets or https://…'],
        'announce_bg'   => ['Background colour', 'color', ''],
    ],
    'Blog & News' => [
        'blog_title'    => ['Blog page heading', 'text', ''],
        'blog_intro'    => ['Blog page intro', 'textarea', ''],
        'news_title'    => ['News page heading', 'text', ''],
        'news_intro'    => ['News page intro', 'textarea', ''],
        'posts_per_page' => ['Posts per page', 'number', ''],
    ],
    'Markets page' => [
        'markets_heading' => ['Heading on /markets', 'text', 'Empty = “Prediction market odds across every platform”'],
        'markets_intro'   => ['Intro text on /markets', 'textarea', 'Empty = default text'],
    ],
    'Social profiles' => [
        'social_x' => ['X (Twitter)', 'text', 'https://x.com/…'], 'social_linkedin' => ['LinkedIn', 'text', ''], 'social_facebook' => ['Facebook', 'text', ''],
        'social_instagram' => ['Instagram', 'text', ''], 'social_youtube' => ['YouTube', 'text', ''], 'social_telegram' => ['Telegram', 'text', ''],
    ],
    'Tracking & custom code' => [
        'head_code' => ['Code in <head> on every page', 'code', 'Google Analytics, Search Console verification, Meta Pixel… Paste exactly what the service gives you.'],
        'body_code' => ['Code before </body> on every page', 'code', 'Chat widgets and other scripts.'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf('settings.php');
    $changed = [];
    $old = cms_settings();
    foreach ($fields as $group) {
        foreach ($group as $k => [$label, $type]) {
            $v = $type === 'switch' ? (!empty($_POST[$k]) ? '1' : '0') : str_replace("\r\n", "\n", trim((string)($_POST[$k] ?? '')));
            if ($type === 'number') $v = (string)max(1, min(50, (int)$v));
            if ($type === 'color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $v = '#6D4AFF';
            if (in_array($k, ['default_og_image', 'announce_link'], true) && $v !== '' && !cms_safe_url($v)) $v = '';
            if (strpos($k, 'social_') === 0 && $v !== '' && !preg_match('~^https://~i', $v)) $v = '';
            if (($old[$k] ?? '') !== $v) { cms_setting_set($k, $v); $changed[] = $k; }
        }
    }
    cms_publish_cache();
    if ($changed) audit('settings.update', 'settings', null, implode(', ', $changed));
    flash('success', $changed ? 'Settings saved — live now.' : 'No changes.');
    redirect('settings.php');
}

$s = cms_settings();
layout_start('Site Settings', 'settings', 'Site-wide options. Page content is edited under Pages and Header & Footer.');
echo cms_assets();
?>
<form method="post" class="form"><?= csrf_field() ?>
<div class="grid grid-2">
<?php foreach ($fields as $group => $items): ?>
    <div class="card mb" <?= $group === 'Tracking & custom code' ? 'style="grid-column:1/-1"' : '' ?>><div class="card-head"><h2><?= e($group) ?></h2></div><div class="card-body">
    <?php foreach ($items as $k => [$label, $type, $hint]): $v = (string)($s[$k] ?? ''); ?>
        <?php if ($type === 'switch'): ?>
            <div class="field"><label class="switch"><input type="checkbox" name="<?= $k ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
                <?= $hint ? '<div class="hint">' . e($hint) . '</div>' : '' ?></div>
        <?php elseif ($type === 'image'): ?>
            <?= image_field($k, $v, $label) ?><?= $hint ? '<div class="hint" style="margin:-8px 0 14px">' . e($hint) . '</div>' : '' ?>
        <?php else: ?>
            <div class="field"><label class="lbl"><?= e($label) ?></label>
            <?php if ($type === 'textarea' || $type === 'code'): ?>
                <textarea name="<?= $k ?>" rows="<?= $type === 'code' ? 7 : 3 ?>" <?= $type === 'code' ? 'class="mono" spellcheck="false" style="font-size:12.5px"' : '' ?>><?= e($v) ?></textarea>
            <?php else: ?>
                <input type="<?= $type === 'color' ? 'color' : ($type === 'number' ? 'number' : 'text') ?>" name="<?= $k ?>" value="<?= e($v) ?>" <?= $type === 'color' ? 'style="width:70px;height:40px;padding:2px"' : '' ?>>
            <?php endif; ?>
            <?= $hint ? '<div class="hint">' . e($hint) . '</div>' : '' ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div></div>
<?php endforeach; ?>
</div>
<button class="btn btn-primary"><?= icon('check', 16) ?>Save settings</button>
</form>
<?= media_modal() ?>
<?php layout_end();
