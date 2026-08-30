#!/usr/bin/env bash
# shellcheck disable=SC1091

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"
load_run
export_run_env
export APP_DIR_FOR_VERIFY="$APP_DIR"
php_run "$(dirname "${BASH_SOURCE[0]}")/send-message.php" "$@"
