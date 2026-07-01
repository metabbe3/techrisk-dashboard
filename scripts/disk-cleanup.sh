#!/usr/bin/env bash
#
# scripts/disk-cleanup.sh — Emergency disk reclaim for the TechRisk stack.
#
# Reclaims disk space consumed by Docker build cache/images/volumes, MySQL
# binary logs, old Laravel logs, and stray manual SQL dumps.
#
# This is SAFE to run because every piece of persistent data in this project is
# bind-mounted (storage/dbdata, storage/redis, storage/backups) — there are NO
# named Docker volumes, so `docker system prune --volumes` cannot delete the
# database, Redis data, or backups. It only removes unreferenced images,
# stopped containers, dangling networks, and orphaned volumes (e.g. anonymous
# volumes left by image layers).
#
# Usage:
#   scripts/disk-cleanup.sh                # interactive — prompts before each step
#   scripts/disk-cleanup.sh --yes          # non-interactive (skip prompts; for automation)
#   scripts/disk-cleanup.sh --wipe-redis   # ALSO wipe Redis data (nuclear — see below)
#
# Flags:
#   --yes / -y     Skip confirmation prompts.
#   --wipe-redis   Stop Redis and delete storage/redis/*. This is the fix for the
#                  corrupted-append-only-file boot-loop from the disk-full incident.
#                  It loses ALL cache, sessions, and queued-job state — use only
#                  when Redis refuses to start because of a corrupted AOF file.
#   --help / -h    Show this help.
#
set -euo pipefail

# --- Resolve project root (parent of scripts/) -------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

# --- Argument parsing --------------------------------------------------------
ASSUME_YES=0
WIPE_REDIS=0

show_help() {
    sed -n '3,27p' "$0"
    exit 0
}

for arg in "$@"; do
    case "$arg" in
        --yes|-y) ASSUME_YES=1 ;;
        --wipe-redis) WIPE_REDIS=1 ;;
        --help|-h) show_help ;;
        *)
            echo "Unknown flag: $arg" >&2
            echo "Run: $0 --help" >&2
            exit 2
            ;;
    esac
done

# --- Helpers -----------------------------------------------------------------
if [ -t 1 ]; then
    BOLD='\033[1m'; RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; NC='\033[0m'
else
    BOLD=''; RED=''; GREEN=''; YELLOW=''; NC=''
fi
log()   { echo -e "${BOLD}$*${NC}"; }
ok()    { echo -e "  ${GREEN}✓${NC} $*"; }
warn()  { echo -e "  ${YELLOW}⚠${NC} $*"; }
err()   { echo -e "  ${RED}✗${NC} $*" >&2; }

disk_usage() { df -h . | awk 'NR==1 || NR==2'; }

confirm() {
    # confirm <prompt>  → returns 0 (yes) or 1 (no). Never aborts under set -e
    # because it is always evaluated in a condition (if/while).
    if [ "$ASSUME_YES" -eq 1 ]; then return 0; fi
    local reply
    read -r -p "  $* [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]]
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || { err "Required command not found: $1"; exit 127; }
}

# Read a single value out of .env without sourcing the whole file (safe against
# values containing shell metacharacters).
env_value() {
    local key="$1" default="${2:-}"
    local val
    val="$(grep -E "^${key}=" .env 2>/dev/null | tail -n1 | cut -d= -f2- || true)"
    echo "${val:-$default}"
}

# --- Preflight ---------------------------------------------------------------
require_cmd docker

log "TechRisk disk cleanup"
echo "  Project: $PROJECT_ROOT"
echo
log "Current disk usage:"
disk_usage
echo

step=0
next_step() { step=$((step + 1)); }

# --- 1. Docker prune ---------------------------------------------------------
next_step
log "${step}. Docker — prune unused images, containers, networks, build cache, orphaned volumes"
if confirm "Run \`docker system prune -a --volumes -f\` and \`docker builder prune -a -f\`?"; then
    docker system prune -a --volumes -f
    docker builder prune -a -f
    ok "Docker pruned"
else
    warn "Skipped Docker prune"
fi
echo

# --- 2. MySQL binary logs ----------------------------------------------------
next_step
log "${step}. MySQL — purge binary logs immediately"
if confirm "Purge MySQL binary logs (PURGE BINARY LOGS BEFORE NOW())?"; then
    if docker compose ps --status=running db >/dev/null 2>&1; then
        DB_USERNAME="$(env_value DB_USERNAME root)"
        DB_PASSWORD="$(env_value DB_PASSWORD)"
        if docker compose exec -T db mysql -u"${DB_USERNAME}" ${DB_PASSWORD:+-p"${DB_PASSWORD}"} \
                -e "PURGE BINARY LOGS BEFORE NOW();" 2>/dev/null; then
            ok "Binary logs purged"
        else
            warn "Could not purge binary logs (is the db container healthy?)"
        fi
    else
        warn "db container not running — start it with \`docker compose up -d db\` first"
    fi
else
    warn "Skipped binary log purge"
fi
echo

# --- 3. Laravel logs ---------------------------------------------------------
next_step
log "${step}. Laravel — remove logs older than 7 days"
if confirm "Delete storage/logs/*.log older than 7 days and truncate legacy laravel.log?"; then
    find storage/logs -type f -name "*.log" -mtime +7 -delete 2>/dev/null || true
    # The pre-rotation single laravel.log (now superseded by daily rotation)
    # can be enormous; shrink it in place, preserving ownership/permissions.
    if [ -f storage/logs/laravel.log ]; then
        legacy_size="$(stat -c %s storage/logs/laravel.log 2>/dev/null || stat -f %z storage/logs/laravel.log 2>/dev/null || echo 0)"
        if [ "$legacy_size" -gt 10485760 ]; then   # > 10 MB
            truncate -s 0 storage/logs/laravel.log 2>/dev/null || : > storage/logs/laravel.log
            ok "Truncated legacy laravel.log (${legacy_size} bytes → 0)"
        fi
    fi
    ok "Old logs removed"
else
    warn "Skipped log cleanup"
fi
echo

# --- 4. Stray manual SQL dumps ----------------------------------------------
next_step
log "${step}. Manual dumps — remove stray *.sql / *.sql.gz older than 7 days (root, backups/, storage/backups/)"
if confirm "Delete old stray SQL dumps?"; then
    find . ./backups ./storage/backups -maxdepth 1 -type f \( -name '*.sql' -o -name '*.sql.gz' \) \
        -mtime +7 -delete 2>/dev/null || true
    ok "Old SQL dumps removed"
else
    warn "Skipped SQL dump cleanup"
fi
echo

# --- 5. Redis wipe (nuclear, opt-in) ----------------------------------------
if [ "$WIPE_REDIS" -eq 1 ]; then
    next_step
    log "${step}. Redis — WIPE data (corrupted AOF recovery)"
    echo -e "  ${RED}This deletes ALL Redis data: cache, sessions, queued jobs.${NC}"
    if confirm "Stop Redis, delete storage/redis/*, then restart Redis?"; then
        docker compose stop redis || true
        # shellcheck disable=SC2115
        rm -rf "${PROJECT_ROOT}/storage/redis/"*
        docker compose start redis || true
        ok "Redis wiped and restarted — monitor \`docker compose logs redis\` for a clean boot"
    else
        warn "Skipped Redis wipe"
    fi
    echo
fi

# --- Summary -----------------------------------------------------------------
log "Done. Disk usage after cleanup:"
disk_usage
echo
log "Tip: arm the weekly auto-prune with: scripts/docker-prune.sh --install"
