#!/usr/bin/env bash
# Paste this entire file into Forge's Deploy Script editor. Forge expands the
# macros below; this template must NOT be invoked with `bash scripts/forge-deploy.sh`.
# Reference: https://laravel.com/forge/docs/sites/deployments
#
# One-time Forge setup:
# - Create a Laravel site with zero-downtime deployments enabled. Existing
#   standard-deployment sites need a new site configured for this strategy.
# - Use PHP 8.2 with pdo_pgsql and the extensions required by composer.lock,
#   Composer 2, and Node 24. Configure PostgreSQL and production credentials.
# - Share .env (Forge's default) and storage. Do not share bootstrap/cache or
#   public/build. Point Nginx at the Forge-managed current/public directory.
# - Set APP_ENV=production, APP_DEBUG=false, APP_URL, SESSION_SECURE_COOKIE=true,
#   DB_CONNECTION=central, SESSION_CONNECTION=central, DB_CACHE_CONNECTION=central,
#   and a persistent APP_KEY in Forge's environment editor before first deploy.
#   Keep database sessions/cache/queues, or configure persistent Redis stores.
# - Configure Forge queue workers against current/artisan, with enough shutdown
#   time to finish jobs, and schedule:run every minute against current/artisan.
# - Enable Forge deployment health checks for /up and retain previous releases.
#
# Availability contract: migrations must work with BOTH old and new code and
# avoid long blocking database locks. Use expand/contract migrations and perform
# large backfills separately. Existing till-removal migrations are destructive:
# do not deploy them while an older release still uses those tables/columns.
# A failed migration can leave database changes behind; release rollback does
# not undo them. Never run migrate:fresh, seed demo data, or rotate APP_KEY here.

set -eo pipefail

$CREATE_RELEASE()

cd "$FORGE_RELEASE_DIRECTORY"

: "${FORGE_PHP:?Forge must provide the site PHP binary}"
: "${FORGE_COMPOSER:?Forge must provide Composer}"
: "${FORGE_SITE_PATH:?Forge must provide the current site path}"

if [[ ! -L .env || ! -f .env || ! -L storage || ! -d storage ]]; then
    echo 'Configure shared .env and storage in Forge before deploying.' >&2
    exit 1
fi

if [[ -L bootstrap/cache || -L public/build ]]; then
    echo 'bootstrap/cache and public/build must be local to each release.' >&2
    exit 1
fi

# Use a release-local view cache: view:cache clears its destination, so sharing
# compiled views would otherwise affect requests still using the active release.
export VIEW_COMPILED_PATH="$FORGE_RELEASE_DIRECTORY/bootstrap/cache/views"
mkdir -p "$VIEW_COMPILED_PATH"

"$FORGE_COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
"$FORGE_COMPOSER" check-platform-reqs --no-dev

# Vite is a devDependency and is required on the build host even in production.
# Preserve the repository's ignore-scripts policy and install optional binaries.
npm ci --include=dev --include=optional --ignore-scripts --no-audit --no-fund
npm run build
test -s public/build/manifest.json
test ! -e public/hot

# Keep hashed chunks available for pages opened before the release switch.
# Copy after Vite builds (it empties the build directory); never replace new
# assets or the new manifest. This accumulates old assets across releases;
# prune only once the supported lifetime of open browser sessions has elapsed.
if [[ -d "$FORGE_SITE_PATH/public/build/assets" ]]; then
    mkdir -p public/build/assets
    cp -an "$FORGE_SITE_PATH/public/build/assets/." public/build/assets/
fi

"$FORGE_PHP" artisan storage:link --no-interaction
"$FORGE_PHP" artisan config:cache --no-interaction
"$FORGE_PHP" artisan route:cache --no-interaction
"$FORGE_PHP" artisan event:cache --no-interaction
"$FORGE_PHP" artisan view:cache --no-interaction

# Central migrations already apply the project's updates to existing tenants.
# Failure must stop activation; do not use --graceful or suppress exit codes.
"$FORGE_PHP" artisan migrate --database=central --force --no-interaction

# Forge atomically switches current only after all preparation succeeds.
# PHP-FPM reload and maintenance mode are unnecessary for this strategy.
$ACTIVATE_RELEASE()

$RESTART_QUEUES()
