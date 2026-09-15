#!/usr/bin/env bash
# shellcheck disable=SC1091,SC2016

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"

load_run
assert_disposable_database_name
export_run_env
mkdir -p "$EVIDENCE_DIR"
exec > >(tee "$EVIDENCE_DIR/doctor.log") 2>&1

failures=0
check_fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*"; failures=$((failures + 1)); }

descends_from() {
	local pid="$1" ancestor="$2" guard=0
	while [ -n "$pid" ] && [ "$pid" != "0" ] && [ "$pid" != "1" ] && [ "$guard" -lt 16 ]; do
		[ "$pid" = "$ancestor" ] && return 0
		pid="$(ps -o ppid= -p "$pid" 2>/dev/null | tr -d ' ')"
		guard=$((guard + 1))
	done
	return 1
}

session_rows() {
	redis-cli -h 127.0.0.1 -p "$REDIS_PORT_" -a "$REDIS_PASSWORD_" --no-auth-warning dbsize 2>/dev/null || true
}

printf 'run %s -> %s\n\n' "$RUN_ID" "$RUN_DIR"

if kill -0 "$SERVER_PID" 2>/dev/null; then ok "server pid $SERVER_PID alive"; else check_fail "server pid $SERVER_PID is gone"; fi
if kill -0 "$SERVER_LOG_PID" 2>/dev/null; then ok "redacting server-log pid $SERVER_LOG_PID alive"; else check_fail "redacting server-log pid $SERVER_LOG_PID is gone"; fi
if kill -0 "$WORKER_PID" 2>/dev/null; then ok "Redis queue worker pid $WORKER_PID alive"; else check_fail "queue worker pid $WORKER_PID is gone"; fi
if kill -0 "$SCHEDULER_PID" 2>/dev/null; then ok "scheduler pid $SCHEDULER_PID alive"; else check_fail "scheduler pid $SCHEDULER_PID is gone"; fi
if kill -0 "$REDIS_PID" 2>/dev/null; then ok "isolated Redis pid $REDIS_PID alive"; else check_fail "Redis pid $REDIS_PID is gone"; fi
if kill -0 "$AUTHORITY_PID" 2>/dev/null; then ok "managed-authority stub pid $AUTHORITY_PID alive"; else check_fail "authority pid $AUTHORITY_PID is gone"; fi
if docker inspect "$MINIO_CONTAINER" >/dev/null 2>&1; then ok "private MinIO container $MINIO_CONTAINER alive"; else check_fail "MinIO container is gone"; fi

code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/up" 2>/dev/null || true)"
if [ "$code" = "200" ]; then ok "$BASE_URL/up -> 200"; else check_fail "$BASE_URL/up -> ${code:-no response}"; fi

owner_pids="$(lsof -nP -iTCP:"$PORT" -sTCP:LISTEN -t 2>/dev/null | tr '\n' ' ')"
owned=0
for pid in $owner_pids; do
	if descends_from "$pid" "$SERVER_PID"; then owned=$((owned + 1)); else check_fail "listener pid $pid does not descend from server pid $SERVER_PID"; fi
done
if [ "$owned" -gt 0 ] && [ "$owned" -eq "$(printf '%s' "$owner_pids" | wc -w | tr -d ' ')" ]; then
	ok "port $PORT listener tree belongs to server pid $SERVER_PID (${owner_pids% })"
elif [ -z "$owner_pids" ]; then
	check_fail "port $PORT has no listening owner"
fi

head_sha="$(git -C "$APP_DIR" rev-parse HEAD)"
if [ "$head_sha" = "$GIT_SHA" ]; then ok "serving launch SHA $GIT_SHA"; else check_fail "launch SHA $GIT_SHA differs from checkout $head_sha"; fi
if [ "${WORKTREE_PROVENANCE:-}" = "exact-commit-v1" ]; then ok "run metadata requires exact committed-tree provenance"; else check_fail "run metadata does not require exact committed-tree provenance"; fi
if git_tree_is_exact_commit "$APP_DIR"; then ok "serving worktree remains free of tracked modifications and untracked files"; else check_fail "serving worktree has tracked modifications or untracked files"; fi

database_identity="$(php_run -r '
require getenv("APP_DIR_FOR_VERIFY")."/vendor/autoload.php";
$app = require getenv("APP_DIR_FOR_VERIFY")."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$default = Illuminate\Support\Facades\DB::selectOne("select current_database() as name")->name ?? "";
$sink = Illuminate\Support\Facades\DB::connection("sink")->selectOne("select current_database() as name")->name ?? "";
$tables = Illuminate\Support\Facades\DB::connection("sink")->selectOne("select count(*) as count from information_schema.tables where table_schema = ? and table_name = ?", ["public", "messages"])->count ?? 0;
printf("%s|%s|%s", $default, $sink, $tables);
' 2>/dev/null || true)"
IFS='|' read -r default_database sink_database message_table_count <<< "$database_identity"
if [ "$default_database" = "$DB_NAME" ] && [ "$sink_database" = "$DB_NAME" ] && [ "${message_table_count:-0}" -eq 1 ]; then
	ok "default and sink connections identify PostgreSQL database $DB_NAME; messages table present"
