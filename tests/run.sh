#!/usr/bin/env bash
# Lint, dataset e test end-to-end delle API con una finta API Anthropic.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
APP_PORT="${APP_PORT:-8765}"
MOCK_PORT="${MOCK_PORT:-8766}"
PIDS=()
cleanup() {
    for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null || true; done
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "== php -l"
find "$ROOT" -name '*.php' -not -path '*/.git/*' -print0 | xargs -0 -n1 php -l > /dev/null
find "$ROOT" -name '.*.php' -not -path '*/.git/*' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== dataset"
php "$ROOT/tests/validate-data.php"

echo "== api"
# Copia dell'app con una config di test: non tocca .config.php né data/ del repo.
mkdir -p "$WORK/app"
cp -R "$ROOT/index.html" "$ROOT/app.js" "$ROOT/api" "$ROOT/data" "$WORK/app/"
rm -f "$WORK/app/data/leaderboard.json" "$WORK/app/data/.ratelimit.json"
cat > "$WORK/app/.config.php" <<PHP
<?php
if (!defined('ENTRY_POINT')) die('Direct access not allowed');
define('ANTHROPIC_API_KEY', 'test-key');
define('ANTHROPIC_MODEL', 'claude-haiku-5-5');
define('ANTHROPIC_API_URL', 'http://127.0.0.1:$MOCK_PORT/');
define('ANTHROPIC_API_VERSION', '2023-06-01');
define('RATE_LIMIT_OPEN_PER_IP_HOUR', 12);
define('RATE_LIMIT_OPEN_GLOBAL_DAY', 1000);
define('RATE_LIMIT_RUNS_PER_IP_HOUR', 1000);
PHP

export MOCK_CALLS_FILE="$WORK/mock-calls"
php -S "127.0.0.1:$MOCK_PORT" "$ROOT/tests/mock-anthropic.php" > "$WORK/mock.log" 2>&1 &
PIDS+=($!)
php -S "127.0.0.1:$APP_PORT" -t "$WORK/app" > "$WORK/app.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 50); do
    curl -s -o /dev/null "http://127.0.0.1:$APP_PORT/index.html" && curl -s -o /dev/null "http://127.0.0.1:$MOCK_PORT/" && break
    sleep 0.1
done

APP_URL="http://127.0.0.1:$APP_PORT" APP_DIR="$WORK/app" php "$ROOT/tests/api-test.php" || {
    echo "--- app.log"; tail -n 30 "$WORK/app.log"; exit 1;
}
