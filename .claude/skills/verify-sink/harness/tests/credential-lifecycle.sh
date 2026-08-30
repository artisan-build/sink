#!/usr/bin/env bash

set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/sink-verify-credentials.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

export VERIFY_ROOT="$TEST_ROOT/verify-root"
# shellcheck disable=SC1091
. "$HARNESS_DIR/common.sh"

TEST_PASSWORD='test-only-custom-credential'
FAKE_BIN="$TEST_ROOT/bin"
mkdir -p "$FAKE_BIN"
printf '#!/usr/bin/env bash\nprintf "FAIL test attempted a PostgreSQL command\\n" >&2\nexit 97\n' > "$FAKE_BIN/php"
chmod +x "$FAKE_BIN/php"

fail_test() {
	printf 'FAIL %s\n' "$*" >&2
	exit 1
}

assert_equal() {
	local expected="$1" actual="$2" label="$3"
	[ "$actual" = "$expected" ] || fail_test "$label: expected '$expected', got '$actual'"
}

prepare_run() {
	local run_id='credential_lifecycle_test' run_dir
	run_dir="$RUNS_DIR/$run_id"
	rm -rf "$VERIFY_ROOT"
	mkdir -p "$run_dir/evidence"
	{
		printf 'RUN_ID=%q\n' "$run_id"
		printf 'PORT=\n'
		printf 'BASE_URL=%q\n' 'http://127.0.0.1:1'
		printf 'DB_NAME=%q\n' 'sink_verify_credential_lifecycle_test'
		printf 'GIT_SHA=%q\n' 'test-sha'
		printf 'WORKTREE_PROVENANCE=%q\n' 'exact-commit-v1'
		printf 'EVIDENCE_DIR=%q\n' "$run_dir/evidence"
		printf 'PGHOST_=%q\n' '127.0.0.1'
		printf 'PGPORT_=%q\n' '5432'
		printf 'PGUSER_=%q\n' 'postgres'
		printf 'PGPASSWORD_SOURCE=%q\n' 'VERIFY_PGPASSWORD'
		printf 'SERVER_PID=\nSERVER_LOG_PID=\nWORKER_PID=\n'
	} > "$run_dir/run.env"
	printf '%s' "$run_id" > "$CURRENT_RUN_FILE"
}

assert_retry_state_retained() {
	local expected="$1" run_dir
	run_dir="$RUNS_DIR/credential_lifecycle_test"
	[ -f "$CURRENT_RUN_FILE" ] || fail_test 'cleanup removed current-run after credential rejection'
	assert_equal 'credential_lifecycle_test' "$(<"$CURRENT_RUN_FILE")" 'current-run identity'
	[ -d "$run_dir" ] || fail_test 'cleanup removed the run directory after credential rejection'
	[ -f "$run_dir/run.env" ] || fail_test 'cleanup removed run.env after credential rejection'
	[ -f "$run_dir/evidence/cleanup.log" ] || fail_test 'cleanup did not retain credential rejection evidence'
	assert_equal "$expected" "$(<"$run_dir/evidence/cleanup.log")" 'cleanup evidence message'
	[ ! -e "$run_dir/evidence/cleanup-pids.txt" ] || fail_test 'cleanup reached process cleanup after credential rejection'
}

expect_cleanup_failure() {
	local variable_name="${1:-}" variable_value="${2:-}" expected="$3" output status
	prepare_run
	set +e
	output="$({
		unset VERIFY_PGPASSWORD VERIFY_PGHOST VERIFY_PGPORT VERIFY_PGUSER
		export PATH="$FAKE_BIN:$PATH"
		if [ -n "$variable_name" ]; then
			export VERIFY_PGPASSWORD="$TEST_PASSWORD"
			export "$variable_name=$variable_value"
		fi
		"$HARNESS_DIR/cleanup.sh"
	} 2>&1)"
	status=$?
	set -e
	[ "$status" -ne 0 ] || fail_test 'cleanup accepted missing or conflicting credentials'
	assert_equal "$expected" "$output" 'cleanup rejection message'
	assert_retry_state_retained "$expected"
}

prepare_run
success_output="$({
	unset VERIFY_PGHOST VERIFY_PGPORT VERIFY_PGUSER
	export VERIFY_PGPASSWORD="$TEST_PASSWORD"
	load_run
	[ "$PGPASS_" = "$TEST_PASSWORD" ] || fail_test 'custom credential was not bound'
	printf 'custom credential bound\n'
} 2>&1)"
assert_equal 'custom credential bound' "$success_output" 'custom credential success output'
[[ "$success_output" != *"$TEST_PASSWORD"* ]] || fail_test 'custom credential was printed on success'

missing_message=$'\033[31mFAIL\033[0m  This run requires the original credential in VERIFY_PGPASSWORD. Re-supply it and rerun; the run record was retained.'
expect_cleanup_failure '' '' "$missing_message"
expect_cleanup_failure 'VERIFY_PGHOST' 'db.example.test' $'\033[31mFAIL\033[0m  VERIFY_PGHOST differs from the recorded run host 127.0.0.1.'
expect_cleanup_failure 'VERIFY_PGPORT' '6543' $'\033[31mFAIL\033[0m  VERIFY_PGPORT differs from the recorded run port 5432.'
expect_cleanup_failure 'VERIFY_PGUSER' 'other-user' $'\033[31mFAIL\033[0m  VERIFY_PGUSER differs from the recorded run user postgres.'

if grep -R -F "$TEST_PASSWORD" "$VERIFY_ROOT" >/dev/null 2>&1; then
	fail_test 'custom credential was persisted under VERIFY_ROOT'
fi

printf 'ok custom credential bound without disclosure; missing/conflicting cleanup rejected with retry state retained\n'
