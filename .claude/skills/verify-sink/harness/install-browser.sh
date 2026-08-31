#!/usr/bin/env bash
# shellcheck disable=SC1091

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

command -v node >/dev/null 2>&1 || die "node is required for the browser harness."
command -v npm >/dev/null 2>&1 || die "npm is required to install Playwright outside Sink."

mkdir -p "$VERIFY_ROOT" "$BROWSERS_DIR"
npm install --prefix "$VERIFY_ROOT" --no-save playwright
PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR" "$NODE_MODULES/.bin/playwright" install chromium

ok "Playwright installed at $NODE_MODULES"
ok "Chromium installed at $BROWSERS_DIR"
