<?php
/** JSON endpoints for the media library: ?action=list|upload|delete */
require __DIR__ . '/includes/cms-ui.php';

const MEDIA_MAX_BYTES = 8 * 1024 * 1024;
const MEDIA_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    require_json_perm('media.manage', false);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 40;
    $rows = q('SELECT id, path, original_name, alt FROM cms_media ORDER BY id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per))->fetchAll();
    $more = count($rows) > $per;
    json_out(['more' => $more, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'url' => $r['path'], 'name' => $r['original_name'] ?: basename($r['path']), 'alt' => $r['alt']], array_slice($rows, 0, $per))]);
}

if ($action === 'upload') {
    require_json_perm('media.manage');
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $err = (int)($f['error'] ?? 4);
        json_out(['error' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'File is too large for the server upload limit.' : 'No file received.'], 400);
    }
    if ($f['size'] > MEDIA_MAX_BYTES) json_out(['error' => 'Images must be 8 MB or smaller.'], 400);
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !isset(MEDIA_TYPES[$info[2]])) json_out(['error' => 'Only JPG, PNG, GIF and WebP images can be uploaded.'], 400);
    $ext = MEDIA_TYPES[$info[2]];
    [$w, $h] = $info;

    $sub = date('Y/m');
    $dir = SPH_ROOT . '/uploads/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) json_out(['error' => 'The uploads folder is not writable on the server.'], 500);
    $base = cms_slugify(pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $name = substr($base, 0, 60) . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = "$dir/$name";

    // Very large photos are scaled down to 2000px wide (GD); everything else is stored as uploaded.
    $saved = false;
    if ($w > 2000 && $ext !== 'gif' && function_exists('imagecreatetruecolor')) {
        $src = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$ext];
        $im = function_exists($src) ? @$src($f['tmp_name']) : false;
        if ($im) {
            $nh = (int)round($h * 2000 / $w);
            $out = imagecreatetruecolor(2000, $nh);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $im, 0, 0, 0, 0, 2000, $nh, $w, $h);
            $saved = $ext === 'jpg' ? imagejpeg($out, $dest, 84) : ($ext === 'png' ? imagepng($out, $dest, 7) : imagewebp($out, $dest, 84));
            if ($saved) { $w = 2000; $h = $nh; }
        }
    }
    if (!$saved && !move_uploaded_file($f['tmp_name'], $dest)) json_out(['error' => 'Could not save the file.'], 500);
    @chmod($dest, 0644);

    $path = "/uploads/$sub/$name";
    q('INSERT INTO cms_media (path, original_name, mime, size, width, height, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$path, mb_substr((string)$f['name'], 0, 255), $info['mime'], filesize($dest), $w, $h, current_admin()['id']]);
    $id = (int)db()->lastInsertId();
    audit('media.upload', 'media', $id, $path);
    json_out(['id' => $id, 'url' => $path, 'name' => $f['name'], 'width' => $w, 'height' => $h]);
}

if ($action === 'delete') {
    require_json_perm('media.manage');
    $m = q('SELECT * FROM cms_media WHERE id = ?', [(int)($_POST['id'] ?? 0)])->fetch();
    if (!$m) json_out(['error' => 'Not found'], 404);
    $file = realpath(SPH_ROOT . $m['path']);
    if ($file && strpos($file, realpath(SPH_ROOT . '/uploads') . '/') === 0) @unlink($file);
    q('DELETE FROM cms_media WHERE id = ?', [$m['id']]);
    audit('media.delete', 'media', $m['id'], $m['path']);
    json_out(['ok' => true]);
}

json_out(['error' => 'Unknown action'], 400);