else
	check_fail "database identity mismatch: recorded=$DB_NAME default=${default_database:-unreadable} sink=${sink_database:-unreadable} messages_table=${message_table_count:-unreadable}"
fi

sessions_before="$(session_rows)"
curl -sS -o /dev/null -c "$RUN_DIR/.doctor-cookies" "$BASE_URL/bfc/managed/login" 2>/dev/null || true
sessions_after="$(session_rows)"
rm -f "$RUN_DIR/.doctor-cookies"
if [ -n "$sessions_before" ] && [ -n "$sessions_after" ] && [ "$sessions_after" -gt "$sessions_before" ]; then
	ok "serving process wrote a session to isolated Redis ($sessions_before -> $sessions_after)"
else
	check_fail "managed login did not increase isolated Redis keys (${sessions_before:-unreadable} -> ${sessions_after:-unreadable})"
fi

if redis-cli -h 127.0.0.1 -p "$REDIS_PORT_" -a "$REDIS_PASSWORD_" --no-auth-warning ping 2>/dev/null | grep -q PONG; then ok "shared Redis for sessions, cache, and queue is reachable"; else check_fail "Redis is unreachable"; fi

if [ "$LARAVEL_STORAGE_PATH" = "$RUN_DIR/storage" ] && [ "$SESSION_DRIVER" = redis ] && [ "$CACHE_STORE" = redis ] && [ "$QUEUE_CONNECTION" = redis ] && [ "$SINK_QUEUE_CONNECTION" = redis ] && [ "$MAIL_MAILER" = log ] && [ "$FILESYSTEM_DISK" = s3 ] && [ "$SINK_DISK" = s3 ]; then
	ok "production-equivalent drivers forced: Redis state/queue, private S3-compatible storage, log mail"
else
	check_fail "one or more safe driver overrides are missing"
fi

if grep -q '"parsed_at": "' "$EVIDENCE_DIR/ingest.json" 2>/dev/null && grep -q 'ParseMessage.*DONE' "$RUN_DIR/worker.log" 2>/dev/null; then
	ok "ingest object was written to and parsed back from the private S3-compatible bucket"
else
	check_fail "S3-compatible ingest object proof is incomplete"
fi
if grep -q '"path":"/managed-auth/v1/handoffs".*"verdict":"accepted"' "$EVIDENCE_DIR/managed-authority.jsonl" && grep -q 'exchange.*accepted' "$EVIDENCE_DIR/managed-authority.jsonl"; then ok "managed handoff and exchange reached the disposable authority"; else check_fail "managed-auth wire evidence is incomplete"; fi
if grep -q 'method=initialize verdict=passed' "$EVIDENCE_DIR/mcp.log"; then ok "MCP initialize passed over loopback HTTP"; else check_fail "MCP evidence is incomplete"; fi

printf '\n      credential names visible to the application (values never read):\n'
for name in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN REDIS_PASSWORD BUILT_FOR_CLOUD_MANAGED_CLIENT_SECRET POSTMARK_API_KEY RESEND_API_KEY MAIL_USERNAME MAIL_PASSWORD; do
	where=""
	grep -qx "$name" "$RUN_DIR/launched.env" 2>/dev/null && where="run environment"
	if grep -qE "^${name}=.+" "$APP_DIR/.env" 2>/dev/null; then where="${where:+$where + }checkout .env"; fi
	if [ -n "$where" ]; then
		printf '        %-24s SET (%s); safe driver override prevents outbound use\n' "$name" "$where"
	else
		printf '        %-24s absent\n' "$name"
	fi
done

if command -v node >/dev/null 2>&1; then ok "node $(node --version)"; else check_fail "node is not on PATH"; fi
if [ -d "$NODE_MODULES/playwright" ] && NODE_PATH="$NODE_MODULES" PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR" node -e 'const fs=require("fs"); const {chromium}=require("playwright"); process.exit(fs.existsSync(chromium.executablePath()) ? 0 : 1)' 2>/dev/null; then
	ok "Playwright and isolated Chromium are present outside the repository"
else
	check_fail "Playwright/Chromium missing; run harness/install-browser.sh"
fi

printf '\n'
if [ "$failures" -eq 0 ]; then ok "instance is worth driving"; exit 0; fi
die "$failures Doctor check(s) failed; do not drive this instance."
