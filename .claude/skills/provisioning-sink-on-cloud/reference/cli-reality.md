# Laravel Cloud CLI reality for Sink (cloud-cli v0.5.0)

What works non-interactively, what needs the dashboard, and where to hand off to the human. Re-check on newer
CLI versions; these constraints mirror the validated Hone and Matte runs.

## Auth / org selection

- If the repo is not bound with `.cloud/config.json`, org selection may be interactive. Have the human run
  `cloud repo:config` or the first bootstrap command that opens the organization picker.
- After `.cloud/config.json` contains the organization/application binding, later commands can be run with
  `-n`.
- Do not guess organizations from local token ids; the CLI does not expose enough local metadata.

## Works via CLI (non-interactive)

- `application:create --name ... --repository owner/repo --region ... --json -n` creates the app and default
  environment. Use `application:get ... --json -n` to capture `defaultEnvironmentId`.
- `database-cluster:create --name ... --type neon_serverless_postgres_18 --region ... --json -n` creates a
  Neon serverless cluster. `database:create <cluster> --name sink --json -n` creates the schema; `<cluster>` is
  positional.
- `cache:create --name ... --type upstash_redis --region ... --size ... --auto-upgrade-enabled=false --is-public=false --json -n`
  creates a private Redis cache. Both boolean flags are required.
- `environment:variables <env> --action set --key K --value V -n --force` upserts one env var while preserving
  Cloud-injected values. Loop this for `SINK_*` and related keys.
- `instance:update <inst> --uses-scheduler=true --json -n --force` flips the scheduler on after the app
  instance exists.
- `command:run <env> --cmd="php artisan ..." -n` runs a shell command on an instance. Prefix Artisan commands
  with `php artisan`.
- `deploy <app> main --no-wait -n` returns a deployment id; poll with `deployment:get <id> --json -n`. Delegate
  noisy polling to a subagent.

## Dashboard-only / unreliable on v0.5.0

- **Object-storage bucket attach is dashboard-only.** The CLI can create/list buckets in some orgs, but it does
  not expose a reliable bucket -> environment association flag. Attach the bucket in the Laravel Cloud dashboard
  (environment -> Storage). Once attached and deployed, Cloud injects a managed disk named `private` and
  `FILESYSTEM_DISK=private`. Sink uses this by default through `SINK_DISK` falling back to `FILESYSTEM_DISK`.
  Do not hand-wire `AWS_*` or R2 secrets.
- **`instance:create` may be broken non-interactively.** If it fails with the known scaling enum error, use the
  default app instance created with the application, size it in the dashboard, then run `instance:update` to
  enable the scheduler.
- **`environment:update --database-id/--cache-id` may under-report or no-op depending on the org/CLI path.** If
  attach flags do not clearly work, attach Postgres and Redis in the dashboard. Verify by exercising the app, not
  by reading `environment:get`.
- **Managed queue creation may require the dashboard.** If `managed-queue:create` sends unsupported replica
  fields or cannot associate with the environment, create/configure it in the dashboard and follow the CLI help
  for any available `set-default` command.

## Do not trust readback alone

`environment:get` can report `databaseSchemaId`, `cacheId`, branch, or storage details as null even when the UI
and a successful deploy prove they are attached. Verify functionally:

- `/capabilities` returns **200**.
- `/ingest` with no token returns **401**.
- `/ingest` with a valid token and bad `envelope_version` returns **422**.
- `php artisan migrate:status` lists Sink and built-for-cloud tables.
- MCP `initialize` works at `/mcp` with a valid token.

## Sink-specific gotchas

- Do not enable Cloud-managed mail integration for Sink.
- Bucket attach must precede storage verification; `FILESYSTEM_DISK=private` appears only after Cloud injects the
  attached storage config and the app is deployed.
- `SINK_DB_*` should track injected `DB_*` by default; only split them when a user explicitly wants a separate
  metadata database.
- `FALLBACK_TOKEN` is a bootstrap convenience. For production hand-off, issue per-app tokens from the operator's
  machine with `php artisan token:create <label>` in the Sink app clone where `.cloud/config.json` is bound and
  the `cloud` CLI is authenticated. `<label>` is a human token label, such as the source app's name, not a
  deployed application id. The driver command stores only the hash in the deployed environment and prints the
  plaintext once for source apps and MCP clients.
