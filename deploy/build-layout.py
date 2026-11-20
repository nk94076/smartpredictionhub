import re, sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import importlib.util
_spec = importlib.util.spec_from_file_location('clean_urls', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'clean-urls.py'))
clean_urls = importlib.util.module_from_spec(_spec); _spec.loader.exec_module(clean_urls)
root = sys.argv[1]
src = open(f'{root}/index.html', encoding='utf-8').read()

def links(html):
    """Point the site's nav/footer at the new live markets pages."""
    html = html.replace('href="home.html"', 'href="/"')
    cat = {'Political Markets': 'politics', 'Sports Markets': 'sports', 'Crypto Markets': 'crypto'}
    def sub_cat(m):
        label = m.group(2)
        return f'href="/markets.php?category={cat[label]}"' + m.group(1) + label
    html = re.sub(r'href="category\.html"((?:(?!</a>).){0,700}?)(Political Markets|Sports Markets|Crypto Markets)', sub_cat, html, flags=re.S)
    # desktop dropdown: add "All live markets" as the first entry
    first = html.find('<a href="/markets.php?category=politics"\n                                            class="flex items-start')
    if first != -1 and 'All live markets' not in html:
        entry = ('<a href="/markets.php"\n                                            class="flex items-start gap-4 p-4 rounded-xl hover:bg-primary/10 transition">\n'
                 '                                            <i class="fa-solid fa-bolt text-accent text-xl mt-1"></i>\n'
                 '                                            <div>\n                                                <h4 class="text-white font-semibold">All live markets</h4>\n'
                 '                                                <p class="text-[#9A94BE] text-sm">Odds compared across every platform</p>\n'
                 '                                            </div>\n                                        </a>\n\n                                        ')
        html = html[:first] + entry + html[first:]
    # mobile menu: same
    mob = html.find('<a href="/markets.php?category=politics" class="block text-[#B8B4D9] hover:text-primary">')
    if mob != -1 and 'All live markets</a>' not in html:
        html = html[:mob] + '<a href="/markets.php" class="block text-[#B8B4D9] hover:text-primary">All live markets</a>\n\n                            ' + html[mob:]
    html = html.replace('https://smartpredictionhub.com/27-tool-volume-dashboard.html', '/markets.php')
    foot = {
        '<a href="/coming-soon.html">Markets</a>': '<a href="/markets.php">Markets</a>',
        '<a href="/coming-soon.html">Politics</a>': '<a href="/markets.php?category=politics">Politics</a>',
        '<a href="/coming-soon.html">Sports</a>': '<a href="/markets.php?category=sports">Sports</a>',
        '>Bitcoin</a>': '>Bitcoin</a>',
        '<a\n                            href="/coming-soon.html">Bitcoin</a>': '<a\n                            href="/markets.php?category=crypto">Bitcoin</a>',
        '<a href="/coming-soon.html">Economy</a>': '<a href="/markets.php?category=economics">Economy</a>',
        '<a\n                            href="/coming-soon.html">Technology</a>': '<a\n                            href="/markets.php?category=tech">Technology</a>',
        '<a href="/coming-soon.html">World Events</a>': '<a href="/markets.php?category=politics">World Events</a>',
        '<a\n                        href="/coming-soon.html">Volume Dashboard</a>': '<a\n                        href="/markets.php?sort=volume24">Volume Dashboard</a>',
    }
    for a, b in foot.items():
        html = html.replace(a, b)
    return html

def live_topbar(html):
    """Replace the static 'Market Pulse' items with a live stats ticker."""
    s = html.find('<!-- Left Ticker -->')
    e = html.find('<!-- Right -->')
    if s == -1 or e == -1 or 'id="mk-ticker"' in html:
        return html
    block = html[s:e]
    # keep the "Market Pulse:" label item, drop the rest
    label_end = block.find('<!-- Item -->', block.find('Market Pulse:'))
    new = block[:label_end] + '<div id="mk-ticker" class="mk-ticker"><span>Loading live market data…</span></div>\n\n                </div>\n\n                '
    html = html[:s] + new + html[e:]
    html = re.sub(r'(Data updated\s*<span class="font-semibold text-white">)\s*8 min ago\s*(</span>)',
                  r'\1<span id="mk-updated">–</span>\2', html)
    return html

out = clean_urls.clean_html(links(live_topbar(src)))  # extension-less URLs
lines = out.split('\n')

def find(pat, start=0):
    for i in range(start, len(lines)):
        if pat in lines[i]:
            return i
    raise SystemExit('not found: ' + pat)

style_end = find('</style>')
body = find('<body>')
hero = find('<section class="relative bg-[#0B0920]">')
# the mobile menu script ends right before the hero comment block
header_end = hero
while '</script>' not in lines[header_end]:
    header_end -= 1
foot_s = find('<footer class="foot">')
foot_e = find('</footer>', foot_s)

head = '\n'.join(lines[:style_end + 1])
head = re.sub(r'<title>.*?</title>', '<title><?= htmlspecialchars($pageTitle ?? "Smart Prediction Hub", ENT_QUOTES) ?></title>\n'
              '    <meta name="description" content="<?= htmlspecialchars($pageDescription ?? "", ENT_QUOTES) ?>">\n'
              '    <?php if (!empty($canonical)): ?><link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES) ?>"><?php endif; ?>', head, count=1)
top = ("<?php if (!defined('SPH_APP')) { http_response_code(404); exit; } ?>\n"
       '<?php /* Shared page top, generated from index.html by deploy/build-layout.py — edit index.html, then re-run. */ ?>\n'
       + head + '\n    <link rel="stylesheet" href="/assets/markets.css?v=<?= @filemtime(__DIR__ . "/../assets/markets.css") ?>">\n</head>\n'
       + '\n'.join(lines[body:header_end + 1]) + '\n')
bottom = ("<?php if (!defined('SPH_APP')) { http_response_code(404); exit; } ?>\n"
          '<?php /* Shared page bottom, generated from index.html by deploy/build-layout.py. */ ?>\n'
          + '\n'.join(lines[foot_s:foot_e + 1])
          + '\n    <script src="/assets/markets.js?v=<?= @filemtime(__DIR__ . "/../assets/markets.js") ?>" defer></script>\n</body>\n</html>\n')
open(f'{root}/includes/layout-top.php', 'w', encoding='utf-8').write(top)
open(f'{root}/includes/layout-bottom.php', 'w', encoding='utf-8').write(bottom)

if '--write-index' in sys.argv:
    open(f'{root}/index.html', 'w', encoding='utf-8').write(out)
print('ok', len(top.splitlines()), len(bottom.splitlines()))
