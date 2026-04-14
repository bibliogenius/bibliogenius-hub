#!/bin/bash
set -e

cd /app

# Install composer dependencies on first boot (or after the named vendor
# volume has been wiped). Dev deps are included so vendor/bin/phpunit is
# available for running tests from the host via `docker compose exec`.
#
# `composer install` is tried first (fast path: lock in sync). If it
# fails because the lock lags composer.json (common when dev deps were
# added without running update on the host), fall back to `update`.
if [ ! -f vendor/autoload.php ]; then
    echo "[dev-entrypoint] vendor/ empty — resolving dependencies..."
    if ! composer install --no-interaction --prefer-dist; then
        echo "[dev-entrypoint] composer install failed (lock likely stale) — retrying with update..."
        composer update --no-interaction --prefer-dist
    fi
fi

# Delegate schema bootstrap + cache warming + FrankenPHP startup to the
# prod entrypoint. It uses Postgres-compatible raw SQL via `dbal:run-sql`
# (see the comment at the top of docker-entrypoint.sh); the Doctrine
# migration files in migrations/ predate the Postgres switch and still
# contain SQLite `AUTOINCREMENT` syntax, so `doctrine:migrations:migrate`
# cannot be used here.
#
# The prod entrypoint reads APP_ENV from the environment, which the
# compose file sets to `dev`.
exec /usr/local/bin/docker-entrypoint.sh
