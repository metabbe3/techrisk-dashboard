#!/bin/sh
# Easy start: build + run the DingTalk agent from this directory.
# Usage: ./start.sh            (reads dingtalk-agent.env if present)
#        LOG=/tmp/agent.log ./start.sh
set -e
cd "$(dirname "$0")"

# The env file is read by config.Load itself — shell-sourcing it here mangled
# $ and backticks inside secrets, and sourced values won over the app's own
# parsing.

echo "[start.sh] building..."
go build -o agent ./cmd/agent

echo "[start.sh] starting agent (Ctrl+C to stop)"
exec ./agent
