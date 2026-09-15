#!/usr/bin/env bash
# shellcheck disable=SC1091

. "$(dirname "${BASH_SOURCE[0]}")/common.sh"
load_run
export_run_env
export APP_DIR_FOR_VERIFY="$APP_DIR"
mint_output="$(php_run "$APP_DIR/artisan" bfc:credential:mint installation sink-verify-installation --kind=bearer --purpose=consumption --abilities=consumption --name="${1#--app=}" --local --no-interaction)"
export VERIFY_INGEST_CREDENTIAL="${mint_output##*shown once: }"
[ -n "$VERIFY_INGEST_CREDENTIAL" ] || die "bfc:credential:mint did not return a one-time ingest credential."
printf 'command=bfc:credential:mint purpose=sink.ingest capability=consumption local=yes verdict=passed\n' >> "$EVIDENCE_DIR/artisan.log"
php_run "$(dirname "${BASH_SOURCE[0]}")/send-message.php" "$@"
unset VERIFY_INGEST_CREDENTIAL mint_output
