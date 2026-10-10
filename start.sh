#!/bin/sh
# Runs both halves of Wavr in one container (keeps Railway usage to a single service):
#   - signaling WebSocket server on $SIGNAL_INTERNAL_PORT (default 8080), auto-restarted if it crashes
#   - web pages + PHP API on $PORT (Railway sets this)
SIGNAL_INTERNAL_PORT="${SIGNAL_INTERNAL_PORT:-8080}"

(while true; do
  php signaling/server.php "$SIGNAL_INTERNAL_PORT"
  echo "[start.sh] signaling server exited, restarting in 2s..." >&2
  sleep 2
done) &

# Several workers so a slow Solana RPC call doesn't block every other request.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -S "0.0.0.0:${PORT:-8000}" -t /app /app/router.php
