---
name: verify-sink
description: Use when verifying Sink's real Livewire web UI, invitation-only access, inbox, or message views in a browser. Launches an isolated Sink instance on disposable PostgreSQL and a unique port, drives mapped user paths with muted Playwright, captures UI and database evidence, and cleans up without touching the developer's Herd site or database.
---

# verify-sink

Sink is a Livewire 4 mail-capture application. HTTP assertions alone do not prove its reactive filters,
invitation flow, or responsive UI, so this skill drives a real Chromium browser against a disposable
instance.

This skill never requests mutation testing. Its job is to move a user-facing claim from
`test-verified` to `live-verified`.

## Safety boundary

The harness never drives the developer's Herd hostname. It creates one `sink_verify_*` PostgreSQL
database, chooses a free loopback port, forces both Laravel's default connection and Sink's named
`sink` connection to that database, and places storage, logs, Playwright, screenshots, and runtime
state outside the repository under `~/.cache/sink-verify`.

The run forces the local filesystem, database queue, database cache, database sessions, log mailer,
and muted headless Chromium. It does not send mail, play audio, use S3, or call another app. Do not
change those safe overrides to match a production credential during ordinary verification.

## Where things go

```text
~/.cache/sink-verify/
├── node_modules/                 Playwright, not a Sink dependency
├── browsers/                     isolated Chromium binaries
├── current-run                   current run id
└── runs/<run-id>/
    ├── run.env                   non-secret run identity, port, PIDs, SHA, exact-tree provenance, database, PostgreSQL host/port/user, and credential reference
    ├── launched.env              exported variable names only, never values
    ├── server.log                 request log with invitation routes redacted before disk
    ├── worker.log
    ├── migrate.log
    ├── storage/                  this run's local object storage and framework state
    ├── *.steps.json              caller-authored browser recipes
    └── evidence/                 screenshots, transcript, Doctor/cleanup/DB proof
```

## Launch

Install the browser once, outside the repository:

```bash
.claude/skills/verify-sink/harness/install-browser.sh
```

Launch one disposable instance:

```bash
.claude/skills/verify-sink/harness/launch.sh
```

Launch creates and migrates PostgreSQL, starts `php -S` directly with four request workers, starts a
real database queue worker, waits for `/up`, and runs Doctor. It deliberately does **not** use
`php artisan serve`: that command filters the environment before starting `php -S` and can silently
serve the checkout's own database.

The instance is ready only when Doctor prints `instance is worth driving`. Launch prints the exact
`RUN_DIR`, `BASE_URL`, and `EVIDENCE` paths.

Defaults can be overridden without editing the skill:

```bash
VERIFY_PORT_FROM=8299 VERIFY_PORT_TO=8349 \
VERIFY_PGHOST=127.0.0.1 VERIFY_PGPORT=5432 \
VERIFY_PGUSER=postgres VERIFY_PGPASSWORD=postgres \
  .claude/skills/verify-sink/harness/launch.sh
```

`run.env` records the chosen PostgreSQL host, port, and user plus the name
`VERIFY_PGPASSWORD` when that variable supplied the password. It never records the password. Keep the
credential exported for later commands in the same shell, or securely re-supply it in a new shell:

```bash
read -r -s -p 'PostgreSQL password: ' VERIFY_PGPASSWORD; printf '\n'
export VERIFY_PGPASSWORD
.claude/skills/verify-sink/harness/doctor.sh
.claude/skills/verify-sink/harness/seed-actor.sh --email=admin@verify.test --password=verify-password --name="Verify Admin" --admin
.claude/skills/verify-sink/harness/cleanup.sh
unset VERIFY_PGPASSWORD
```

Doctor, every helper, and Cleanup load the recorded host/port/user rather than re-deriving defaults.
They reject conflicting `VERIFY_PGHOST`, `VERIFY_PGPORT`, or `VERIFY_PGUSER` values. A run launched
with `VERIFY_PGPASSWORD` fails closed before connecting when that variable is absent, and Cleanup keeps
`current-run` so the command can be retried after the credential is re-supplied.

## Doctor

