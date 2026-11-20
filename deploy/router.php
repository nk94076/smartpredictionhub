<?php
/**
 * Local development only: mimics deploy/nginx-cloudpanel.conf for PHP's built-in server.
 *   php -S 127.0.0.1:8080 deploy/router.php
 */
$root = dirname(__DIR__);
$uri = $_SERVER['REQUEST_URI'];
$path = rawurldecode(parse_url($uri, PHP_URL_PATH));
$qs = (string)parse_url($uri, PHP_URL_QUERY);
$deny = function () { http_response_code(404); echo 'Not found'; return true; };
$run = function (string $script, array $get = []) use ($root) {
    $_GET = $get + $_GET;
    $_SERVER['SCRIPT_FILENAME'] = $root . $script;
    $_SERVER['SCRIPT_NAME'] = $script;
    chdir(dirname($root . $script));
    require $root . $script;
    return true;
};

if (preg_match('~^/(app|includes|deploy|\.git)(/|$)|^/(admin|user)/includes/|/\.(?!well-known)|\.(sql|md|log|ini|bak|env|sh|py|zip|lock)$|^/test\.php$~i', $path)) return $deny();
if (preg_match('~^/uploads/.*\.(php|phtml|phar|html?)$~i', $path)) return $deny();

// old URLs -> clean
if (preg_match('~^/(index\.(html|php))$~', $path)) { header('Location: /' . ($qs ? "?$qs" : ''), true, 301); return true; }
if (preg_match('~^/(?!api\.php)((?:reviews/)?[A-Za-z0-9_-]+)\.(php|html)$~', $path, $m) && !in_array($m[1], ['page', 'posts', 'article', 'sitemap'], true)) {
    if ($m[1] === 'event' && preg_match('~^id=([A-Za-z0-9_-]+)$~', $qs, $q)) { header("Location: /event/{$q[1]}", true, 301); return true; }
    if ($m[1] === 'markets' && preg_match('~^category=([a-z]+)$~', $qs, $q)) { header("Location: /markets/{$q[1]}", true, 301); return true; }
    header('Location: /' . $m[1] . ($qs ? "?$qs" : ''), true, 301);
    return true;
}

// clean -> real
if (preg_match('~^/event/([A-Za-z0-9_-]+)/?$~', $path, $m)) return $run('/event.php', ['id' => $m[1]]);
if (preg_match('~^/markets/([a-z]+)/?$~', $path, $m)) return $run('/markets.php', ['category' => $m[1]]);
if (preg_match('~^/(blog|news)/?$~', $path, $m)) return $run('/posts.php', ['type' => $m[1]]);
if (preg_match('~^/(blog|news)/category/([a-z0-9-]+)/?$~', $path, $m)) return $run('/posts.php', ['type' => $m[1], 'category' => $m[2]]);
if (preg_match('~^/(blog|news)/([a-z0-9-]+)/?$~', $path, $m)) return $run('/article.php', ['type' => $m[1], 'slug' => $m[2]]);
if ($path === '/sitemap.xml') return $run('/sitemap.php');
if ($path === '/') return $run('/page.php', ['p' => '/']);

$file = $root . $path;
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) return $run(rtrim($path, '/') . '/index.php');
if (is_file($file)) {
    if (substr($file, -4) === '.php') return $run($path);
    return false; // static asset
}
if (is_file($file . '.php')) return $run($path . '.php');
return $run('/page.php', ['p' => $path]);
