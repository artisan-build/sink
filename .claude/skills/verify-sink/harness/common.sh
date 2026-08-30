#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2034

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
VERIFY_ROOT="${VERIFY_ROOT:-$HOME/.cache/sink-verify}"
RUNS_DIR="$VERIFY_ROOT/runs"
NODE_MODULES="$VERIFY_ROOT/node_modules"
BROWSERS_DIR="$VERIFY_ROOT/browsers"
CURRENT_RUN_FILE="$VERIFY_ROOT/current-run"

PGHOST_="${VERIFY_PGHOST:-127.0.0.1}"
PGPORT_="${VERIFY_PGPORT:-5432}"
PGUSER_="${VERIFY_PGUSER:-postgres}"
PGPASS_="${VERIFY_PGPASSWORD:-postgres}"

die() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[32mok\033[0m    %s\n' "$*"; }
note() { printf '      %s\n' "$*"; }

current_run_dir() {
	[ -f "$CURRENT_RUN_FILE" ] || return 1
	local id
	id="$(<"$CURRENT_RUN_FILE")"
	[ -n "$id" ] && [ -d "$RUNS_DIR/$id" ] || return 1
	printf '%s' "$RUNS_DIR/$id"
}

load_run() {
	local dir
	dir="$(current_run_dir)" || die "No run on record. Run harness/launch.sh first."
	# shellcheck disable=SC1090
	. "$dir/run.env"
	RUN_DIR="$dir"
}

export_run_env() {
	local app_key
	app_key="$(printf 'sink-verify-%s' "$RUN_ID" | openssl dgst -sha256 -binary | base64)"
	export APP_DIR_FOR_VERIFY="$APP_DIR"
	export APP_ENV=local
	export APP_DEBUG=true
	export APP_URL="$BASE_URL"
	export APP_KEY="base64:$app_key"
	export DB_CONNECTION=pgsql
	export DB_URL=""
	export DB_HOST="$PGHOST_"
	export DB_PORT="$PGPORT_"
	export DB_DATABASE="$DB_NAME"
	export DB_USERNAME="$PGUSER_"
	export DB_PASSWORD="$PGPASS_"
	export SINK_DB_HOST="$PGHOST_"
	export SINK_DB_PORT="$PGPORT_"
	export SINK_DB_DATABASE="$DB_NAME"
	export SINK_DB_USERNAME="$PGUSER_"
	export SINK_DB_PASSWORD="$PGPASS_"
	export SESSION_DRIVER=database
	export SESSION_COOKIE="sink_verify_${RUN_ID}"
	export CACHE_STORE=database
	export QUEUE_CONNECTION=database
	export SINK_QUEUE_CONNECTION=database
	export MAIL_MAILER=log
	export FILESYSTEM_DISK=local
	export SINK_DISK=local
	export LOG_CHANNEL=stderr
	export LOG_STACK=stderr
	export LARAVEL_STORAGE_PATH="$RUN_DIR/storage"
	export PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR"
}

php_run() {
	php -d variables_order=EGPCS "$@"
}

assert_disposable_database_name() {
	[[ "${DB_NAME:-}" =~ ^sink_verify_[a-z0-9_]+$ ]] || die "Refusing database '${DB_NAME:-unset}': expected sink_verify_[a-z0-9_]+."
}
