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
6. **Vhost** — Site → Vhost: paste `deploy/nginx-cloudpanel.conf` above the `location ~ \.php$` block.
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
