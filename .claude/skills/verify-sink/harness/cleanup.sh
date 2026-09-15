#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2016

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

current_run_dir >/dev/null 2>&1 || { note "No run on record; nothing to clean up."; exit 0; }
load_run_metadata
mkdir -p "$EVIDENCE_DIR"
exec > >(tee -a "$EVIDENCE_DIR/cleanup.log") 2>&1
bind_run_connection
assert_disposable_resource_names

failures=0

descendants() {
	local pid="$1" child
	for child in $(pgrep -P "$pid" 2>/dev/null || true); do
		descendants "$child"
		printf '%s\n' "$child"
	done
}

kill_tree() {
	local pid="$1" label="$2" victims victim survived=0
	[ -n "$pid" ] || return 0
	victims="$(descendants "$pid") $pid"
	printf '%s %s\n' "$label" "$victims" >> "$EVIDENCE_DIR/cleanup-pids.txt"
	for victim in $victims; do
		kill -0 "$victim" 2>/dev/null || continue
		kill "$victim" 2>/dev/null || true
	done
	sleep 0.5
	for victim in $victims; do
		if kill -0 "$victim" 2>/dev/null; then kill -9 "$victim" 2>/dev/null || true; fi
	done
	sleep 0.2
	for victim in $victims; do
		if kill -0 "$victim" 2>/dev/null; then
			printf '\033[31mFAIL\033[0m  %s pid %s survived cleanup\n' "$label" "$victim"
			failures=$((failures + 1))
			survived=$((survived + 1))
		fi
	done
	if [ "$survived" -eq 0 ]; then
		ok "stopped recorded $label tree (${victims//$'\n'/, })"
	fi
}

: > "$EVIDENCE_DIR/cleanup-pids.txt"
kill_tree "${WORKER_PID:-}" "queue-worker"
kill_tree "${SCHEDULER_PID:-}" "scheduler"
kill_tree "${SERVER_PID:-}" "server"
kill_tree "${SERVER_LOG_PID:-}" "server-log-redactor"
kill_tree "${AUTHORITY_PID:-}" "managed-authority"
kill_tree "${REDIS_PID:-}" "redis"
kill_tree "${SECRET_PID:-}" "secret-keeper"

if [ -n "${PORT:-}" ] && lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
	printf '\033[31mFAIL\033[0m  port %s still has listener pids: %s\n' "$PORT" "$(lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t 2>/dev/null | tr '\n' ' ')"
	failures=$((failures + 1))
else
	ok "port $PORT is free"
fi

if [ -n "${AUTHORITY_PORT:-}" ] && lsof -nP -iTCP:"$AUTHORITY_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
	printf '\033[31mFAIL\033[0m  authority port %s still has a listener\n' "$AUTHORITY_PORT"
	failures=$((failures + 1))
else
	ok "authority port $AUTHORITY_PORT is free"
fi
if [ -n "${REDIS_PORT_:-}" ] && lsof -nP -iTCP:"$REDIS_PORT_" -sTCP:LISTEN -t >/dev/null 2>&1; then
	printf '\033[31mFAIL\033[0m  Redis port %s still has a listener\n' "$REDIS_PORT_"
	failures=$((failures + 1))
else
	ok "Redis port $REDIS_PORT_ is free"
fi

if docker inspect "$MINIO_CONTAINER" >/dev/null 2>&1; then
	docker rm -f "$MINIO_CONTAINER" >/dev/null
fi
if docker inspect "$MINIO_CONTAINER" >/dev/null 2>&1; then
	printf '\033[31mFAIL\033[0m  MinIO container %s survived cleanup\n' "$MINIO_CONTAINER"
	failures=$((failures + 1))
else
	ok "removed exact MinIO container $MINIO_CONTAINER and its run-unique bucket $MINIO_BUCKET"
fi
for _ in $(seq 1 30); do
	lsof -nP -iTCP:"$MINIO_PORT" -sTCP:LISTEN -t >/dev/null 2>&1 || break
	sleep 0.1
done
if lsof -nP -iTCP:"$MINIO_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
	printf '\033[31mFAIL\033[0m  MinIO port %s still has a listener\n' "$MINIO_PORT"
	failures=$((failures + 1))
else
	ok "MinIO port $MINIO_PORT is free"
fi
rm -f "$RUN_DIR/authority-key.pem" "$RUN_DIR/authority-cert.pem" "$RUN_DIR/server-log.pipe" "$RUN_SECRET_PIPE"

if [[ "${DB_NAME:-}" =~ ^sink_verify_[a-z0-9_]+$ ]]; then
	if php -r '
$dsn = sprintf("pgsql:host=%s;port=%s;dbname=postgres", $argv[1], $argv[2]);
try {
    $pdo = new PDO($dsn, $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identifier = "\"".str_replace("\"", "\"\"", $argv[5])."\"";
    $pdo->exec("DROP DATABASE IF EXISTS {$identifier} WITH (FORCE)");
    $check = $pdo->prepare("select count(*) from pg_database where datname = ?");
    $check->execute([$argv[5]]);
    exit((int) $check->fetchColumn() === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
' "$PGHOST_" "$PGPORT_" "$PGUSER_" "$PGPASS_" "$DB_NAME"; then
		ok "dropped and proved absent disposable database $DB_NAME"
	else
		printf '\033[31mFAIL\033[0m  could not drop/prove absent database %s\n' "$DB_NAME"
		failures=$((failures + 1))
	fi
else
	printf '\033[31mFAIL\033[0m  refusing to drop database name "%s"\n' "${DB_NAME:-unset}"
	failures=$((failures + 1))
fi

note "evidence kept at $EVIDENCE_DIR"
printf '      %s\n' "$EVIDENCE_DIR"/* 2>/dev/null || true

if [ "$failures" -ne 0 ]; then die "$failures cleanup check(s) failed; current-run was retained for retry."; fi

if [ -f "$CURRENT_RUN_FILE" ] && [ "$(<"$CURRENT_RUN_FILE")" = "$RUN_ID" ]; then rm -f "$CURRENT_RUN_FILE"; fi
ok "cleanup complete; current-run record removed"
