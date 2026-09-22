<p align="center">
  <img src="art/icon.png" width="128" alt="sink icon">
</p>

<h1 align="center">Sink</h1>

Sink is a self-hosted mail catcher for Laravel staging, integration, and end-to-end tests. It replaces a hosted test inbox with an HTTP mail transport, a shared web inbox, and body-blind MCP tools on infrastructure you control.

Sink captures messages instead of delivering them. It is not an SMTP server, a production mail service, or a replacement for `Mail::fake()` in unit tests.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/sink) can provision and manage Sink for you. Use it when you want the inbox without operating its Laravel Cloud resources yourself.

The rest of this guide is for contributors and teams that want to run their own installation.

## What Sink includes

- A `sink` Laravel mail transport that posts raw MIME messages to `POST /ingest`.
- A signed-in inbox at `/inbox` with metadata, rendered mail, headers, links, raw source, and attachments.
- An HTTP MCP server at `/mcp` by default. Its tools expose metadata and boolean body matches, never body text.
- An hourly `sink:maintain` task that applies the retention and storage limits.
- Separate, purpose-limited credentials for message ingest and MCP access.

A **credential purpose** is the single protocol a credential may use. Sink maps `sink.ingest` to the `consumption` purpose and `sink.mcp` to the `mcp` purpose. Do not reuse one credential for both.

## Run it locally

### Prerequisites

- PHP 8.3 or newer, on a 64-bit build with `ext-gmp` and SQLite support.
- Composer.
- Git.

Node, npm, and Vite are not required. The frontend assets are already built.

From a fresh checkout, run these steps in order:

1. Install the exact locked dependencies.

   ```shell
   composer install --no-interaction
   ```

   Composer should finish package discovery without errors.

2. Create the local environment file.

   ```shell
   cp .env.example .env
   php artisan key:generate
   ```

   If you are using a Git worktree, copy the primary checkout's `.env` instead of replacing it from `.env.example`. `key:generate` should report that the application key was set.

3. Create the SQLite database and run the migrations.

   ```shell
   touch database/database.sqlite
   php artisan migrate --graceful
   ```

   The migration should complete without failures. This local setup uses SQLite; production uses the database attached by Laravel Cloud.

4. Run the test suite.

   ```shell
   composer test
   ```

   Pint and Pest should both pass. The test suite uses isolated in-memory SQLite, array cache and sessions, and a synchronous queue.

5. Start the local web server when you want to use the browser UI.

   ```shell
   composer run dev
   ```

   Open the URL printed by Artisan. Before signing in for the first time, create the local Owner in a second terminal:

   ```shell
   php artisan create-admin --local
   ```

   The command prompts for a name, email, and password. `--local` is important: it guarantees that the command writes to this checkout's database instead of a Laravel Cloud environment.

## Deploy it yourself on Laravel Cloud

These steps create a single Sink installation. One installation can receive mail from many Laravel apps.

1. Fork this repository and connect the fork to a Laravel Cloud application. Use the interactive `cloud ship` bootstrap for a new application, or bind an existing application with `cloud repo:config`.
2. Provision and attach PostgreSQL, a private object-storage bucket, and a managed queue. Enable the scheduler on the web instance. Do not create a manually scaled background worker for the queue.
3. Let Cloud inject every setting for those resources. **Never set environment variables for a database, cache, queue, or bucket that Cloud provisions.** Cloud injects the credentials and connection selectors such as `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`, `DB_*`, `REDIS_*`, `SQS_*`, and `AWS_*`. Setting your own value shadows the injected value and breaks the resource.
4. Set only app-specific values that Cloud does not inject, such as `APP_NAME`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, and any Sink retention limits you want to change. Let Cloud preserve or generate `APP_KEY`.
5. Deploy, run `php artisan migrate --force` in the Cloud environment, and verify that the database connects, the private disk can write and read, queued parse jobs drain, and the scheduler lists `sink:maintain` hourly.
6. From your local checkout, create the first Owner in the intended Cloud environment:

   ```shell
   php artisan create-admin --environment=production
   ```

   Replace `production` with the intended Cloud environment name. This command intentionally reaches Laravel Cloud when `--environment` is present. It prompts locally and sends a password hash to the selected environment. Use `--local` instead only when you mean the current machine.
7. Sign in and open **Installation credentials**. Create one Bearer credential for `sink.ingest` for each source app. Create a separate Bearer credential for `sink.mcp` for each MCP client. Each secret is shown once, so transfer it directly to the destination secret manager.

Cloud commands and dashboard capabilities change over time. Inspect `cloud <command> --help` before provisioning. Do not enable a Cloud-managed mail integration on the Sink app; Sink is the inbox.

## Connect a Laravel app

In the Laravel app whose mail you want to capture:

1. Install the client.

   ```shell
   composer require artisan-build/sink-client
   ```

2. Run the installer and enter the `sink.ingest` credential at the masked prompt.

   ```shell
   php artisan sink:install --url=https://sink.example.com
   ```

