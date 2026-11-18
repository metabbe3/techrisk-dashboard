#!/usr/bin/env bash
#
# scripts/storage-find.sh — READ-ONLY storage sweep report.
#
# Finds what is eating the disk so you can decide what to sweep: the biggest
# directories and files, files not modified in N days, unused Docker images
# (what `docker-prune.sh` would remove), and journal/docker overhead.
#
# It deletes NOTHING — the destructive half already lives in
#   scripts/disk-cleanup.sh    (interactive reclaim, incl. MySQL binlogs/logs)
#   scripts/docker-prune.sh    (weekly prune + crontab install)
# Run this first, look, then run those.
#
# Usage:
#   scripts/storage-find.sh                    # project + /var/log + /tmp
#   scripts/storage-find.sh /var /opt          # sweep additional paths too
#   scripts/storage-find.sh --days=30 --top=20 # defaults shown
#
set -euo pipefail

DAYS=30
TOP=20
EXTRA_PATHS=()
for arg in "$@"; do
    case "$arg" in
        --days=*) DAYS="${arg#--days=}" ;;
        --top=*)  TOP="${arg#--top=}" ;;
        --help|-h) sed -n '3,18p' "$0"; exit 0 ;;
        -*) echo "Unknown flag: $arg (try --help)" >&2; exit 2 ;;
        *)  EXTRA_PATHS+=("$arg") ;;
    esac
done

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -t 1 ]; then BOLD=$'\033[1m'; NC=$'\033[0m'; else BOLD=''; NC=''; fi
sect() { echo; echo "${BOLD}== $* ==${NC}"; }

# --- 1. Disk + Docker overhead ----------------------------------------------
sect "Disk"
df -h . | awk 'NR==1 || NR==2'
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    echo
    docker system df
else
    echo "(docker not running — image sections skipped)"
fi

# --- 2. Biggest directories in the project -----------------------------------
sect "Biggest directories under project (1 level)"
du -xh -d1 "$PROJECT_ROOT" 2>/dev/null | sort -rh | head -"$TOP"

# --- 3. Unused Docker images (prune candidates) ------------------------------
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    sect "Unused Docker images (safe to prune — no container references them)"
    used="$(docker ps -a --format '{{.Image}}' | sort -u)"
    # An image is "used" if a container names its repo:tag OR its ID (images
    # pulled by digest show only the ID in ps). Dangling (<none>) are always
    # unused — they are the classic build-cache leftovers.
    docker images --format '{{.Repository}}:{{.Tag}} {{.ID}} {{.Size}}' | while read -r repotag id size; do
        case "$repotag" in
            "<none>:<none>") echo "DANGLING  $id  $size" ;;
            *)  if ! printf '%s\n' "$used" | grep -qxF "$repotag" && \
                ! printf '%s\n' "$used" | grep -qxF "$id"; then
                    echo "UNUSED    $repotag  ($id)  $size"
                fi ;;
        esac
    done | sort
    echo
    echo "(reclaim: scripts/disk-cleanup.sh, or scripts/docker-prune.sh for the weekly cron)"
fi

# --- 4. Old, large files ------------------------------------------------------
sect "Files >10 MB not modified in $DAYS days (excluding system dirs that must not be touched)"
roots=("$PROJECT_ROOT" /var/log /tmp "${EXTRA_PATHS[@]}")
for root in "${roots[@]}"; do
    [ -d "$root" ] || continue
    echo "-- $root"
    # -xdev stays on one filesystem (no descending into /proc or bind mounts);
    # prune the live app data dirs — files there are referenced by the DB.
    find "$root" -xdev \( -path "$PROJECT_ROOT/storage/dbdata" -o -path "$PROJECT_ROOT/storage/redis" -o -path "$PROJECT_ROOT/storage/backups" \) -prune \
        -o -type f -size +10M -mtime +"$DAYS" -printf '%10s  %TY-%Tm-%Td  %p\n' 2>/dev/null | sort -rn | head -"$TOP" || true
done

# --- 5. Journal ---------------------------------------------------------------
if command -v journalctl >/dev/null 2>&1; then
    sect "systemd journal"
    journalctl --disk-usage 2>/dev/null || true
    echo "(reclaim: journalctl --vacuum-time=14d)"
fi

echo
echo "Report only — nothing was deleted."
