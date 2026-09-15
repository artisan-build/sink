#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2016,SC2153

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

assert_exact_committed_tree

if existing="$(current_run_dir 2>/dev/null)"; then
	# shellcheck disable=SC1090
	. "$existing/run.env"
	if [ -n "${SERVER_PID:-}" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
		die "A run is already active at $existing. Drive it or run harness/cleanup.sh."
	fi
	note "Cleaning stale run record at $existing."
	"$(dirname "${BASH_SOURCE[0]}")/cleanup.sh" >/dev/null || die "Stale run cleanup failed."
fi

[ -f "$APP_DIR/vendor/autoload.php" ] || die "Dependencies are missing. Run composer install."
configure_launch_connection

RUN_ID="$(date -u +%Y%m%dt%H%M%Sz)_$$"
RUN_DIR="$RUNS_DIR/$RUN_ID"
EVIDENCE_DIR="$RUN_DIR/evidence"
mkdir -p "$EVIDENCE_DIR" "$RUN_DIR/storage/app/private" "$RUN_DIR/storage/framework/cache/data" \
	"$RUN_DIR/storage/framework/sessions" "$RUN_DIR/storage/framework/views" "$RUN_DIR/storage/logs"

PORT=""
for candidate in $(seq "${VERIFY_PORT_FROM:-8199}" "${VERIFY_PORT_TO:-8249}"); do
	if ! lsof -nP -iTCP:"$candidate" -sTCP:LISTEN -t >/dev/null 2>&1; then
		PORT="$candidate"
		break
	fi
done
[ -n "$PORT" ] || die "No free port in ${VERIFY_PORT_FROM:-8199}..${VERIFY_PORT_TO:-8249}."
BASE_URL="http://127.0.0.1:$PORT"
DB_NAME="sink_verify_$RUN_ID"
REDIS_PORT_="$(seq 8399 8449 | while read -r candidate; do lsof -nP -iTCP:"$candidate" -sTCP:LISTEN -t >/dev/null 2>&1 || { printf '%s' "$candidate"; break; }; done)"
MINIO_PORT="$(seq 8499 8549 | while read -r candidate; do lsof -nP -iTCP:"$candidate" -sTCP:LISTEN -t >/dev/null 2>&1 || { printf '%s' "$candidate"; break; }; done)"
AUTHORITY_PORT="$(seq 8599 8649 | while read -r candidate; do lsof -nP -iTCP:"$candidate" -sTCP:LISTEN -t >/dev/null 2>&1 || { printf '%s' "$candidate"; break; }; done)"
[ -n "$REDIS_PORT_" ] && [ -n "$MINIO_PORT" ] && [ -n "$AUTHORITY_PORT" ] || die "A disposable service port range is exhausted."
MINIO_CONTAINER="sink-verify-minio-${RUN_ID//_/-}"
MINIO_BUCKET="sink-verify-${RUN_ID//_/-}"
AUTHORITY_CERT="$RUN_DIR/authority-cert.pem"
AUTHORITY_KEY="$RUN_DIR/authority-key.pem"
RUN_SECRET_PIPE="$RUN_DIR/run-secret.pipe"
RUN_SECRET_="$(openssl rand -hex 32)"
mkfifo "$RUN_SECRET_PIPE"
chmod 600 "$RUN_SECRET_PIPE"
assert_disposable_database_name
assert_disposable_resource_names
bind_run_secrets

php -r '
$dsn = sprintf("pgsql:host=%s;port=%s;dbname=postgres", $argv[1], $argv[2]);
try {
    $pdo = new PDO($dsn, $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identifier = "\"".str_replace("\"", "\"\"", $argv[5])."\"";
    $pdo->exec("CREATE DATABASE {$identifier}");
} catch (Throwable $e) {
    fwrite(STDERR, "cannot create disposable postgres database: ".$e->getMessage()."\n");
    exit(1);
}
' "$PGHOST_" "$PGPORT_" "$PGUSER_" "$PGPASS_" "$DB_NAME" || die "Could not create $DB_NAME."
ok "created PostgreSQL database $DB_NAME"

{
	printf 'RUN_ID=%q\n' "$RUN_ID"
	printf 'PORT=%q\n' "$PORT"
	printf 'BASE_URL=%q\n' "$BASE_URL"
	printf 'DB_NAME=%q\n' "$DB_NAME"
	printf 'GIT_SHA=%q\n' "$(git -C "$APP_DIR" rev-parse HEAD)"
	printf 'WORKTREE_PROVENANCE=%q\n' "exact-commit-v1"
	printf 'EVIDENCE_DIR=%q\n' "$EVIDENCE_DIR"
	printf 'PGHOST_=%q\n' "$PGHOST_"
	printf 'PGPORT_=%q\n' "$PGPORT_"
	printf 'PGUSER_=%q\n' "$PGUSER_"
	printf 'PGPASSWORD_SOURCE=%q\n' "$PGPASSWORD_SOURCE"
	printf 'REDIS_PORT_=%q\nMINIO_PORT=%q\nAUTHORITY_PORT=%q\n' "$REDIS_PORT_" "$MINIO_PORT" "$AUTHORITY_PORT"
	printf 'MINIO_CONTAINER=%q\nMINIO_BUCKET=%q\nAUTHORITY_CERT=%q\n' "$MINIO_CONTAINER" "$MINIO_BUCKET" "$AUTHORITY_CERT"
	printf 'RUN_SECRET_PIPE=%q\n' "$RUN_SECRET_PIPE"
	printf 'SERVER_PID=\nSERVER_LOG_PID=\nWORKER_PID=\nREDIS_PID=\nAUTHORITY_PID=\nSCHEDULER_PID=\nSECRET_PID=\n'
} > "$RUN_DIR/run.env"
printf '%s' "$RUN_ID" > "$CURRENT_RUN_FILE"

successful=0
cleanup_failed_launch() {
	local status=$?
	if [ "$successful" -ne 1 ]; then
		note "Launch failed; cleaning the recorded run."
		"$(dirname "${BASH_SOURCE[0]}")/cleanup.sh" >/dev/null 2>&1 || true
	fi
	return "$status"
}
trap cleanup_failed_launch EXIT

(
	while true; do
		printf '%s\n' "$RUN_SECRET_" > "$RUN_SECRET_PIPE" || exit
	done
) &
SECRET_PID=$!
record_run_pid SECRET_PID "$SECRET_PID"

export_run_env
env -0 | tr '\0' '\n' | perl -ne 'print "$1\n" if /^([A-Z_][A-Z0-9_]*)=/' | sort -u > "$RUN_DIR/launched.env"

printf 'bind 127.0.0.1\nport %s\nsave ""\nappendonly no\nrequirepass %s\n' "$REDIS_PORT_" "$REDIS_PASSWORD_" | redis-server - > "$RUN_DIR/redis.log" 2>&1 &
REDIS_PID=$!
record_run_pid REDIS_PID "$REDIS_PID"
for _ in $(seq 1 30); do redis-cli -h 127.0.0.1 -p "$REDIS_PORT_" -a "$REDIS_PASSWORD_" --no-auth-warning ping 2>/dev/null | grep -q PONG && break; sleep 0.2; done
kill -0 "$REDIS_PID" 2>/dev/null || die "Disposable Redis failed to start."

docker run --rm -d --name "$MINIO_CONTAINER" -p "127.0.0.1:$MINIO_PORT:9000" -e MINIO_ROOT_USER="$MINIO_ACCESS_KEY_" -e MINIO_ROOT_PASSWORD="$MINIO_SECRET_KEY_" minio/minio server /data >/dev/null
for _ in $(seq 1 60); do curl -fsS "http://127.0.0.1:$MINIO_PORT/minio/health/live" >/dev/null 2>&1 && break; sleep 0.25; done
curl -fsS "http://127.0.0.1:$MINIO_PORT/minio/health/live" >/dev/null || die "Disposable MinIO failed to start."
docker run --rm --network "container:$MINIO_CONTAINER" minio/mc alias set verify http://127.0.0.1:9000 "$MINIO_ACCESS_KEY_" "$MINIO_SECRET_KEY_" >/dev/null
docker run --rm --network "container:$MINIO_CONTAINER" minio/mc mb "verify/$MINIO_BUCKET" >/dev/null

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1' -keyout "$AUTHORITY_KEY" -out "$AUTHORITY_CERT" >/dev/null 2>&1
VERIFY_AUTHORITY_PORT="$AUTHORITY_PORT" VERIFY_APP_URL="$BASE_URL" VERIFY_AUTHORITY_SECRET="$AUTHORITY_SECRET_" VERIFY_AUTHORITY_CODE="$AUTHORITY_CODE_" VERIFY_AUTHORITY_EVIDENCE="$EVIDENCE_DIR/managed-authority.jsonl" VERIFY_AUTHORITY_CERT="$AUTHORITY_CERT" VERIFY_AUTHORITY_KEY="$AUTHORITY_KEY" php_run "$(dirname "${BASH_SOURCE[0]}")/authority-stub.php" > "$RUN_DIR/authority.log" 2>&1 &
AUTHORITY_PID=$!
record_run_pid AUTHORITY_PID "$AUTHORITY_PID"
for _ in $(seq 1 30); do curl -ksS "https://127.0.0.1:$AUTHORITY_PORT/not-found" >/dev/null 2>&1 && break; sleep 0.2; done
kill -0 "$AUTHORITY_PID" 2>/dev/null || die "Managed-authority stub failed to start."

cd "$APP_DIR" || die "Cannot enter application directory $APP_DIR."
php_run artisan config:clear --no-interaction >/dev/null
php_run artisan migrate --force --no-interaction > "$RUN_DIR/migrate.log" 2>&1 || die "Migrations failed. See $RUN_DIR/migrate.log."
VERIFY_AUTHORITY_PORT="$AUTHORITY_PORT" php_run "$(dirname "${BASH_SOURCE[0]}")/configure-authority.php" >> "$EVIDENCE_DIR/managed-authority.jsonl"
ok "migrated application and Sink package tables"

ROUTER="$APP_DIR/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
[ -f "$ROUTER" ] || die "Laravel's php -S router is missing at $ROUTER."
export PHP_CLI_SERVER_WORKERS="${VERIFY_SERVER_WORKERS:-4}"

cd "$APP_DIR/public" || die "Cannot enter public directory $APP_DIR/public."
mkfifo "$RUN_DIR/server-log.pipe"
perl -pe 'BEGIN { $| = 1 } s{(/register/)[A-Za-z0-9]{40}}{${1}[REDACTED]}g; s{(/bfc/managed/callback\?)[^ ]+}{${1}[REDACTED]}g' < "$RUN_DIR/server-log.pipe" > "$RUN_DIR/server.log" 2>&1 &
SERVER_LOG_PID=$!
php -d variables_order=EGPCS -S "127.0.0.1:$PORT" "$ROUTER" > "$RUN_DIR/server-log.pipe" 2>&1 &
SERVER_PID=$!
cd "$APP_DIR" || die "Cannot return to application directory $APP_DIR."

php -d variables_order=EGPCS artisan queue:work redis --queue=default --tries=3 --sleep=1 --timeout=90 > "$RUN_DIR/worker.log" 2>&1 &
WORKER_PID=$!
php -d variables_order=EGPCS artisan schedule:work > "$RUN_DIR/scheduler.log" 2>&1 &
SCHEDULER_PID=$!

if sed -i '' -e "s/^SERVER_PID=.*/SERVER_PID=$SERVER_PID/" -e "s/^SERVER_LOG_PID=.*/SERVER_LOG_PID=$SERVER_LOG_PID/" -e "s/^WORKER_PID=.*/WORKER_PID=$WORKER_PID/" -e "s/^REDIS_PID=.*/REDIS_PID=$REDIS_PID/" -e "s/^AUTHORITY_PID=.*/AUTHORITY_PID=$AUTHORITY_PID/" -e "s/^SCHEDULER_PID=.*/SCHEDULER_PID=$SCHEDULER_PID/" "$RUN_DIR/run.env" 2>/dev/null; then
	:
else
	sed -i -e "s/^SERVER_PID=.*/SERVER_PID=$SERVER_PID/" -e "s/^SERVER_LOG_PID=.*/SERVER_LOG_PID=$SERVER_LOG_PID/" -e "s/^WORKER_PID=.*/WORKER_PID=$WORKER_PID/" -e "s/^REDIS_PID=.*/REDIS_PID=$REDIS_PID/" -e "s/^AUTHORITY_PID=.*/AUTHORITY_PID=$AUTHORITY_PID/" -e "s/^SCHEDULER_PID=.*/SCHEDULER_PID=$SCHEDULER_PID/" "$RUN_DIR/run.env"
fi

ready=0
for _ in $(seq 1 60); do
	if [ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/up" 2>/dev/null || true)" = "200" ]; then
		ready=1
		break
	fi
	sleep 0.5
done
[ "$ready" -eq 1 ] || die "Server did not answer $BASE_URL/up. See $RUN_DIR/server.log."

standalone_login_status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/bfc/login" 2>/dev/null || true)"
managed_login_status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/bfc/managed/login?intended=/inbox" 2>/dev/null || true)"
printf 'standalone_login=%s managed_login=%s\n' "$standalone_login_status" "$managed_login_status" > "$EVIDENCE_DIR/authority-http.log"
[ "$standalone_login_status" = 404 ] && [ "$managed_login_status" = 302 ] || die "The serving process did not enforce the disposable managed authority."

php_run artisan sink:maintain --no-interaction > "$EVIDENCE_DIR/scheduler.log" 2>&1 || die "The local scheduler maintenance probe failed."
printf 'scheduler_process=alive maintenance_command=passed\n' >> "$EVIDENCE_DIR/scheduler.log"

if ! mint_output="$(php_run artisan bfc:credential:mint installation sink-verify-installation --kind=bearer --purpose=mcp --name=verify-mcp --local --no-interaction)"; then
	die "The local MCP credential mint failed."
fi
mcp_credential="${mint_output##*shown once: }"
[ -n "$mcp_credential" ] || die "Could not mint disposable MCP credential."
printf 'command=bfc:credential:mint purpose=sink.mcp capability=mcp local=yes verdict=passed\n' >> "$EVIDENCE_DIR/artisan.log"
mcp_response="$(curl -sS -X POST "$BASE_URL/mcp" -H "Authorization: Bearer $mcp_credential" -H 'Accept: application/json, text/event-stream' -H 'Content-Type: application/json' --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"sink-verify","version":"1"}}}')"
[[ "$mcp_response" == *'serverInfo'* ]] || die "MCP initialize proof failed."
printf 'transport=loopback-http method=initialize verdict=passed\n' > "$EVIDENCE_DIR/mcp.log"
unset mcp_credential mcp_response mint_output

"$(dirname "${BASH_SOURCE[0]}")/send-message.sh" --app=verify-source --recipient=recipient@verify.test --subject='Verification message' > "$EVIDENCE_DIR/ingest.json"

printf '%s\n' '[{"goto":"/bfc/managed/login?intended=/inbox"},{"expectUrl":{"contains":"/inbox"}},{"expectText":{"selector":"body","contains":"Inbox"}},{"overflow":false},{"shot":"managed-authenticated-inbox"}]' > "$RUN_DIR/managed-auth.steps.json"
NODE_PATH="$NODE_MODULES" PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR" node "$(dirname "${BASH_SOURCE[0]}")/drive.cjs" --base="$BASE_URL" --out="$EVIDENCE_DIR" --steps="$RUN_DIR/managed-auth.steps.json" --name=managed-auth --viewport=1280x800 --viewport=390x844

printf '\nRUN_DIR=%s\nBASE_URL=%s\nEVIDENCE=%s\n\n' "$RUN_DIR" "$BASE_URL" "$EVIDENCE_DIR"

"$(dirname "${BASH_SOURCE[0]}")/doctor.sh" || die "Doctor rejected the launched instance."
successful=1
trap - EXIT