```bash
.claude/skills/verify-sink/harness/doctor.sh
```

Doctor checks all of the following and exits non-zero if any check fails:

1. The recorded server and database queue-worker PIDs are alive.
2. `/up` answers 200.
3. Every process listening on the recorded port descends from the recorded server PID.
4. The checkout is still at the launch SHA and remains free of tracked modifications and standard untracked files.
5. PostgreSQL reports the recorded database for both the default and named `sink` connections.
6. A real anonymous `/login` request increases the session-row count in this run's database. This is
   the behavioral proof that the serving process received the run environment.
7. The database worker is alive and the queue/failed-job tables are readable.
8. Safe filesystem, mail, queue, cache, and session drivers are in force.
9. Playwright and the isolated Chromium executable are present.

Doctor writes `evidence/doctor.log`. It reports only credential **names** visible from the run's
exported-name list and the checkout's `.env`; it never reads or prints their values. Because the run
forces `SINK_DISK=local` and `MAIL_MAILER=log`, AWS and mail credentials remain unreachable even if
their names are present.

Run Doctor again after anything surprising and before trusting further evidence.

## Drive

Put a known actor in the disposable database when the feature is not account creation itself:

```bash
.claude/skills/verify-sink/harness/seed-actor.sh \
  --email=admin@verify.test --password=verify-password --name="Verify Admin" --admin
```

Create a real captured message through authenticated `POST /ingest` when an inbox recipe needs one:

```bash
.claude/skills/verify-sink/harness/send-message.sh \
  --app=verify-source --recipient=recipient@verify.test --subject="Verification message"
```

Both helpers refuse to run unless the default and named `sink` connections identify the current
`sink_verify_*` database. Seeding is a precondition, not proof of the feature being tested. The message
helper uses the real local token command, real HTTP ingest route, real database queue, and waits for the
parse side effect; it never writes the plaintext bearer token to disk or stdout.

Write a JSON step file under the current run and run it at desktop and mobile widths:

```bash
RUN="$HOME/.cache/sink-verify/runs/$(cat "$HOME/.cache/sink-verify/current-run")"

NODE_PATH="$HOME/.cache/sink-verify/node_modules" \
PLAYWRIGHT_BROWSERS_PATH="$HOME/.cache/sink-verify/browsers" \
  node .claude/skills/verify-sink/harness/drive.cjs \
  --base="$(. "$RUN/run.env"; printf '%s' "$BASE_URL")" \
  --out="$RUN/evidence" --steps="$RUN/feature.steps.json" --name=feature \
  --viewport=1280x800 --viewport=390x844
```

Supported verbs are `goto`, `login`, `fill`, `fillLabel`, `click`, `clickRole`, `clickNewPage`,
`captureValue`, `newContext`, `acceptDialog`, `expect`, `expectRole`, `expectMissing`, `expectText`,
`expectUrl`, `expectStatus`, `expectValue`, `expectAttribute`, `expectChecked`, `expectFrameText`,
`measure`, `overflow`, `shot`, and `wait`. `captureValue` stores an input value or named attribute as a
secret variable; later strings can reference it as `{{name}}`. `newContext` discards cookies and opens
a fresh isolated context. `clickNewPage` clicks a selector or accessible role and switches to the page
opened by that action. `expectValue` accepts either `selector` or an exact accessible `label` plus
`equals` or `contains`. Strings may contain `{{viewport}}`; the driver replaces it with the active
viewport so a two-viewport write can use unique emails.

Every screenshot, including automatic failure screenshots, passes through the same default-deny
redaction boundary. It masks password controls, every password supplied by a recipe, captured secret
values, `/register/{token}` URLs, and standalone 40-character invitation tokens before pixels are
written. Transcript, console, HTTP-error, and terminal records redact the same known values and
invitation patterns. The PHP server writes through a recorded redactor process so invitation routes
are also masked before `server.log` reaches disk. Recipes must use `captureValue`; they must never copy
a token into a step file.
Every step and browser console error is appended to `evidence/transcript.jsonl`; a failed expectation
captures a redacted screenshot and exits non-zero.

