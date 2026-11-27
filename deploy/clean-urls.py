"""Rewrite internal links to extension-less URLs (/markets, /event/<id>, /blog, /reviews/x).
Usage: python3 deploy/clean-urls.py .   (safe to run again)
nginx maps them back to the real files — see deploy/nginx-cloudpanel.conf."""
import os, re, sys, posixpath

ROOT = sys.argv[1] if len(sys.argv) > 1 else '.'
SKIP_DIRS = {'app', 'api', '.git', 'deploy', 'node_modules'}

def clean_href(href, page_dir):
    m = re.match(r'^(?:https?://(?:www\.)?smartpredictionhub\.com)?([^"#?:]*?)\.(html|php)(\?[^"#]*)?(#[^"]*)?$', href)
    if not m:
        return None
    path, ext, query, frag = m.group(1), m.group(2), m.group(3) or '', m.group(4) or ''
    if not path.startswith('/'):
        # relative link: keep it in the page's folder only if that file really exists there,
        # otherwise it is one of the site-wide header/footer links that mean the site root
        local = posixpath.normpath(posixpath.join('/' + page_dir, path)) if page_dir else '/' + path
        exists = os.path.exists(os.path.join(ROOT, local.lstrip('/') + '.' + ext))
        path = local if (exists or not page_dir) else '/' + posixpath.basename(path)
    if re.match(r'^/(admin|user|api)(/|$)', path) or path in ('/api', '/test'):
        return None
    if ext == 'php' and path not in ('/markets', '/event', '/index'):
        return None
    if posixpath.basename(path) in ('index', 'home'):
        path = '/'
    if path == '/markets':
        c = re.match(r'^\?category=([a-z]+)(?:&(.*))?$', query)
        if c:
            path, query = '/markets/' + c.group(1), ('?' + c.group(2)) if c.group(2) else ''
    if path == '/event':
        c = re.match(r'^\?id=([a-z0-9-]+)$', query)
        if c:
            path, query = '/event/' + c.group(1), ''
    return path + query + frag

def clean_html(html, page_dir=''):
    def sub(m):
        new = clean_href(m.group(2), page_dir)
        return m.group(1) + new + '"' if new is not None else m.group(0)
    return re.sub(r'(href=")([^"]+)"', sub, html)

if __name__ == '__main__':
    changed = 0
    for dirpath, dirs, files in os.walk(ROOT):
        rel = os.path.relpath(dirpath, ROOT).replace(os.sep, '/')
        rel = '' if rel in ('.', 'includes') else rel  # includes/ render at root-level URLs
        dirs[:] = [d for d in dirs if not (rel == '' and d in SKIP_DIRS)]
        for f in files:
            if not f.endswith(('.html', '.php')):
                continue
            p = os.path.join(dirpath, f)
            s = open(p, encoding='utf-8').read()
            n = clean_html(s, rel)
            if n != s:
                open(p, 'w', encoding='utf-8').write(n)
                changed += 1
    print('files changed:', changed)
