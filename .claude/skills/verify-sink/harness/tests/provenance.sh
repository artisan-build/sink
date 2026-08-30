#!/usr/bin/env bash

set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
. "$HARNESS_DIR/common.sh"

TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/sink-verify-provenance.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

REPOSITORY="$TEST_ROOT/repository"
git init -q "$REPOSITORY"
printf 'committed\n' > "$REPOSITORY/tracked.txt"
git -C "$REPOSITORY" add tracked.txt
git -C "$REPOSITORY" -c user.name='Sink Verify' -c user.email='sink-verify@example.test' commit -qm 'Initial commit'

assert_exact_committed_tree "$REPOSITORY"

printf 'modified\n' > "$REPOSITORY/tracked.txt"
if (assert_exact_committed_tree "$REPOSITORY") 2>/dev/null; then
	printf 'FAIL tracked modification was accepted\n' >&2
	exit 1
fi
git -C "$REPOSITORY" checkout -q -- tracked.txt

printf 'untracked\n' > "$REPOSITORY/untracked.txt"
if (assert_exact_committed_tree "$REPOSITORY") 2>/dev/null; then
	printf 'FAIL untracked file was accepted\n' >&2
	exit 1
fi
rm "$REPOSITORY/untracked.txt"

assert_exact_committed_tree "$REPOSITORY"
printf 'ok clean tree accepted; tracked and untracked dirty states rejected\n'