3. Enable Sink only in environments where mail must be captured.

   ```dotenv
   MAIL_MAILER=sink
   ```

The installer writes `SINK_URL` and `SINK_TOKEN` to that app's `.env`. Installing the package alone does not change the app's mailer. In `production`, the transport also refuses to start unless `SINK_ALLOW_PRODUCTION=true` is explicitly set.

Send mail through Laravel as usual. Sink retries temporary HTTP failures, uses an idempotency key so a retry does not create a duplicate, and throws if delivery to Sink never succeeds.

Run `php artisan sink:update` in the source app to compare its envelope version with the configured Sink server. Upgrade the Sink server before upgrading clients.

## Use the inbox and MCP

Open `/inbox` after signing in to search and inspect captured messages. Sink renders HTML in a sandboxed frame and keeps raw MIME and attachment bytes in the configured storage disk.

Connect an HTTP MCP client to `https://sink.example.com/mcp` with this header:

```text
Authorization: Bearer <sink.mcp credential>
```

For example, call `count_messages` with an app, subject, recipient, or time window to count matching sends. Use `body_matches` when you need to assert body content: it returns only a boolean and match count. `purge` is the only mutating MCP tool and refuses an unscoped deletion.

## Configuration

### Sink server

Leave these unset unless you need to change the documented default. Laravel Cloud resource variables are not listed here because Cloud must inject them.

| Variable | Default | Purpose |
| --- | --- | --- |
| `SINK_ROUTE_PREFIX` | empty | Prefixes the ingest, capabilities, and inbox routes. |
| `SINK_QUEUE_CONNECTION` | default queue | Overrides the queue connection used by parse jobs. Leave it unset for a Cloud managed queue. |
| `SINK_DISK` | `FILESYSTEM_DISK`, then `local` | Storage disk for raw MIME and attachment bytes. |
| `SINK_DB_HOST`, `SINK_DB_PORT`, `SINK_DB_DATABASE`, `SINK_DB_USERNAME`, `SINK_DB_PASSWORD` | unset | Defines a separate PostgreSQL metadata connection. Leave all five unset to use the app's default database. |
| `SINK_RETENTION_DAYS` | `7` | Deletes messages older than this many days. |
| `SINK_MAX_MESSAGES` | unset | Optional maximum retained message count. |
| `SINK_MAX_TOTAL_BYTES` | unset | Optional maximum retained message bytes. |
| `SINK_MCP_PATH` | `/mcp` | HTTP path for the MCP server. |

### Source Laravel app

| Variable | Default | Purpose |
| --- | --- | --- |
| `MAIL_MAILER` | app default | Set to `sink` to activate capture. |
| `SINK_URL` | required | Base URL of the Sink installation. |
| `SINK_TOKEN` | required | Bearer credential with the `consumption` purpose. |
| `SINK_STREAM` | unset | Optional stream label reserved for message grouping. |
| `SINK_ALLOW_PRODUCTION` | `false` | Allows the capture transport when `APP_ENV=production`. |
| `SINK_RETRY_ATTEMPTS` | `3` | HTTP attempts before the send fails. |
| `SINK_RETRY_BASE_MS` | `200` | Base delay for exponential retry backoff. |
| `SINK_TIMEOUT` | `15` | Per-request timeout in seconds. |
| `SINK_MAX_MESSAGE_BYTES` | `10485760` | Maximum MIME payload before attachments or bodies are omitted. |

## Troubleshooting

### `database/database.sqlite` does not exist

Run `touch database/database.sqlite`, then `php artisan migrate --graceful`. Commands that inspect the schedule can also need the local database because the default cache store uses it.

### A command uses the wrong database

Run commands from the intended checkout and use `php artisan config:clear` after changing `.env`. In a worktree, copy the primary checkout's `.env`; do not run a setup command in the primary checkout just to prepare the worktree.

### A credential returns `401`

Check that it is live, installation-owned, and has the right purpose. Ingest requires `consumption`; MCP requires `mcp`. Credentials from the pre-purpose token store do not work and must be re-minted.

### Messages arrive but stay unparsed

Verify that the managed queue is attached and processing jobs. Remove any hand-written `QUEUE_CONNECTION`, `SQS_*`, or queue credentials that shadow Laravel Cloud's injected values. Leave `SINK_QUEUE_CONNECTION` unset when parse jobs should use the default managed queue.

### Storage or database works locally but fails on Cloud

Check for manually configured `DB_*`, `DB_CONNECTION`, `AWS_*`, `FILESYSTEM_DISK`, cache, or queue variables. Remove values tied to attached Cloud resources so the managed environment file can supply the correct settings.

### Installed package behavior does not match this checkout

Run a real `composer install --no-interaction` in the checkout. Do not reuse or symlink another checkout's `vendor` directory.

## License

Sink is open-source software licensed under the [MIT license](LICENSE).
