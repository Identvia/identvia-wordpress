#!/usr/bin/env bash
# Local end-to-end run: WordPress (php -S, MariaDB) + this plugin + a real Identvia dev server.
#   IDENTVIA_BASE=http://localhost:8180 WP_DIR=/path/to/wp WP_CLI="php /path/wp-cli.phar" \
#   IDENTVIA_HELPER=/path/to/live/helper.php tests/e2e/run.sh
set -euo pipefail
: "${IDENTVIA_BASE:?}" "${WP_DIR:?}" "${WP_CLI:?}" "${IDENTVIA_HELPER:?}"
WP_URL="${WP_URL:-http://localhost:8190}"
STATE="$(mktemp)"
php "$IDENTVIA_HELPER" setup "$IDENTVIA_BASE" "$STATE" http://localhost:4300/callback \
  "$WP_URL/wp-login.php?action=identvia_callback" "$WP_URL/wp-login.php?loggedout=true"
php "$(dirname "$0")/run-e2e.php" "$WP_URL" "$WP_CLI --path=$WP_DIR" "$STATE" "$IDENTVIA_HELPER"
