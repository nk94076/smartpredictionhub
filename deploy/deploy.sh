#!/usr/bin/env bash
# Update the live site from GitHub. Run on the VPS as the CloudPanel site user:
#   bash deploy/deploy.sh
set -euo pipefail
cd "$(dirname "$0")/.."
git pull --ff-only
mkdir -p app/cache app/sessions
chmod 755 app/cache app/sessions
[ -f app/config.php ] || { echo "app/config.php missing: cp app/config.sample.php app/config.php and fill in the DB details"; exit 1; }
php app/cron/refresh-markets.php -v | tail -n 5
echo "Deployed $(git rev-parse --short HEAD)"