Prefer observed accessible labels and control names such as `Email address`, `Create invitation`,
`Inbox`, and `Subject contains`. Sink currently has few `data-test` handles; use the observed route or label rather
than inventing one. Read the relevant feature file before writing steps.

## Evidence

Every live-verification report must name the mapped feature and each entry point actually driven. A
recipe that was not run remains a recipe.

Capture all of these:

- The action and resulting state: transcript lines plus before/after screenshots.
- Database or storage side effects, written into the evidence directory with `inspect-db.sh` or an
  equally explicit read.
- Desktop and mobile screenshots, `measure` geometry, and `overflow` results for layout claims.
- Browser console errors and failed HTTP responses from the transcript.
- Doctor's launch SHA, process ownership, database identity, and session-write proof.
- The exact production-driver caveats below.

Database evidence examples:

```bash
RUN="$HOME/.cache/sink-verify/runs/$(cat "$HOME/.cache/sink-verify/current-run")"
.claude/skills/verify-sink/harness/inspect-db.sh --invitation-prefix='verify+' \
  | tee "$RUN/evidence/invitations-db.json"
.claude/skills/verify-sink/harness/inspect-db.sh --message-subject='Verification message' \
  | tee "$RUN/evidence/message-db.json"
.claude/skills/verify-sink/harness/inspect-db.sh --user-email='user@verify.test' \
  | tee "$RUN/evidence/user-db.json"
```

The harness is closer to production than `phpunit.xml`: it uses PostgreSQL, database sessions,
database cache, and a real queue worker, while tests pin SQLite, array sessions/cache, and the sync
queue. The named differences from production are important: this run uses the database queue instead
of Laravel Cloud's managed queue, local disk instead of object storage, and log mail instead of an
outbound mail transport. It proves application behavior on PostgreSQL, not Redis/S3 latency, partial
failure, bucket policy, or managed-queue delivery.

Screenshot evidence beats `getComputedStyle`. Use `measure` (`getBoundingClientRect`) and `overflow`
at more than one viewport. Hit-testing does not prove paint or occlusion.

If a route was attempted but a concrete prerequisite is unavailable, report `verified-unreachable`
with the route and prerequisite. If the harness cannot exercise working behavior, report
`verifier-blocked`. Neither status is a pass. Never silently skip an entry point.

## Cleanup

```bash
.claude/skills/verify-sink/harness/cleanup.sh
```

Run cleanup after success and after every failed iteration. It snapshots the descendants of each
recorded root PID, kills each tree deepest-first, verifies the port has no listener, validates that the
database name is exactly a `sink_verify_*` identifier, drops only that explicit database, and then
proves it no longer exists. Cleanup exits non-zero if any process, port, or database survives.

Cleanup removes only the live instance and database. It preserves the run directory and prints the
evidence path; `evidence/cleanup.log` and `evidence/cleanup-pids.txt` record what happened.

## Helpers

| Helper | Purpose |
| --- | --- |
| `harness/install-browser.sh` | Install Playwright and Chromium under `~/.cache/sink-verify`. |
| `harness/launch.sh` | Create/migrate/start one isolated run and invoke Doctor. |
| `harness/doctor.sh` | Prove process, SHA, PostgreSQL, worker, safe-driver, and browser identity. |
| `harness/seed-actor.sh` | Create/update a known disposable user, optionally with `--admin`. |
| `harness/send-message.sh` | Ingest and parse a basic HTML message through the real HTTP/queue path. |
| `harness/inspect-db.sh` | Emit secret-free JSON evidence for run summary, invitations, or messages. |
| `harness/drive.cjs` | Drive JSON browser steps in isolated muted Chromium contexts. |
| `harness/cleanup.sh` | Kill recorded trees, free the port, drop only the run database, keep proof. |

Shell wrappers can be invoked from the repository root exactly as shown above. PHP helpers are
implementation details and refuse direct non-run use.

## Feature Map

`features/README.md` contains baseline preconditions, status meanings, driving conventions, and the
index. Each feature file lists every observed user entry point. Read it first: driving one listed entry
point does not prove the others.
