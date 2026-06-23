# Sink resource plan - tiers, command sequence, env vars

Size names below are examples from live catalogs. Always re-resolve with `cloud instance:sizes --json -n`
and `cloud cache:types --json -n` in the target org/region. Read [cli-reality.md](cli-reality.md) first;
it marks dashboard-only steps on cloud-cli v0.5.0.

## Tier presets

| Tier | Web instance | Autoscale | Managed queue | Redis (`upstash_redis`) | Postgres | Bucket | Rough capacity |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Small** | `flex-1gb` (`flex-512mb` floor) | 1 replica | smallest (`mq-pro-256mb`) | `250mb` | Neon default CU | private bucket | test/staging inboxes, low attachment volume |
| **Medium** | `flex-2gb` | 1-3 replicas | mid | `1gb` | Neon, raise `cu_max` if needed | private bucket | several source apps, regular attachment traffic |
| **Large** | `flex-4gb`+ | 2-5 replicas | larger | `2.5gb`+ | Neon, higher `cu_max` | private bucket | many apps or high-volume integration/E2E mail |

Storage is driven by retained raw MIME + attachments:

```text
bucket_bytes ~= messages/day * SINK_RETENTION_DAYS * average_raw_mime_and_attachment_bytes
metadata_bytes ~= messages/day * SINK_RETENTION_DAYS * small Postgres metadata rows
```

Bias smaller. Sink has explicit retention and size caps, and Cloud resources can be resized later.

## Provisioning sequence (`<...>` = captured from prior `--json`)

```sh
# 0. Discover; pick region == source apps' region where possible.
cloud instance:sizes --json -n
cloud cache:types --json -n
cloud app:list --json -n

# 1. Application (auto-creates default env). Skip if it already exists.
cloud application:create --name sink-<client> --repository artisan-build/sink --region <region> --json -n
cloud application:get <app-id> --json -n                 # capture defaultEnvironmentId + app/default instance
cloud environment:get <env-id> --json -n                 # capture env url

# 2. Postgres cluster (Neon serverless) + schema.
cloud database-cluster:create --name sink-<client> --type neon_serverless_postgres_18 --region <region> --json -n
cloud database:create <cluster-id> --name sink --json -n # <cluster> is positional; capture schema id

# 3. Redis cache.
cloud cache:create --name sink-<client> --type upstash_redis --region <region> --size <redis-size> \
  --auto-upgrade-enabled=false --is-public=false --json -n

# 4. Bucket (DASHBOARD on v0.5.0): env -> Storage -> attach a private bucket.
#    Cloud injects FILESYSTEM_DISK=private and a managed private S3 disk. SINK_DISK defaults to it.

# 5. Web instance + scheduler.
#    Size the default app instance in dashboard if instance:create is broken, then enable scheduler:
cloud instance:update <app-instance-id> --uses-scheduler=true --json -n --force

# 6. Managed queue.
#    Use cloud managed-queue:create if it works in this org/CLI; otherwise create in dashboard.
cloud managed-queue:create -n
#    If the CLI/dashboard exposes a set-default action for the queue, use it as documented by `cloud managed-queue -h`.

# 7. Attach DB + cache to env.
#    Use dashboard on v0.5.0 if environment:update attach flags under-report or no-op.
#    Attach Postgres schema "sink", Redis cache, and bucket before deploy.

# 8. Env vars (loop; --action set upserts one key, preserves Cloud-injected values).
#    Single-quote ${...} values in real shells so the local shell does not expand them.
for kv in \
  'APP_URL=https://<env-url>' \
  'DB_CONNECTION=pgsql' \
  'SINK_DB_HOST=${DB_HOST}' 'SINK_DB_PORT=${DB_PORT}' 'SINK_DB_DATABASE=${DB_DATABASE}' \
  'SINK_DB_USERNAME=${DB_USERNAME}' 'SINK_DB_PASSWORD=${DB_PASSWORD}' \
  'QUEUE_CONNECTION=redis' 'SINK_QUEUE_CONNECTION=redis' \
  'SINK_RETENTION_DAYS=7' 'SINK_MAX_MESSAGES=<message-cap>' 'SINK_MAX_TOTAL_BYTES=<byte-cap>' \
  'SINK_MCP_PATH=/mcp' 'SINK_MCP_LOCAL_NAME=sink' \
  'FILESYSTEM_DISK=private' 'FALLBACK_TOKEN=<random-bootstrap-token>' ; do
  k=${kv%%=*}; v=${kv#*=}
  cloud environment:variables <env-id> --action set --key "$k" --value "$v" -n --force
done

# Optional: set SINK_DISK only if not using Cloud's injected FILESYSTEM_DISK=private default.
# Do NOT enable Cloud-managed mail integration.

# 9. Deploy + poll. Deploy should run migrations; run migrate manually if needed.
cloud deploy sink-<client> main --no-wait -n             # returns deployment_id
cloud deployment:get <deployment-id> --json -n           # poll until deployment.succeeded
cloud command:run <env-id> --cmd="php artisan migrate --force" -n

# 10. First admin + first source-app token.
cloud command:run <env-id> --cmd="php artisan create-admin" -n
#    Run token:create locally on the operator machine, from the Sink app clone with .cloud/config.json bound.
#    <label> is a human token label, such as the source app's name, not a deployed application id.
php artisan token:create <label>
#    The driver command stores only the hash in the deployed environment and prints the plaintext once.
```

