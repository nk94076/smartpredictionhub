<?php
/** Permission checkbox matrix (used by both the Role and Admin edit screens) */
function render_perm_matrix(array $checked, string $idPrefix = 'p'): void
{
    foreach (PERMISSIONS as $group => $perms):
        $gid = $idPrefix . preg_replace('/[^a-z]/', '', strtolower($group)); ?>
        <div class="perm-group">
            <div class="perm-group-head">
                <span><?= e($group) ?></span>
                <label><input type="checkbox" data-group-toggle="<?= e($gid) ?>"> Select all</label>
            </div>
            <?php foreach ($perms as $key => [$label, $desc]): ?>
                <label class="perm-item">
                    <input type="checkbox" name="perms[]" value="<?= e($key) ?>" data-group="<?= e($gid) ?>" <?= in_array($key, $checked, true) ? 'checked' : '' ?>>
                    <span><strong><?= e($label) ?><code><?= e($key) ?></code></strong><small><?= e($desc) ?></small></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach;
}
