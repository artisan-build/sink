#!/usr/bin/env bash

set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
. "$HARNESS_DIR/common.sh"

fail() { printf 'FAIL %s\n' "$*" >&2; exit 1; }

RUN_ID=first_run
bind_run_secrets
first_redis="$REDIS_PASSWORD_"
first_minio="$MINIO_SECRET_KEY_"
RUN_ID=second_run
bind_run_secrets
[ "$first_redis" != "$REDIS_PASSWORD_" ] || fail 'Redis isolation secret did not vary by run'
[ "$first_minio" != "$MINIO_SECRET_KEY_" ] || fail 'MinIO isolation secret did not vary by run'

MINIO_CONTAINER=sink-verify-minio-20260915t000000z-1
MINIO_BUCKET=sink-verify-20260915t000000z-1
assert_disposable_resource_names
MINIO_CONTAINER=unsafe
if (assert_disposable_resource_names) 2>/dev/null; then
	fail 'unsafe MinIO identity was accepted'
fi

for surface in \
	"$APP_DIR/README.md" \
	"$APP_DIR/packages/sink-client/skills/configuring-sink-client/SKILL.md" \
	"$APP_DIR/packages/sink-client/docs/integrate/default.md" \
	"$APP_DIR/.claude/skills/provisioning-sink-on-cloud/SKILL.md" \
	"$APP_DIR/.claude/skills/provisioning-sink-on-cloud/reference/cli-reality.md" \
	"$APP_DIR/.claude/skills/provisioning-sink-on-cloud/reference/resource-plan.md"; do
	if grep -Eq 'FALLBACK_TOKEN|TokenRegistry|token:create|per-token|dual-use' "$surface"; then
		fail "retired credential guidance remains in $surface"
	fi
done

grep -q -- '--purpose=consumption --name=' "$HARNESS_DIR/send-message.sh" || fail 'ingest mint does not use the mapped consumption purpose'
grep -q -- '--purpose=mcp --name=' "$HARNESS_DIR/launch.sh" || fail 'MCP mint does not use the mapped MCP purpose'
if grep -Eq -- '--purpose=(consumption|mcp).*--abilities=' "$HARNESS_DIR/send-message.sh" "$HARNESS_DIR/launch.sh"; then
	fail 'protocol purpose was incorrectly duplicated as an operator ability'
fi

printf 'ok run-unique secrets, destructive-name guards, and supported credential docs\n'
