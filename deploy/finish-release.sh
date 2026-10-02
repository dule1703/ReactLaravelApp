#!/bin/bash
#
# Finishes a deploy on the server. The new release has already been unpacked
# into releases/<timestamp> by the GitHub Actions "upload build" step.
#
# Called over SSH from GitHub Actions as:
#   bash ~/projects/react-laravel-app/deploy/<env>/finish-release.sh <env> <timestamp>
#
set -euo pipefail

ENV_NAME="${1:?Usage: finish-release.sh <production|staging> <timestamp>}"
TS="${2:?Usage: finish-release.sh <production|staging> <timestamp>}"

# ==== ADJUST THESE VALUES FOR YOUR ACCOUNT ===================================
CPANEL_USER_HOME="/home/ddweba"           # confirmed: echo $HOME in cPanel Terminal
PROJECT_NAME="react-laravel-app"          # namespace per project -> projects/<PROJECT_NAME>/...
PHP_BIN="/usr/local/bin/php"              # VERIFY: must be PHP >= 8.2 (cPanel > Select PHP Version)
COMPOSER_BIN="/usr/local/bin/composer"    # verify: which composer (cPanel Terminal)
OPCACHE_RESET_URL=""                      # e.g. https://app.example.com/__deploy/opcache-reset?token=XXXX (empty = skip)
# ==============================================================================

# Inode budget: every release with vendor/ is ~15-25k inodes on shared hosting.
case "$ENV_NAME" in
    production) KEEP_RELEASES=5 ;;
    staging)    KEEP_RELEASES=3 ;;
    *) echo "ERROR: unknown environment '$ENV_NAME' (expected production|staging)." >&2; exit 1 ;;
esac

PROJECT_ROOT="$CPANEL_USER_HOME/projects/$PROJECT_NAME"
DEPLOY_BASE="$PROJECT_ROOT/deploy/$ENV_NAME"
RELEASES_DIR="$DEPLOY_BASE/releases"
SHARED_DIR="$DEPLOY_BASE/shared"
CURRENT_LINK="$DEPLOY_BASE/current"
NEW_RELEASE="$RELEASES_DIR/$TS"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# --- lock: prevents two deploys/rollbacks of the same env from overlapping ---
mkdir -p "$DEPLOY_BASE"
exec 200>"$DEPLOY_BASE/.deploy.lock"
flock -n 200 || { echo "A deploy for $ENV_NAME is already running - aborting."; exit 1; }

log "== FINISHING deploy [$ENV_NAME] -> release $TS =="

if [ ! -d "$NEW_RELEASE" ]; then
    echo "ERROR: $NEW_RELEASE does not exist - the upload step in GitHub Actions failed before this script." >&2
    exit 1
fi

if [ ! -f "$SHARED_DIR/.env" ]; then
    echo "ERROR: $SHARED_DIR/.env does not exist. Create it manually before the first deploy." >&2
    exit 1
fi

cd "$NEW_RELEASE"

# 1) link shared resources (storage/ and .env are NOT part of the upload - they live on the server)
rm -rf storage
ln -s "$SHARED_DIR/storage" storage
ln -sf "$SHARED_DIR/.env" .env

# 2) PHP dependencies (vendor/ is not uploaded - installed here, with the server's PHP version)
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-progress

# 3) public/storage symlink inside the NEW release (must be repeated every time)
"$PHP_BIN" artisan storage:link

# 4) migrations - must be backward compatible (expand/contract), no maintenance mode
"$PHP_BIN" artisan migrate --force

# 5) caches
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

# 6) ATOMIC symlink swap current -> new release
ln -sfn "$NEW_RELEASE" "$CURRENT_LINK.tmp"
mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
log "current -> $NEW_RELEASE"

# 7) opcache reset (CLI and the LSPHP web pool have SEPARATE opcache - CLI migrate does not clear it)
if [ -n "$OPCACHE_RESET_URL" ]; then
    curl -fsS "$OPCACHE_RESET_URL" || log "WARNING: opcache-reset call failed (check manually)"
fi

# 8) cleanup of old releases
cd "$RELEASES_DIR"
ls -1 | sort -r | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
    log "removing old release: $old"
    rm -rf -- "$old"
done

log "== END of deploy [$ENV_NAME] - success =="
