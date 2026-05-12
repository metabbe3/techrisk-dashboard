#!/usr/bin/env bash
# Setup Laravel Reverb for production
# Usage: bash setup-reverb.sh
#
# This script only touches Reverb-related env vars.
# Everything else in .env is left untouched.

set -e

ENV_FILE=".env"

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: .env file not found in current directory."
    exit 1
fi

# Backup
cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d%H%M%S)"
echo "Backed up .env"

# Helper: set a key in .env (update if exists, append if not)
env_set() {
    local key="$1"
    local value="$2"
    local file="$3"

    # Remove any existing line (including commented out)
    sed -i "/^#\?${key}=/d" "$file"
    # Append
    echo "${key}=${value}" >> "$file"
}

# Generate a random string
random_str() {
    openssl rand -hex 16
}

# Detect external host from APP_URL
APP_URL=$(grep -E "^APP_URL=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")
EXTERNAL_HOST=$(echo "$APP_URL" | sed -E 's|https?://||' | sed 's|/.*||')
SCHEME="https"

if [ -z "$EXTERNAL_HOST" ]; then
    EXTERNAL_HOST="localhost"
    SCHEME="http"
    echo "WARNING: Could not detect APP_URL. Using localhost — update VITE_REVERB_HOST manually."
fi

echo ""
echo "Detected APP_URL: ${APP_URL}"
echo "External WebSocket host: ${EXTERNAL_HOST}"
echo "Scheme: ${SCHEME}"
echo ""

# Generate or reuse Reverb credentials
EXISTING_APP_ID=$(grep -E "^REVERB_APP_ID=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")
EXISTING_APP_KEY=$(grep -E "^REVERB_APP_KEY=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")
EXISTING_APP_SECRET=$(grep -E "^REVERB_APP_SECRET=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'")

APP_ID="${EXISTING_APP_ID:-$(random_str)}"
APP_KEY="${EXISTING_APP_KEY:-$(random_str)}"
APP_SECRET="${EXISTING_APP_SECRET:-$(random_str)}"

# Apply changes
env_set "BROADCAST_CONNECTION" "reverb" "$ENV_FILE"

# Backend connects to Docker service
env_set "REVERB_APP_ID" "$APP_ID" "$ENV_FILE"
env_set "REVERB_APP_KEY" "$APP_KEY" "$ENV_FILE"
env_set "REVERB_APP_SECRET" "$APP_SECRET" "$ENV_FILE"
env_set "REVERB_HOST" "reverb" "$ENV_FILE"
env_set "REVERB_PORT" "8080" "$ENV_FILE"
env_set "REVERB_SCHEME" "http" "$ENV_FILE"

# Frontend connects to external host
env_set "VITE_REVERB_APP_KEY" "$APP_KEY" "$ENV_FILE"
env_set "VITE_REVERB_HOST" "$EXTERNAL_HOST" "$ENV_FILE"
env_set "VITE_REVERB_PORT" "8080" "$ENV_FILE"
env_set "VITE_REVERB_SCHEME" "$SCHEME" "$ENV_FILE"

echo "Done! Updated .env with Reverb configuration:"
echo ""
echo "  BROADCAST_CONNECTION=reverb"
echo "  REVERB_HOST=reverb          (backend → Docker)"
echo "  VITE_REVERB_HOST=${EXTERNAL_HOST}   (frontend → external)"
echo "  VITE_REVERB_SCHEME=${SCHEME}"
echo ""
echo "Next steps:"
echo "  1. docker-compose up -d reverb"
echo "  2. npm run build"
echo "  3. docker-compose restart worker"
