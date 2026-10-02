#!/bin/bash
#
# Roll back to the previous (or an explicitly given) release.
# Called over SSH, same pattern as finish-release.sh:
#   bash ~/projects/react-laravel-app/deploy/<env>/rollback.sh <env> [timestamp]
#
# Does not build, does not run composer install, does not touch migrations -
# it only moves the 'current' symlink to an already prepared release.
#
set -euo pipefail

ENV_NAME="${1:?Usage: rollback.sh <production|staging> [timestamp]}"
TARGET_TS="${2:-}"   # optional - without it, rolls back to the release right BEFORE the current one

# ==== SAME VALUES AS IN finish-release.sh - THEY MUST MATCH ===================
CPANEL_USER_HOME="/home/ddweba"
PROJECT_NAME="react-laravel-app"
OPCACHE_RESET_URL=""   # e.g. https://app.example.com/__deploy/opcache-reset?token=XXXX (empty = skip)
# ==============================================================================

PROJECT_ROOT="$CPANEL_USER_HOME/projects/$PROJECT_NAME"
DEPLOY_BASE="$PROJECT_ROOT/deploy/$ENV_NAME"
RELEASES_DIR="$DEPLOY_BASE/releases"
CURRENT_LINK="$DEPLOY_BASE/current"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# --- lock: same .deploy.lock as finish-release.sh, so rollback and deploy never run together ---
mkdir -p "$DEPLOY_BASE"
exec 200>"$DEPLOY_BASE/.deploy.lock"
flock -n 200 || { echo "A deploy/rollback for $ENV_NAME is already running - aborting."; exit 1; }

if [ ! -L "$CURRENT_LINK" ]; then
    echo "ERROR: $CURRENT_LINK does not exist or is not a symlink - there is no active release to roll back." >&2
    exit 1
fi

CURRENT_RELEASE="$(basename "$(readlink -f "$CURRENT_LINK")")"
log "Currently active release: $CURRENT_RELEASE"

if [ -z "$TARGET_TS" ]; then
    # no explicit timestamp: take the release right BEFORE the current one (sorted descending)
    TARGET_TS="$(cd "$RELEASES_DIR" && ls -1 | sort -r | awk -v cur="$CURRENT_RELEASE" '
        found_cur==1 { print; exit }
        $0==cur { found_cur=1 }
    ')"
    if [ -z "$TARGET_TS" ]; then
        echo "ERROR: No older release to roll back to (the current one is the oldest kept)." >&2
        exit 1
    fi
    log "No timestamp given - choosing previous release: $TARGET_TS"
fi

TARGET_RELEASE="$RELEASES_DIR/$TARGET_TS"

if [ ! -d "$TARGET_RELEASE" ]; then
    echo "ERROR: $TARGET_RELEASE does not exist. Available releases:" >&2
    ls -1 "$RELEASES_DIR" >&2
    exit 1
fi

if [ "$TARGET_TS" = "$CURRENT_RELEASE" ]; then
    echo "ERROR: Target release ($TARGET_TS) is already active - nothing to roll back." >&2
    exit 1
fi

log "== ROLLBACK [$ENV_NAME]: $CURRENT_RELEASE -> $TARGET_TS =="

# ATOMIC symlink swap backwards - same mechanism as the forward swap in finish-release.sh
ln -sfn "$TARGET_RELEASE" "$CURRENT_LINK.tmp"
mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
log "current -> $TARGET_RELEASE"

if [ -n "$OPCACHE_RESET_URL" ]; then
    curl -fsS "$OPCACHE_RESET_URL" || log "WARNING: opcache-reset call failed (check manually)"
fi

log "== END of rollback [$ENV_NAME] - success. current now points to $TARGET_TS =="
log "NOTE: migrations are NOT rolled back. If release $CURRENT_RELEASE added a migration,"
log "that column/table still exists in the database - expected and safe thanks to expand/contract."
