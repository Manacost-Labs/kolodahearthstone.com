#!/bin/sh
set -eu

production_root=/var/www/koloda/data/www/kolodahearthstone.ru
staging_root=/var/www/koloda/data/www/test.kolodahearthstone.com
production_database=kldhs
staging_database=kldhs_stage
staging_user=kldhs_stage
backup_root=/var/backups/kolodahearthstone-staging
repo_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
guard_source="$repo_root/ops/staging/koloda-staging-guard.php"

if [ "${1:-}" != "--apply" ] || [ "$(id -u)" -ne 0 ]; then
    echo "Usage: sudo $0 --apply" >&2
    exit 2
fi
if [ "$production_root" = "$staging_root" ] || [ "$production_database" = "$staging_database" ]; then
    echo "Safety check failed." >&2
    exit 1
fi
if [ ! -f "$production_root/wp-config.php" ] || [ ! -f "$guard_source" ]; then
    echo "Expected production configuration or staging guard is missing." >&2
    exit 1
fi

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$staging_root" "$backup_root"
staging_database_exists=0
if mariadb -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$staging_database'" | grep -qx "$staging_database"; then
    staging_database_exists=1
    mariadb-dump --single-transaction --quick "$staging_database" | gzip -1 > "$backup_root/$staging_database-$timestamp.sql.gz"
fi

touch "$staging_root/.staging-refresh"
trap 'rm -f "$staging_root/.staging-refresh"' EXIT HUP INT TERM

rsync -a --delete \
    --exclude='/wp-config.php' \
    --exclude='/wp-content/uploads/***' \
    --exclude='/wp-content/cache/***' \
    --exclude='/wp-content/wflogs/***' \
    --exclude='/wp-content/upgrade/***' \
    --exclude='/wp-content/ai1wm-backups/***' \
    "$production_root/" "$staging_root/"

staging_config_created=0
if [ ! -f "$staging_root/wp-config.php" ]; then
    cp "$production_root/wp-config.php" "$staging_root/wp-config.php"
    staging_config_created=1
fi

staging_password=''
if [ "$staging_database_exists" -eq 0 ] || [ "$staging_config_created" -eq 1 ]; then
    staging_password=$(openssl rand -hex 32)
    if [ "$staging_database_exists" -eq 0 ]; then
        mariadb -e "CREATE DATABASE \`$staging_database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    fi
    mariadb -e "CREATE USER IF NOT EXISTS '$staging_user'@'localhost' IDENTIFIED BY '$staging_password'; ALTER USER '$staging_user'@'localhost' IDENTIFIED BY '$staging_password'; GRANT ALL PRIVILEGES ON \`$staging_database\`.* TO '$staging_user'@'localhost'; FLUSH PRIVILEGES;"
fi
sudo -u koloda wp --path="$staging_root" config set DB_NAME "$staging_database" --type=constant --quiet
sudo -u koloda wp --path="$staging_root" config set DB_USER "$staging_user" --type=constant --quiet
sudo -u koloda wp --path="$staging_root" config set DB_HOST "localhost" --type=constant --quiet
if [ -n "$staging_password" ]; then
    sudo -u koloda wp --path="$staging_root" config set DB_PASSWORD "$staging_password" --type=constant --quiet
fi
sudo -u koloda wp --path="$staging_root" config set WP_ENVIRONMENT_TYPE staging --type=constant --quiet
sudo -u koloda wp --path="$staging_root" config set WP_REDIS_PREFIX "khs_stage_" --type=constant --quiet
sudo -u koloda wp --path="$staging_root" config set WP_REDIS_DATABASE 1 --type=constant --raw --quiet
rm -f \
    "$staging_root/wp-content/mu-plugins/manacost-cache-purge.php" \
    "$staging_root/wp-content/mu-plugins/plausible-analytics.php" \
    "$staging_root/wp-content/object-cache.php" \
    "$staging_root/wp-content/advanced-cache.php"
install -o koloda -g koloda -m 0644 "$guard_source" "$staging_root/wp-content/mu-plugins/00-khs-staging-guard.php"
mkdir -p "$staging_root/wp-content/uploads" "$staging_root/wp-content/cache" "$staging_root/wp-content/wflogs"
chown -R koloda:koloda "$staging_root"
find "$staging_root/wp-content/cache" -type d -exec chmod 0755 {} +
find "$staging_root/wp-content/cache" -type f -exec chmod 0644 {} +
find "$staging_root/wp-content/uploads" -type d -exec chmod 0755 {} +
find "$staging_root/wp-content/uploads" -type f -exec chmod 0644 {} +

mariadb-dump --single-transaction --quick --routines --triggers --events "$production_database" | mariadb "$staging_database"
sudo -u koloda wp --path="$staging_root" search-replace 'https://kolodahearthstone.com' 'https://test.kolodahearthstone.com' --all-tables-with-prefix --skip-columns=guid --precise --quiet
sudo -u koloda wp --path="$staging_root" search-replace 'https://kolodahearthstone.ru' 'https://test.kolodahearthstone.com' --all-tables-with-prefix --skip-columns=guid --precise --quiet
sudo -u koloda wp --path="$staging_root" search-replace 'http://kolodahearthstone.com' 'https://test.kolodahearthstone.com' --all-tables-with-prefix --skip-columns=guid --precise --quiet
sudo -u koloda wp --path="$staging_root" search-replace 'http://kolodahearthstone.ru' 'https://test.kolodahearthstone.com' --all-tables-with-prefix --skip-columns=guid --precise --quiet
sudo -u koloda wp --path="$staging_root" option update home 'https://test.kolodahearthstone.com' --quiet
sudo -u koloda wp --path="$staging_root" option update siteurl 'https://test.kolodahearthstone.com' --quiet
sudo -u koloda wp --path="$staging_root" option update blog_public 0 --quiet
sudo -u koloda wp --path="$staging_root" cache flush >/dev/null 2>&1 || true

rm -f "$staging_root/.staging-refresh"
trap - EXIT HUP INT TERM
echo "KolodaHearthstone staging refreshed. Backup: $backup_root/$staging_database-$timestamp.sql.gz"
