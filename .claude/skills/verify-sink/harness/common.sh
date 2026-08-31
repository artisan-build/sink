#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2034

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
VERIFY_ROOT="${VERIFY_ROOT:-$HOME/.cache/sink-verify}"
RUNS_DIR="$VERIFY_ROOT/runs"
NODE_MODULES="$VERIFY_ROOT/node_modules"
BROWSERS_DIR="$VERIFY_ROOT/browsers"
CURRENT_RUN_FILE="$VERIFY_ROOT/current-run"

die() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[32mok\033[0m    %s\n' "$*"; }
note() { printf '      %s\n' "$*"; }

git_tree_is_exact_commit() {
	local directory="${1:-$APP_DIR}" status
	status="$(git -C "$directory" status --porcelain=v1 --untracked-files=normal --ignore-submodules=none)" || return 1
	[ -z "$status" ]
}

assert_exact_committed_tree() {
	local directory="${1:-$APP_DIR}"
	git_tree_is_exact_commit "$directory" || die "Application worktree has tracked modifications or untracked files; refusing to verify uncommitted contents."
}

current_run_dir() {
	[ -f "$CURRENT_RUN_FILE" ] || return 1
	local id
	id="$(<"$CURRENT_RUN_FILE")"
	[ -n "$id" ] && [ -d "$RUNS_DIR/$id" ] || return 1
	printf '%s' "$RUNS_DIR/$id"
}

load_run_metadata() {
	local dir
	dir="$(current_run_dir)" || die "No run on record. Run harness/launch.sh first."
	# shellcheck disable=SC1090
	. "$dir/run.env"
	RUN_DIR="$dir"
}

configure_launch_connection() {
	PGHOST_="${VERIFY_PGHOST:-127.0.0.1}"
	PGPORT_="${VERIFY_PGPORT:-5432}"
	PGUSER_="${VERIFY_PGUSER:-postgres}"

	if [ "${VERIFY_PGPASSWORD+x}" = x ]; then
		[ -n "$VERIFY_PGPASSWORD" ] || die "VERIFY_PGPASSWORD was supplied but empty."
		PGPASSWORD_SOURCE=VERIFY_PGPASSWORD
		PGPASS_="$VERIFY_PGPASSWORD"
	else
		PGPASSWORD_SOURCE=builtin-local-default
		PGPASS_=postgres
	fi
}

bind_run_connection() {
	[ -n "${PGHOST_:-}" ] && [ -n "${PGPORT_:-}" ] && [ -n "${PGUSER_:-}" ] || die "Run record has incomplete PostgreSQL connection identity."

	[ "${VERIFY_PGHOST+x}" != x ] || [ "$VERIFY_PGHOST" = "$PGHOST_" ] || die "VERIFY_PGHOST differs from the recorded run host $PGHOST_."
	[ "${VERIFY_PGPORT+x}" != x ] || [ "$VERIFY_PGPORT" = "$PGPORT_" ] || die "VERIFY_PGPORT differs from the recorded run port $PGPORT_."
	[ "${VERIFY_PGUSER+x}" != x ] || [ "$VERIFY_PGUSER" = "$PGUSER_" ] || die "VERIFY_PGUSER differs from the recorded run user $PGUSER_."

	case "${PGPASSWORD_SOURCE:-}" in
		VERIFY_PGPASSWORD)
			[ "${VERIFY_PGPASSWORD+x}" = x ] && [ -n "$VERIFY_PGPASSWORD" ] || die "This run requires the original credential in VERIFY_PGPASSWORD. Re-supply it and rerun; the run record was retained."
			PGPASS_="$VERIFY_PGPASSWORD"
			;;
		builtin-local-default)
			[ "${VERIFY_PGPASSWORD+x}" != x ] || die "This run used the built-in local PostgreSQL credential. Unset VERIFY_PGPASSWORD and rerun."
			PGPASS_=postgres
			;;
		*)
			die "Run record has an unknown PostgreSQL credential reference."
			;;
	esac
}

load_run() {
	load_run_metadata
	bind_run_connection
}

export_run_env() {
	local app_key
	[ -n "${PGPASS_:-}" ] || die "PostgreSQL credential is not bound to the recorded run."
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
