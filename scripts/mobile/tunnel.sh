#!/usr/bin/env bash
# Opens a temporary public HTTPS tunnel (a cloudflared quick tunnel) to the stack's proxy and
# prints its URL. The Storefront app on a simulator or emulator reaches the stack through it:
# iOS App Transport Security only allows HTTPS to unknown hosts, and a macOS runner has no
# route to the Linux runner that hosts the stack.
#
#   URL="$(./scripts/mobile/tunnel.sh)"     # leaves cloudflared running in the background
#
# Start it BEFORE up.sh and pass the URL as APP_URL, so storage (product image) URLs point at
# the tunnel too. Quick tunnels need no account; they last as long as the process.
set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${HTTP_PORT:-80}"
LOG=".tunnel.log"

if ! command -v cloudflared >/dev/null 2>&1; then
    echo "::error::cloudflared is not installed" >&2
    exit 1
fi

: >"$LOG"
nohup cloudflared tunnel --no-autoupdate --url "http://localhost:${PORT}" >"$LOG" 2>&1 &
echo $! >.tunnel.pid

for _ in $(seq 1 60); do
    URL="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -n1 || true)"
    if [[ -n "$URL" ]]; then
        printf '%s\n' "$URL"
        exit 0
    fi
    sleep 1
done

echo "::error::cloudflared did not report a tunnel URL within 60s" >&2
tail -n 20 "$LOG" >&2 || true
exit 1
