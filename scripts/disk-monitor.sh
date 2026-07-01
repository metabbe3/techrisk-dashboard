#!/usr/bin/env bash
#
# scripts/disk-monitor.sh — Early-warning disk usage check.
#
# Prints a warning (and exits non-zero) when the filesystem holding the project
# exceeds the threshold, so you get a heads-up long before hitting 100% — the
# point at which Redis corrupts its AOF and WebSockets crash.
#
# Wire the exit code / stderr into your existing alerting, or schedule it daily
# alongside the prune (see scripts/docker-prune.sh --install).
#
# Usage:
#   scripts/disk-monitor.sh [threshold_percent]   # default 85
#
set -euo pipefail

THRESHOLD="${1:-85}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

# Percent used of the filesystem holding the current directory.
used="$(df -P . | awk 'NR==2 {gsub(/%/, "", $5); print $5}')"

if [ -z "${used:-}" ]; then
    echo "disk-monitor: could not determine disk usage" >&2
    exit 3
fi

if [ "$used" -ge "$THRESHOLD" ]; then
    echo "disk-monitor: WARNING — disk at ${used}% (threshold ${THRESHOLD}%) on $(df -h . | awk 'NR==2 {print $1}'). Reclaim space: scripts/disk-cleanup.sh" >&2
    exit 1
fi

echo "disk-monitor: OK — disk at ${used}% (threshold ${THRESHOLD}%)."
exit 0
