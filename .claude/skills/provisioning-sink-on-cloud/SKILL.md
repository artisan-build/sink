---
name: provisioning-sink-on-cloud
description: "Deploy, provision, set up, or stand up a self-hosted Sink mail-capture instance on Laravel Cloud with the `cloud` CLI. Gathers expected mail volume as a small/medium/large tier, recommends and only after confirmation provisions the matching isolated environment resources (Postgres, Redis, object-storage bucket, web instance, managed queue, scheduler), wires SINK_* config, deploys, migrates, runs create-admin, and issues the first source-app token. Use when the user wants to deploy, provision, set up, or stand up a Sink server / instance / environment on Laravel Cloud, fork-and-deploy Sink for a client, or onboard a new client's Sink environment."
---

# Provisioning Sink on Laravel Cloud

Stands up **one isolated Sink environment** (one client) on Laravel Cloud. Sink is single-tenant by
environment: repeat this per client. One environment can capture mail from many source apps, so do NOT
provision per source app.

This skill **specializes** the generic `deploying-laravel-cloud` skill (shipped by the Cloud CLI). Its
rules still apply: **discover options at runtime** (`cloud <cmd> -h`, `cloud instance:sizes`,
`cloud cache:types`); add `-n` to every command; use `--json` on reads/creates; use `--force` on
updates and variable sets; **confirm before any billable `:create`**; delegate high-output commands
(`deploy:monitor`, `deployment:get` polling loops, `:list`) to a subagent.

> This flow follows the validated Hone/Matte Cloud runs. **Read
> [reference/cli-reality.md](reference/cli-reality.md)** before running commands; it documents which
> steps work non-interactively on cloud-cli v0.5.0 and which require the dashboard.

## What gets provisioned (the Sink topology)

| Resource | Cloud command | Notes |
| --- | --- | --- |
| Application | `application:create` or the interactive bootstrap in `cli-reality.md` | One per client. Reuse the default environment; do not create a second one unless the user explicitly asks. |
| Postgres | `database-cluster:create --type neon_serverless_postgres_18` -> `database:create <cluster> --name sink` | Holds message metadata, auth, users, and invitations. Set `DB_CONNECTION=pgsql`; `SINK_DB_*` tracks the same injected `DB_*` by default. |
| Redis | `cache:create --type upstash_redis --size ... --auto-upgrade-enabled=false --is-public=false` | Cache plus Redis queue connection. |
| Object-storage bucket | Dashboard attach on cloud-cli v0.5.0 | Cloud injects a managed `private` disk and `FILESYSTEM_DISK=private`; `SINK_DISK` defaults to it. Do not hand-wire R2 secrets. |
| Web instance | `instance:update <app-instance-id> --uses-scheduler=true --json -n --force` after sizing | Serves the inbox UI, `/ingest`, `/capabilities`, and MCP. Scheduler runs `sink:maintain`. |
| Managed queue | Prefer dashboard on v0.5.0 if `managed-queue:create` fails; otherwise `managed-queue:create` | Drains parse jobs. Sink sets `QUEUE_CONNECTION=redis` and `SINK_QUEUE_CONNECTION=redis`; use the managed queue if Cloud supports it for this app. |
| Scheduler | `--uses-scheduler=true` on the web instance | The app schedule runs `sink:maintain`; do not create a separate scheduler resource. |

**Do NOT enable Cloud-managed mail integration on this app.** Sink captures mail; it should not send mail
through Cloud-managed mail.

## Step 1 - Pick a tier

Ask the user for **Small / Medium / Large** using [reference/resource-plan.md](reference/resource-plan.md)
as the starting point. These are starting sizes, not hard commitments. First run
`cloud instance:sizes --json -n` and `cloud cache:types --json -n`, then map the tier to currently
available sizes in the target region. Match the source apps' region where possible (check existing apps
with `cloud app:list --json -n`) so HTTP ingest stays same-region.

## Step 2 - Recommend + confirm (REQUIRED gate)

Present, and **wait for explicit approval before creating anything billable**:

- Concrete region and resource sizes.
- The ordered command list, including dashboard-only steps.
- A rough cost note. Cloud's CLI does not expose per-resource pricing; point the user at
  `cloud usage --json -n` and the dashboard.

Never provision unprompted.

## Step 3 - Provision

Follow [reference/resource-plan.md](reference/resource-plan.md) exactly. Capture each resource's `id`,
`connection`, and environment URL from `--json` output for later steps. High level:

app/default env -> Postgres cluster + `sink` schema -> Redis cache -> bucket attach (dashboard) -> web
instance + scheduler -> managed queue -> attach DB/cache/bucket to env -> set `DB_*`, `SINK_*`, queue,
storage, retention, MCP, and bootstrap env vars -> deploy -> migrate -> run `create-admin` -> issue first
source-app token locally with `php artisan token:create <label>`.

Use `FALLBACK_TOKEN` only as a bootstrap token. Prefer per-app tokens from `token:create` for source apps
and MCP clients, then remove `FALLBACK_TOKEN` for production if the team no longer needs it.

## Step 4 - Verify functionally

Do not trust `environment:get` as the source of truth; it under-reports attached resources on current CLI
versions. Verify the live app:

- `curl -s -o /dev/null -w '%{http_code}' https://<env-url>/capabilities` -> **200**.
- `curl -s -o /dev/null -w '%{http_code}' -X POST https://<env-url>/ingest` with no token -> **401**.
- `curl -s -o /dev/null -w '%{http_code}' -X POST https://<env-url>/ingest -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' --data '{"envelope_version":999}'` -> **422**.
- `cloud command:run <env> --cmd="php artisan migrate:status" -n` lists Sink and built-for-cloud tables.
- MCP at `https://<env-url>/<SINK_MCP_PATH>` is reachable. No token should return **401**; with a valid
  token, MCP `initialize` should return Sink server info and `tools/list` should include the mail tools.

## Step 5 - Hand off the interactive steps

Some steps must be run by the human because the CLI blocks on browser/org/dashboard flows:

- Cloud auth / organization selection if `.cloud/config.json` is not already bound.
- Bucket attach in the Laravel Cloud dashboard (env -> Storage -> attach bucket) on cloud-cli v0.5.0.
- Any dashboard-only managed queue or resource attach fallback documented in `cli-reality.md`.
- The first admin credential entry for `php artisan create-admin` if it prompts interactively.

Give the user exact copy-paste commands and wait for them to report completion before continuing.

## Step 6 - Hand off the source-app setup

- Issue the first token from the operator's machine, in the Sink app clone with `.cloud/config.json` bound and
  an authenticated `cloud` CLI: `php artisan token:create <label>`. The label is a human-readable token label
  such as the source app's name; it is not a deployed application id. This driver command generates the
  plaintext locally, stores only the hash in the deployed environment for you, and prints the plaintext once.
- In the source app: `composer require artisan-build/sink-client` then
  `php artisan sink:install --url=https://<env-url> --token=<plaintext-token>`. Set `MAIL_MAILER=sink` only
  in environments where mail should be captured.
- Connect an agent to MCP at `https://<env-url>/<SINK_MCP_PATH>` with `Authorization: Bearer <token>`.

## Step 7 - Scale later

Use Sink's MCP/tools/UI to check message volume and queue freshness, then resize with `cloud instance:update`,
Redis/cache updates, managed queue settings, or Neon capacity changes. Keep the bucket lifecycle aligned with
`SINK_RETENTION_DAYS` as a storage backstop.
