#!/bin/sh
# Init script of a baked fixture image (ghcr.io/kronixon-pi/connect-ps-fixture): the official image's
# docker_run.sh runs every file in /tmp/init-scripts/ before Apache starts. On an empty database it
# loads the baked dump; on every start it points the shop at $PS_DOMAIN (database and .htaccess).
# Needs a MariaDB reachable as "db" with the credentials the shop was baked with (DB_* below).
set -eu

DB_SERVER="${DB_SERVER:-db}"
DB_USER="${DB_USER:-root}"
DB_PASSWD="${DB_PASSWD:-prestashop}"
DB_NAME="${DB_NAME:-prestashop}"
DOMAIN="${PS_DOMAIN:-localhost:8080}"

i=0
until mysql -h "$DB_SERVER" -u "$DB_USER" -p"$DB_PASSWD" -e 'SELECT 1' >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then echo "kx-restore: database $DB_SERVER not reachable" >&2; exit 1; fi
    sleep 2
done
mysql -h "$DB_SERVER" -u "$DB_USER" -p"$DB_PASSWD" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"

tables="$(mysql -h "$DB_SERVER" -u "$DB_USER" -p"$DB_PASSWD" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")"
if [ "$tables" = "0" ]; then
    echo "kx-restore: loading the baked fixture into $DB_NAME"
    gzip -dc /kx-fixture/db.sql.gz | mysql -h "$DB_SERVER" -u "$DB_USER" -p"$DB_PASSWD" "$DB_NAME"
fi

mysql -h "$DB_SERVER" -u "$DB_USER" -p"$DB_PASSWD" "$DB_NAME" -e "
    UPDATE ps_shop_url SET domain = '$DOMAIN', domain_ssl = '$DOMAIN';
    UPDATE ps_configuration SET value = '$DOMAIN' WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL');"
rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev

# .htaccess names the domain it was written for (every rewrite is conditioned on HTTP_HOST), so friendly
# image URLs of a shop restored for another domain answer 404. PrestaShop writes it again for the new
# domain, as its back office does, as the web server's user so it keeps the file's owner.
# shellcheck disable=SC2016 # PHP code: its $ are PHP's
htaccess='$_SERVER["HTTP_HOST"] = getenv("KX_DOMAIN"); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/";
    define("_PS_ADMIN_DIR_", getcwd() . "/admin-dev");
    require "config/config.inc.php";
    exit(Tools::generateHtaccess() ? 0 : 1);'
if ! (cd /var/www/html && runuser -u www-data -- env KX_DOMAIN="$DOMAIN" php -r "$htaccess" </dev/null); then
    echo "kx-restore: failed to write .htaccess for $DOMAIN" >&2
    exit 1
fi
rm -rf /var/www/html/var/cache/prod /var/www/html/var/cache/dev
echo "kx-restore: shop ready for $DOMAIN"
