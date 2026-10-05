#!/bin/sh
# Runs before Apache on every container start (so on every deploy):
# checks the essential settings, prepares the data folders, applies any
# pending database migrations and, in the background, looks up the Twitch
# category of games that do not have one yet.
set -eu

cd /var/www/html

case "${APP_KEY:-}" in
    ''|CHANGE_ME*)
        echo "StreamOrg: APP_KEY is not set. Generate one with: openssl rand -base64 32" >&2
        echo "           (it encrypts credentials and key vaults: keep it safe, never change it)" >&2
        exit 1
        ;;
esac

if [ -z "${DATABASE_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    echo "StreamOrg: set DATABASE_URL (or DB_HOST, DB_NAME, DB_USER, DB_PASSWORD)." >&2
    exit 1
fi

# Volumes may be mounted empty or owned by root.
mkdir -p public/media tmp/sessions
chown -R www-data:www-data public/media tmp

# The database may still be starting: retry for about half a minute.
tries=0
until php bin/migrate.php; do
    tries=$((tries + 1))
    if [ "$tries" -ge 10 ]; then
        echo "StreamOrg: migrations failed; not starting." >&2
        exit 1
    fi
    echo "StreamOrg: database not ready, retrying in 3s…" >&2
    sleep 3
done

php bin/twitch_categories.php || true &

exec "$@"