## Environment variable checklist

Cloud injects `DB_*`, Redis/cache values, and the managed `private` disk after resources are attached and the
app is deployed. `SINK_DB_*` tracks the same database as `DB_*` by default.

| Key | Value | Notes |
| --- | --- | --- |
| `APP_URL` | `https://<env-url>` | `APP_KEY` is generated by Cloud. |
| `DB_CONNECTION` | `pgsql` | Required for the Sink app metadata DB. |
| `DB_*` | Cloud injected | Do not hand-write secrets if the DB is attached. |
| `SINK_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | `${DB_HOST}` ... `${DB_PASSWORD}` | Same DB as `DB_*` by default. Set separately only for an intentional split DB. |
| `QUEUE_CONNECTION` | `redis` | Source of truth for default queue/cache behavior in Sink. |
| `SINK_QUEUE_CONNECTION` | `redis` | Parse jobs use Redis. |
| `FILESYSTEM_DISK` | `private` | Cloud injects this when the bucket is attached. |
| `SINK_DISK` | unset by default | Defaults to `FILESYSTEM_DISK`; set only to override. |
| `SINK_RETENTION_DAYS` | `7` | Default retention. Bucket lifecycle should be a backstop, not the only cleanup. |
| `SINK_MAX_MESSAGES` | tier-specific cap | Protects Postgres and UI from unbounded growth. |
| `SINK_MAX_TOTAL_BYTES` | tier-specific cap | Protects bucket storage. |
| `SINK_MCP_PATH` | `/mcp` | Default MCP path. |
| `SINK_MCP_LOCAL_NAME` | `sink` | Local MCP display name. |
| `FALLBACK_TOKEN` | random bootstrap token | Optional bootstrap token for ingest + MCP. Prefer per-app `token:create` tokens. |

Do **not** configure Cloud-managed mail for Sink.

## Functional verification

```sh
curl -s -o /dev/null -w '%{http_code}' https://<env-url>/capabilities
curl -s -o /dev/null -w '%{http_code}' -X POST https://<env-url>/ingest
curl -s -o /dev/null -w '%{http_code}' -X POST https://<env-url>/ingest \
  -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \
  --data '{"envelope_version":999}'
cloud command:run <env-id> --cmd="php artisan migrate:status" -n
```

Expected: capabilities **200**, ingest without token **401**, bad envelope version with a valid token **422**,
MCP reachable at `https://<env-url>/<SINK_MCP_PATH>`.
