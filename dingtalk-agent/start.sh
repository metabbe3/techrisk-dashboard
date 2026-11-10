#!/bin/sh
# Easy start: build + run the DingTalk agent from this directory.
# Usage: ./start.sh            (reads dingtalk-agent.env if present)
#        LOG=/tmp/agent.log ./start.sh
set -e
cd "$(dirname "$0")"

if [ -f dingtalk-agent.env ]; then
    set -a
    . ./dingtalk-agent.env
    set +a
fi

echo "[start.sh] building..."
go build -o agent ./cmd/agent

echo "[start.sh] starting agent (Ctrl+C to stop)"
exec ./agent
