#!/bin/sh
# Web pages + PHP API. Signaling runs on Supabase Realtime, so there is no
# long-running server of our own any more.
# Several workers so a slow Solana RPC call doesn't block every other request.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -S "0.0.0.0:${PORT:-8000}" -t /app /app/router.php
