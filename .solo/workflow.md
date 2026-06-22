# Workflow — sink

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.
sink is a **monorepo** being built out from the `artisan-build/laravel-nodeless` starter kit:
a slim Sink app at the root + (target) `packages/sink-contracts`, `packages/sink-client`,
`packages/sink-server`. It mirrors `artisan-build/hone` and `artisan-build/matte` — read their
READMEs and this repo's `docs/sink-build-handover.md` (the authoritative build spec) first.

## Phase & mode
- phase: pre-launch (greenfield)
- default mode: A-autonomous
- merge_policy: merge when CI is green; no human PR code review
- merge method: `gh pr merge --squash --auto`

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `composer ready` at the repo root (ide-helper → rector → pint → phpstan → full Pest → `composer audit`).
- extra suites: once packages exist, for EACH touched package inside `packages/sink-<pkg>`: its own
  `composer lint:test` / `composer test`. The root app gate always runs even for package-only PRs
  (the app autoloads packages via path repository and must boot).
- monorepo: yes (target) — packages: sink-contracts, sink-client, sink-server (+ slim root app).
  Until PR #1 establishes the monorepo, the gate is the root `composer ready` only.

## CI (the merge gate for Mode A)
- status: verified (adequate for Mode A: testing + static analysis present).
- minimum bar: testing + static analysis — MET.
- workflows/jobs:
  - `.github/workflows/tests.yml` (push + PR to main): `composer stan` (PHPStan) + `./vendor/bin/pest`,
    PHP 8.4 + 8.5 matrix.
  - `.github/workflows/lint.yml` (push + PR to main): `composer lint` (Pint).
  - PR #1 must ADD `.github/workflows/release.yml` (lockstep `v*` tag → `php artisan kibble:split` to the
    three read-only package mirrors), mirroring Matte's `release.yml`.
- NOTE: CI installs Flux Pro via `secrets.FLUX_USERNAME` / `secrets.FLUX_LICENSE_KEY`. Those repo
  secrets MUST be set on `artisan-build/sink` or every CI run fails at `composer install`. (Setup
  blocker flagged to Ed — see brain log.)

## Dependency install (fresh worktree)
- command: `composer install --no-interaction` at the root AND inside every touched `packages/sink-<pkg>`.
- post-install: copy `.env` from `.env.example`, `php artisan key:generate`,
  `touch database/database.sqlite`, `php artisan migrate --graceful`.
- Flux Pro auth required: the worktree needs `composer config http-basic.composer.fluxui.dev <user> <key>`
  (or a global `auth.json`) or `composer install` fails. Confirm auth is present before spawning implementers.
- NEVER symlink or `cp -R` `vendor/` (root or package level) — Composer resolves the wrong checkout and
  produces phantom framework-boot/test failures. Real install only.

## Harness map (role → runtime; decorrelate by ROLE/FRAMING, not model lineage)
- In THIS Solo environment only **Claude (agent_tool_id 3)** and **OpenCode (agent_tool_id 2)** run
  reliably; Codex/Kimi/Gemini are broken here. Decorrelate reviewers by role/framing, not by model.
- implementer: OpenCode (Solo `agent_tool_id 2`) — persistent agent in the PR worktree; honors
  `extra_args=["<worktree path>"]` to set cwd.
- quality reviewer: Claude (Solo `agent_tool_id 3`), one-shot, ADVERSARIAL framing — "find what's wrong;
  default to reject." Distinct prompt/scope from the judge.
- acceptance judge: Claude (Solo `agent_tool_id 3`) — judges strictly vs the PR's acceptance criteria;
  must read REAL `composer ready` / test output, not the implementer's claims.

## Ship details
- branch naming: `feat/<slug>`
- PR target repo: `artisan-build/sink` (branch `main`)
- release / split steps: handled by `release.yml` on tag once PR #1 adds it. Splits
  `artisan-build/sink-{contracts,client,server}` are read-only mirrors (issues/PRs disabled).

## Plan & coordination
- plan location: `docs/sink-build-handover.md` (the locked build spec + 9-step build order).
- Solo project: sink (id resolved by name at spawn — ids change on re-add).
- needs: built-for-cloud (consumed for tokens, `create-admin`, invitations, UI auth gate, the cloud
  provisioning installer — build order steps 5 & 8 ADD to that repo).
- run-log: a dedicated append-only Solo scratchpad per the coordinator; brain mirrors outcomes to
  `brain/projects/sink/log.md`.

## Stack notes / quirks
- Base kit = `laravel-nodeless`: Laravel + Livewire 4 + Flux 2 + Fortify, NO Node/npm/Vite. Prebuilt
  Tailwind/Flux assets are checked into `public/build` and served directly — do not add a JS build step.
- `composer ready` runs ide-helper FIRST (regenerates model docblocks) so phpstan resolves types after
  schema changes. Bare `phpstan` against a stale committed `_ide_helper_models.php` gives FALSE errors —
  regenerate + commit it as part of the PR if stale.
- Target DB is Postgres (the `sink` connection); the starter defaults to sqlite. The ingest/parse/data-model
  PR (build order step 4) introduces Postgres; until then sqlite is fine for the gate.
- `sink-contracts` is the compatibility surface: any PR touching it must hold the additive-within-major
  rule (no field removed/repurposed) and keep round-trip tests. `stream` stays reserved/nullable in v1.
- Sink sits on an ingest path and renders captured HTML in the UI — security is load-bearing: bearer
  auth on `/ingest` and `/mcp`, sandboxed iframe + strict CSP for rendered mail, MCP tools body-blind.
