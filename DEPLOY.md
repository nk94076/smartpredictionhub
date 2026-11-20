# Deploy on the VPS (CloudPanel)

The site is PHP 8.1+ (nginx + PHP-FPM via CloudPanel) with a MySQL/MariaDB database.
Live market data is fetched by a cron job into `app/cache/` — no database needed for it.

## 1. One-time setup

1. **DNS** — point `smartpredictionhub.com` (A record, and `www`) to the VPS IP.
2. **CloudPanel → Add Site → Create a PHP Site**
   - Domain: `smartpredictionhub.com`, PHP 8.3, pick a site user (example below: `sph`).
   - Document root becomes `/home/sph/htdocs/smartpredictionhub.com`.
3. **SSL** — Site → SSL/TLS → *New Let's Encrypt Certificate*.
4. **Database** — Site → Databases → *Add Database* (name, user, password).
   Import the old data: Databases → *Import* the `.sql` dump (or phpMyAdmin from the same page).
5. **Code** — SSH in as the site user and clone into the document root:
   ```bash
   ssh sph@SERVER_IP
   cd ~/htdocs
   rm -rf smartpredictionhub.com && git clone https://github.com/nk94076/smartpredictionhub.git smartpredictionhub.com
   cd smartpredictionhub.com
   cp app/config.sample.php app/config.php
   nano app/config.php        # DB_NAME / DB_USER / DB_PASS from step 4, a new random SETUP_KEY
   mkdir -p app/cache app/sessions && chmod 755 app/cache app/sessions
   php app/cron/refresh-markets.php -v   # first data load, takes 1–3 minutes
   ```
6. **Vhost** — Site → Vhost: paste `deploy/nginx-cloudpanel.conf` right after the `root` line, before every other `location` block.
   nginx ignores `.htaccess`, so without this `app/`, `includes/` and the sessions folder are readable.
7. **Cron** — Site → Cron Jobs → Add:
   - Schedule: `*/5 * * * *`
   - Command: `php8.3 /home/sph/htdocs/smartpredictionhub.com/app/cron/refresh-markets.php`
8. **Admin** — open `https://smartpredictionhub.com/admin/` and sign in with the existing admin
   (imported with the database). Only for a fresh database: run `/admin/setup.php`, then delete it.

## 2. Updating the site

```bash
ssh sph@SERVER_IP
cd ~/htdocs/smartpredictionhub.com && bash deploy/deploy.sh
```

## 3. Live market data — how it works

| Piece | File |
| --- | --- |
| Platform fetchers (Polymarket, Kalshi, Limitless, PredictIt) | `app/markets/sources.php` |
| Cross-platform matching, consensus, summaries, sparklines | `app/markets/aggregate.php` |
| Settings (pages fetched, Kalshi proxy, categories) | `app/markets/config.php` |
| Cron entry point | `app/cron/refresh-markets.php` |
| JSON API used by the pages | `api/markets.php`, `api/event.php` |
| Pages | `markets.php` (all events), `event.php?id=…` (one event), homepage widget in `index.html` |
| Front end | `assets/markets.js`, `assets/markets.css` |

- **Kalshi** blocks some datacenter IPs. The aggregator tries `api.elections.kalshi.com` first and falls
  back to the Cloudflare worker in `kalshi-worker.js` (URL in `MK_KALSHI_BASES`).
- If cron stops, the API refreshes stale data itself after answering a request (PHP-FPM only).
- Check it: `php app/cron/refresh-markets.php -v` prints how many events each platform returned.

## 4. Header / footer of the new pages

`markets.php` and `event.php` use `includes/layout-top.php` and `includes/layout-bottom.php`,
generated from `index.html`. After changing the header or footer in `index.html`, run:

```bash
python3 deploy/build-layout.py .
```

## 5. CMS (Admin → Content / Website)

Everything on the site is managed from `/admin/`:

| Section | What it does | Permission |
| --- | --- | --- |
| Blog Posts / News | Write, schedule, publish posts (`/blog/<slug>`, `/news/<slug>`). Writers send drafts for review. | `posts.write`, `posts.publish` |
| Categories | Blog/news topics (`/blog/category/<slug>`) | `posts.categories` |
| Media Library | Image uploads (`/uploads/`, images only, >2000px scaled down) | `media.manage` |
| Pages | Visual editor + HTML editor for every designed page, hide pages, new CMS pages, revisions | `pages.edit` |
| Header & Footer | One shared header/footer for every page | `pages.edit` |
| SEO (per page), Redirects | Titles, descriptions, share images, noindex, 301 redirects | `seo.manage` |
| Markets | Hide / pin / rename markets, platforms on/off, refresh now | `markets.manage` |
| Site Settings | Announcement bar, social links, analytics/tracking code, blog & markets texts | `settings.manage` |

Only a **Super Admin** creates admins (Admins) and roles (Roles & Permissions). Ready-made roles:
*Content Editor*, *Writer*, *Website Manager* (plus Manager / Support / Viewer for users).

How it is wired:

- `page.php` serves every designed `.html` page and CMS page: CMS redirects → hidden pages → CMS pages → the
  (CMS-edited copy of the) `.html` file, with the shared header/footer, SEO tags and announcement bar applied.
- The public site reads only files in `app/cache/cms/` (written by `cms_publish_cache()` in `app/cms.php` on every
  save), so pages keep working even if MySQL is down. Blog/news pages read MySQL.
- Visual editor: `admin/page-edit.php` → iframe `admin/page-render.php` → `admin/page-save.php`; engine in
  `ve_*()` in `app/cms.php`. Edits are applied to the original HTML at exact positions; every save keeps a revision.
- Default header/footer: `includes/site-header.html` / `site-footer.html` (built from `index.html` by
  `deploy/build-layout.py`). Edited copies win.
- After `git pull`, run `php app/cron/migrate.php` (deploy.sh does it) to create new tables.
- Local test server with the same URL rules as nginx: `php -S 127.0.0.1:8080 deploy/router.php`.
