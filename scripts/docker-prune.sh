#!/usr/bin/env bash
#
# scripts/docker-prune.sh — Weekly Docker prune, with self-installing crontab.
#
# Keeps Docker from hoarding build cache, old images, and orphaned volumes —
# the ~57 GB culprit from the disk-full incident. Safe because this project
# keeps all persistent data on bind mounts (no named Docker volumes), so
# `--volumes` only clears orphaned/anonymous volumes, never the DB/Redis/backups.
#
# Usage:
#   scripts/docker-prune.sh              # run the prune now (this is what cron calls)
#   scripts/docker-prune.sh --install    # install the Sunday 3:00 AM crontab entry (idempotent)
#   scripts/docker-prune.sh --uninstall  # remove the crontab entry
#   scripts/docker-prune.sh --help
#
set -euo pipefail

SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
PROJECT_ROOT="$(cd "$(dirname "${SCRIPT_PATH}")/.." && pwd)"
LOG_FILE="${PROJECT_ROOT}/storage/logs/docker-prune.log"

# Sunday 03:00, matching the incident postmortem ("every Sunday at 3:00 AM").
# Minute is off the :00 mark to avoid stampeding the host alongside other jobs.
CRON_SCHEDULE="57 2 * * 0"
CRON_ENTRY="${CRON_SCHEDULE} ${SCRIPT_PATH} >> ${LOG_FILE} 2>&1"
MARKER="# techrisk-docker-prune"

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || { echo "Required command not found: $1" >&2; exit 127; }
}

do_prune() {
    require_cmd docker
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] docker-prune start" | tee -a "$LOG_FILE" >/dev/null
    docker system prune -a --volumes -f | tee -a "$LOG_FILE"
    docker builder prune -a -f | tee -a "$LOG_FILE"
    docker image prune -a -f | tee -a "$LOG_FILE" >/dev/null
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] docker-prune done" | tee -a "$LOG_FILE" >/dev/null
}

do_install() {
    require_cmd crontab
    mkdir -p "$(dirname "$LOG_FILE")"

    local current
    current="$(crontab -l 2>/dev/null || true)"

    if echo "$current" | grep -qF "$MARKER"; then
        echo "Crontab entry already installed:"
        echo "  $CRON_ENTRY"
        return 0
    fi

    # Append the marker + entry to the existing crontab without disturbing
    # anything the user already has.
    {
        echo "$current"
        echo "$MARKER"
        echo "$CRON_ENTRY"
    } | crontab -

    echo "Installed weekly Docker prune into crontab:"
    echo "  schedule : $CRON_SCHEDULE (Sun 02:57)"
    echo "  command  : $SCRIPT_PATH"
    echo "  log      : $LOG_FILE"
    echo
    echo "Verify with:   crontab -l | grep techrisk-docker-prune"
}

do_uninstall() {
    require_cmd crontab
    if ! crontab -l 2>/dev/null | grep -qF "$MARKER"; then
        echo "No techrisk docker-prune entry found in crontab."
        return 0
    fi
    crontab -l 2>/dev/null | grep -vF "$MARKER" | grep -vF "$SCRIPT_PATH" | crontab -
    echo "Removed techrisk docker-prune entry from crontab."
}

show_help() {
    sed -n '3,15p' "$0"
    exit 0
}

case "${1:-run}" in
    run)            do_prune ;;
    --install)      do_install ;;
    --uninstall)    do_uninstall ;;
    --help|-h)      show_help ;;
    *)
        echo "Unknown argument: $1" >&2
        echo "Usage: $0 [--install|--uninstall|--help]" >&2
        exit 2
        ;;
esac
