#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2016,SC2153

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

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
assert_disposable_database_name

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

cat > "$RUN_DIR/run.env" <<ENV
RUN_ID=$RUN_ID
PORT=$PORT
BASE_URL=$BASE_URL
DB_NAME=$DB_NAME
GIT_SHA=$(git -C "$APP_DIR" rev-parse HEAD)
EVIDENCE_DIR=$EVIDENCE_DIR
SERVER_PID=
WORKER_PID=
ENV
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

export_run_env
env -0 | tr '\0' '\n' | perl -ne 'print "$1\n" if /^([A-Z_][A-Z0-9_]*)=/' | sort -u > "$RUN_DIR/launched.env"

cd "$APP_DIR" || die "Cannot enter application directory $APP_DIR."
php_run artisan config:clear --no-interaction >/dev/null
php_run artisan migrate --force --no-interaction > "$RUN_DIR/migrate.log" 2>&1 || die "Migrations failed. See $RUN_DIR/migrate.log."
ok "migrated application and Sink package tables"

ROUTER="$APP_DIR/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
[ -f "$ROUTER" ] || die "Laravel's php -S router is missing at $ROUTER."
export PHP_CLI_SERVER_WORKERS="${VERIFY_SERVER_WORKERS:-4}"

cd "$APP_DIR/public" || die "Cannot enter public directory $APP_DIR/public."
php -d variables_order=EGPCS -S "127.0.0.1:$PORT" "$ROUTER" > "$RUN_DIR/server.log" 2>&1 &
SERVER_PID=$!
cd "$APP_DIR" || die "Cannot return to application directory $APP_DIR."

php -d variables_order=EGPCS artisan queue:work database --queue=default --tries=3 --sleep=1 --timeout=90 > "$RUN_DIR/worker.log" 2>&1 &
WORKER_PID=$!

if sed -i '' -e "s/^SERVER_PID=.*/SERVER_PID=$SERVER_PID/" -e "s/^WORKER_PID=.*/WORKER_PID=$WORKER_PID/" "$RUN_DIR/run.env" 2>/dev/null; then
	:
else
	sed -i -e "s/^SERVER_PID=.*/SERVER_PID=$SERVER_PID/" -e "s/^WORKER_PID=.*/WORKER_PID=$WORKER_PID/" "$RUN_DIR/run.env"
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

printf '\nRUN_DIR=%s\nBASE_URL=%s\nEVIDENCE=%s\n\n' "$RUN_DIR" "$BASE_URL" "$EVIDENCE_DIR"

"$(dirname "${BASH_SOURCE[0]}")/doctor.sh" || die "Doctor rejected the launched instance."
successful=1
trap - EXIT
