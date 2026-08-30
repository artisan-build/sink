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
	php -r '
try {
    $pdo = new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo (int) $pdo->query("select count(*) from sessions")->fetchColumn();
} catch (Throwable) {
    echo "";
}
'
}

printf 'run %s -> %s\n\n' "$RUN_ID" "$RUN_DIR"

if kill -0 "$SERVER_PID" 2>/dev/null; then ok "server pid $SERVER_PID alive"; else check_fail "server pid $SERVER_PID is gone"; fi
if kill -0 "$WORKER_PID" 2>/dev/null; then ok "database queue worker pid $WORKER_PID alive"; else check_fail "queue worker pid $WORKER_PID is gone"; fi

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
curl -sS -o /dev/null -c "$RUN_DIR/.doctor-cookies" "$BASE_URL/login" 2>/dev/null || true
sessions_after="$(session_rows)"
rm -f "$RUN_DIR/.doctor-cookies"
if [ -n "$sessions_before" ] && [ -n "$sessions_after" ] && [ "$sessions_after" -gt "$sessions_before" ]; then
	ok "serving process wrote a session to $DB_NAME ($sessions_before -> $sessions_after)"
else
	check_fail "anonymous /login did not increase sessions in $DB_NAME (${sessions_before:-unreadable} -> ${sessions_after:-unreadable})"
fi

queue_counts="$(php -r '
try {
    $pdo = new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    printf("%d|%d", (int) $pdo->query("select count(*) from jobs")->fetchColumn(), (int) $pdo->query("select count(*) from failed_jobs")->fetchColumn());
} catch (Throwable) {
    exit(1);
}
' 2>/dev/null || true)"
IFS='|' read -r queued_count failed_count <<< "$queue_counts"
if [ -n "$queued_count" ] && [ -n "$failed_count" ]; then ok "database queue readable: queued=$queued_count failed=$failed_count"; else check_fail "queue tables are unreadable"; fi

if [ "$LARAVEL_STORAGE_PATH" = "$RUN_DIR/storage" ] && [ "$SESSION_DRIVER" = database ] && [ "$CACHE_STORE" = database ] && [ "$QUEUE_CONNECTION" = database ] && [ "$SINK_QUEUE_CONNECTION" = database ] && [ "$MAIL_MAILER" = log ] && [ "$FILESYSTEM_DISK" = local ] && [ "$SINK_DISK" = local ]; then
	ok "safe drivers forced: PostgreSQL-backed state, database queue, local run storage, log mail"
else
	check_fail "one or more safe driver overrides are missing"
fi

printf '\n      credential names visible to the application (values never read):\n'
for name in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN POSTMARK_API_KEY RESEND_API_KEY MAIL_USERNAME MAIL_PASSWORD FALLBACK_TOKEN; do
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
