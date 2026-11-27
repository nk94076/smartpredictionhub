<?php
/** Shared bits for the CMS admin screens. Include instead of auth.php: require __DIR__ . '/includes/cms-ui.php'; */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../app/cms.php';

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** For fetch() calls: admin + permission + CSRF (form field or X-CSRF header), JSON errors instead of pages. */
function require_json_perm(string $perm, bool $post = true): void
{
    if (!is_logged_in() || !current_admin()) json_out(['error' => 'Your session expired. Reload the page and sign in again.'], 401);
    if (!can($perm)) json_out(['error' => 'You do not have permission for this.'], 403);
    if ($post) {
        $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
            json_out(['error' => 'Request expired. Reload the page and try again.'], 400);
        }
    }
}

function post_status_badge(array $p): string
{
    if ($p['status'] === 'published') {
        return strtotime((string)$p['published_at']) > time()
            ? '<span class="badge b-blue b-dot">Scheduled</span>'
            : '<span class="badge b-green b-dot">Published</span>';
    }
    return $p['status'] === 'pending' ? '<span class="badge b-amber b-dot">In review</span>' : '<span class="badge b-gray b-dot">Draft</span>';
}

function cms_assets(): string
{
    $v = @filemtime(__DIR__ . '/../assets/cms.js') . @filemtime(__DIR__ . '/../assets/cms.css');
    return '<link rel="stylesheet" href="assets/cms.css?v=' . $v . '"><script src="assets/cms.js?v=' . $v . '" defer></script>';
}

/** Media picker dialog used by the rich text editor and image fields (filled by assets/cms.js). */
function media_modal(): string
{
    if (!can('media.manage')) return '<div id="mediaModal" data-disabled="1" hidden></div>';
    return '<div id="mediaModal" class="mm" hidden data-csrf="' . e(csrf_token()) . '">
      <div class="mm-box">
        <div class="mm-head"><h2>Media library</h2>
          <label class="btn btn-primary btn-sm">' . icon('plus', 14) . 'Upload<input type="file" accept="image/*" multiple hidden data-mm-upload></label>
          <button type="button" class="icon-btn" data-mm-close>' . icon('x', 18) . '</button></div>
        <div class="mm-body"><div class="mm-grid" data-mm-grid></div><button type="button" class="btn btn-light btn-block" data-mm-more hidden>Load more</button></div>
        <div class="mm-foot"><input type="text" class="input" placeholder="…or paste an image URL (https://…)" data-mm-url><button type="button" class="btn btn-light" data-mm-use-url>Use URL</button></div>
      </div></div>';
}

/** Image field with preview + "Choose" (media picker). */
function image_field(string $name, ?string $value, string $label): string
{
    return '<div class="field img-field" data-img-field>
        <label class="lbl">' . e($label) . '</label>
        <div class="img-prev" data-img-prev>' . ($value ? '<img src="' . e($value) . '" alt="">' : '<span class="muted small">No image</span>') . '</div>
        <div class="img-actions"><input type="text" name="' . e($name) . '" value="' . e((string)$value) . '" placeholder="/uploads/… or https://…" data-img-input>
        <button type="button" class="btn btn-light btn-sm" data-img-pick>Choose</button>
        <button type="button" class="btn btn-light btn-sm" data-img-clear>Remove</button></div>
    </div>';
}

/** Rich text editor. Content is posted in a hidden <textarea name="$name">. */
function rich_editor(string $name, string $html): string
{
    $b = fn($cmd, $icon, $title) => '<button type="button" data-cmd="' . $cmd . '" title="' . $title . '">' . $icon . '</button>';
    return '<div class="rte" data-rte>
      <div class="rte-bar">
        <select data-cmd="formatBlock" title="Text style"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option><option value="blockquote">Quote</option><option value="pre">Code</option></select>
        ' . $b('bold', '<b>B</b>', 'Bold') . $b('italic', '<i>I</i>', 'Italic') . $b('underline', '<u>U</u>', 'Underline') . $b('strikeThrough', '<s>S</s>', 'Strikethrough') . '
        <span class="rte-sep"></span>
        ' . $b('insertUnorderedList', '• List', 'Bulleted list') . $b('insertOrderedList', '1. List', 'Numbered list') . '
        ' . $b('justifyLeft', '⯇', 'Align left') . $b('justifyCenter', '≡', 'Center') . '
        <span class="rte-sep"></span>
        ' . $b('link', icon('link', 15), 'Insert link') . $b('unlink', 'Unlink', 'Remove link') . $b('image', icon('image', 15), 'Insert image') . $b('embed', '▶ Video', 'Embed YouTube/Vimeo') . $b('table', 'Table', 'Insert table') . $b('insertHorizontalRule', '―', 'Divider') . '
        <span class="rte-sep"></span>
        ' . $b('removeFormat', 'Clear', 'Clear formatting') . $b('undo', '↶', 'Undo') . $b('redo', '↷', 'Redo') . '
        <button type="button" data-cmd="source" class="rte-src" title="Edit HTML">' . icon('code', 15) . ' HTML</button>
      </div>
      <div class="rte-area article-preview" contenteditable="true" data-rte-area></div>
      <textarea name="' . e($name) . '" class="rte-code" data-rte-code hidden>' . e($html) . '</textarea>
    </div>';
}
