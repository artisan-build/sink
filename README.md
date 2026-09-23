<p align="center">
  <img src="art/icon.png" width="128" alt="sink icon">
</p>

<h1 align="center">Sink</h1>

Sink is a self-hosted mail catcher for Laravel staging, integration, and end-to-end tests. It replaces a hosted test inbox with an HTTP mail transport, a shared web inbox, and Model Context Protocol (MCP) tools on infrastructure you control. The MCP tools are body-blind: they can test message bodies without returning the body text.

Sink captures messages instead of delivering them. It is not an SMTP server, a production mail service, or a replacement for `Mail::fake()` in unit tests.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/sink) can provision and manage Sink for you. Use it when you want the inbox without operating its Laravel Cloud resources yourself.

The rest of this guide is for contributors and teams that want to run their own installation.

## What Sink includes

- A `sink` Laravel mail transport that sends `POST /ingest` a JSON envelope: a versioned JSON wrapper containing `envelope_version`, an idempotency ULID, send time, stream, base64-encoded MIME, and truncation state.
- A signed-in inbox at `/inbox` with metadata, rendered mail, headers, links, raw source, and attachments.
- An HTTP MCP server at `/mcp` by default. Its tools expose metadata and boolean body matches, never body text.
- A `/capabilities` endpoint that clients use to check envelope compatibility.
- An hourly `sink:maintain` task that cleans orphaned blobs and runs `sink:prune` to apply the retention and storage limits. Run `php artisan sink:prune` when you need to reclaim expired messages immediately.
- Separate, purpose-limited credentials for message ingest and MCP access.

A **credential purpose** is the single protocol a credential may use. Sink maps `sink.ingest` to the `consumption` purpose and `sink.mcp` to the `mcp` purpose. Do not reuse one credential for both.

## Run it locally

### Prerequisites

- PHP 8.4.1 or newer, on a 64-bit build with `ext-gmp` and SQLite support.
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

   `key:generate` should report that the application key was set.

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

5. Create the local Owner.

   An **Owner** is the first account and has full administrative access.

   ```shell
   php artisan create-admin --local
   ```

   The command prompts for an email, name, password, and password confirmation. `--local` guarantees that the command writes to this checkout's database instead of a Laravel Cloud environment.

6. Start the local web server when you want to use the browser UI.

   ```shell
   composer run dev
   ```

   Open the URL printed by Artisan, then follow **Open application** to `/bfc/ui` and sign in at `/bfc/login`. If port 8000 is already in use, run `php artisan serve --host=localhost --port=8001` instead.

7. Create local credentials after signing in.

   Open `/bfc/ui/credentials/installation`. An **installation-owned credential** belongs to this Sink installation rather than to a person. For each source app, issue a Bearer credential for `sink.ingest` with subject type `installation`. Put a stable source-app name such as `billing-staging` in **Subject reference**; that value becomes the message's `app` label in the inbox and MCP filters. Create a separate Bearer credential for `sink.mcp` for each MCP client. Each secret is shown once.

   The CLI alternative for a local ingest credential is:

   ```shell
   php artisan bfc:credential:mint installation billing-staging --kind=bearer --purpose=consumption --name="billing-staging ingest" --local
   ```

   The `--local` option keeps the operation off Laravel Cloud. Use `php artisan bfc:credential:list --local` to find an ID, then `php artisan bfc:credential:rotate ID --local` or `php artisan bfc:credential:revoke ID --local` when a credential must be replaced or disabled. The Installation credentials page has the same Rotate and Revoke actions.

8. Run the queue worker in a second terminal.

   ```shell
   php artisan queue:work
   ```

   Keep this process running while Sink receives mail. It should report each `ParseMessage` job as `DONE`; without it, messages are accepted but their subject, recipients, headers, links, and attachments stay unparsed.

9. Send a real message without waiting for the client packages to be published.

   Run this Bash block in a third terminal. Paste the shown-once `sink.ingest` secret when prompted; `read` keeps it out of shell history. If the server uses port 8001, change `SINK_URL` first.

   ```bash
   SINK_URL=http://localhost:8000
   read -rsp "Paste the sink.ingest credential: " SINK_TOKEN
   printf '\n'

   php -r '
   require "vendor/autoload.php";
   $mime = "From: sink@example.test\r\nTo: someone@example.test\r\nSubject: Sink smoke test\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nhello from Sink\r\nhttps://example.test/\r\n";
   echo json_encode([
       "envelope_version" => 1,
       "idempotency_key" => (string) Illuminate\Support\Str::ulid(),
       "sent_at" => (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
       "stream" => null,
       "message" => base64_encode($mime),
       "truncation" => "none",
   ], JSON_THROW_ON_ERROR);
   ' | curl --fail-with-body --request POST "$SINK_URL/ingest" \
       --header "Authorization: Bearer $SINK_TOKEN" \
       --header "Content-Type: application/json" \
       --data-binary @-

   unset SINK_TOKEN
   ```

   The endpoint should return HTTP `202` with a message ID. The queue worker should report `ParseMessage` as `DONE`; `/inbox` should then show **Sink smoke test** under the `billing-staging` app label.

## Deploy it yourself on Laravel Cloud

These steps create a single Sink installation. One installation can receive mail from many Laravel apps.

You need a Laravel Cloud account and the Laravel Cloud `cloud` CLI. Authenticate with `cloud auth`. Replace every `replace-with-*` value below with the value returned by Cloud. CLI commands can change, so inspect `cloud <command> --help` before provisioning.

**Never set environment variables for a database, cache, queue, or bucket that Cloud provisions.** Cloud injects both credentials and connection selectors such as `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`, `DB_*`, `REDIS_*`, `SQS_*`, and `AWS_*`. A value you set yourself shadows the managed value and can break the resource. Do not copy `.env.example` into Cloud.

The Cloud dashboard is the fallback if your CLI version lacks one of the provisioning commands below. Bucket attachment is the only step that currently requires the dashboard.

1. Fork this repository and clone your fork.

2. Bootstrap a new Cloud application from that checkout.

   ```shell
   cloud ship
   ```

   In the interactive flow, connect the fork and let `cloud ship` create and attach PostgreSQL. A successful first deployment and an attached database should appear in the dashboard. For an existing application, skip `cloud ship` and continue with the binding step.

3. Bind the checkout to the Cloud application.

   ```shell
   cloud repo:config
   ```

   Run this even after `cloud ship`, which may not write the repository binding. Confirm that `.cloud/config.json` identifies the intended organization and application, then commit it through your normal review workflow so future clones stay bound. The file contains identifiers, not credentials.

4. Get the environment ID from the application ID in `.cloud/config.json`.

   ```shell
   cloud environment:list replace-with-application-id --json --fields=id,name -n
   export SINK_CLOUD_ENVIRONMENT_ID=replace-with-environment-id
   ```

   Match the intended environment name, then copy its `id`, not its display name.

5. Get the app instance ID.

   ```shell
   cloud instance:list "$SINK_CLOUD_ENVIRONMENT_ID" --json -n
   export SINK_CLOUD_INSTANCE_ID=replace-with-app-instance-id
   ```

   Choose the instance whose type is `app` and copy its `id`.

6. Enable the scheduler on that app instance.

   ```shell
   cloud instance:update "$SINK_CLOUD_INSTANCE_ID" --uses-scheduler=true --json -n --force
   ```

   The update should report scheduling as enabled.

7. Create the private object-storage bucket.

   ```shell
   cloud bucket:create --name sink-production \
       --region replace-with-cloud-region \
       --visibility private \
       --key-name sink-production \
       --key-permission read_write \
       --allowed-origins "https://sink.example.com" \
       --json -n
   ```

   Replace the hostname and region with the real values. The CLI requires `--allowed-origins`, although Sink reads and writes this private bucket server-side.

8. In the Cloud dashboard, open **Application > Environment > Storage** and attach that bucket to the Sink environment. Bucket attachment is the one provisioning action the CLI cannot perform. The environment should show the bucket as attached.

9. List the available managed-queue sizes.

   ```shell
   cloud instance:sizes --json -n
   ```

   Choose a current `mq-pro-*` size.

10. Create the managed queue.

   ```shell
   cloud managed-queue:create "$SINK_CLOUD_ENVIRONMENT_ID" --name sink --size replace-with-mq-size --json -n
   export SINK_CLOUD_QUEUE_ID=replace-with-created-queue-id
   ```

   Copy the created queue's `id` from the JSON response. Do not create a manually scaled background-process worker.

11. Make that managed queue the environment default.

   ```shell
   cloud managed-queue:set-default "$SINK_CLOUD_QUEUE_ID" --json -n
   ```

   The response should report `isDefault` as `true`.

12. Set only app-specific values that Cloud does not inject.

    ```shell
    cloud environment:variables "$SINK_CLOUD_ENVIRONMENT_ID" --action=set --key=APP_NAME --value=Sink -n --force
    cloud environment:variables "$SINK_CLOUD_ENVIRONMENT_ID" --action=set --key=APP_URL --value=https://sink.example.com -n --force
    cloud environment:variables "$SINK_CLOUD_ENVIRONMENT_ID" --action=set --key=APP_ENV --value=production -n --force
    cloud environment:variables "$SINK_CLOUD_ENVIRONMENT_ID" --action=set --key=APP_DEBUG --value=false -n --force
    ```

    Use the real Sink URL. Set retention knobs the same way if you want to change them. Let Cloud preserve or generate `APP_KEY`, and do not set any resource variable named in the cardinal rule above.

13. Deploy the application.

    ```shell
    cloud deploy -n --open
    ```

    Wait for the deployment to finish successfully before running commands against it.

14. Run the migrations.

    ```shell
    cloud command:run "$SINK_CLOUD_ENVIRONMENT_ID" --cmd="php artisan migrate --force" -n
    ```

    The monitored command should finish successfully and list completed migrations.

15. Verify the injected database connection.

    ```shell
    cloud tinker "$SINK_CLOUD_ENVIRONMENT_ID" --code='echo config("database.default").PHP_EOL; Illuminate\Support\Facades\DB::connection()->getPdo();'
    ```

    It should print Cloud's database connection name and exit without a connection error.

16. Verify a private-disk write/read/delete round trip.

    ```shell
    cloud tinker "$SINK_CLOUD_ENVIRONMENT_ID" --code='$path="sink-readme-check"; Illuminate\Support\Facades\Storage::put($path, "ok"); echo Illuminate\Support\Facades\Storage::get($path).PHP_EOL; Illuminate\Support\Facades\Storage::delete($path);'
    ```

    It should print `ok`.

17. Verify the injected queue connection.

    ```shell
    cloud tinker "$SINK_CLOUD_ENVIRONMENT_ID" --code='echo config("queue.default").PHP_EOL;'
    ```

    It should print Cloud's managed connection.

18. Verify the scheduler.

    ```shell
    cloud command:run "$SINK_CLOUD_ENVIRONMENT_ID" --cmd="php artisan schedule:list" -n
    ```

    The monitored command should finish successfully and list `sink:maintain` hourly.

19. Verify the public capabilities endpoint.

    ```shell
    curl --fail https://sink.example.com/capabilities
    ```

    Replace `sink.example.com` with the environment URL. The endpoint should return a JSON capabilities document with HTTP `200`.

20. From the bound local checkout, create the first Owner, the full-access administrator, and let the command show its environment picker:

    ```shell
    php artisan create-admin
    ```

    The picker defaults to the local database, so explicitly select the intended Cloud environment. For a non-interactive call, pass `--environment=<environment-id>`, not the environment name. The command prompts locally and sends a password hash to the selected environment. Use `--local` only when you mean the current machine.

21. Sign in at `/bfc/login` and open `/bfc/ui/credentials/installation`. Create one installation-owned Bearer credential for `sink.ingest` for each source app, putting the source app's stable name in **Subject reference**. Installation-owned means the credential belongs to this Sink installation, not a person. That value is the `app` label in the inbox and MCP filters. Create a separate Bearer credential for `sink.mcp` for each MCP client. Transfer each shown-once secret directly to the destination secret manager.

22. Repeat local step 9 with the deployed `SINK_URL` and its `sink.ingest` credential. The request should return HTTP `202`, the managed queue should drain the parse job, and the deployed `/inbox` should show **Sink smoke test**.

Do not enable a Cloud-managed mail integration on the Sink app; Sink is the inbox.

## Connect a Laravel app

**Release status:** no published `artisan-build/sink-client` version currently resolves from Packagist. The published v0.1.0 and v0.2.0 clients require an unpublished `artisan-build/sink-contracts ^1.0`, while this repository's compatible client, contracts, and server are version 1.0.0 but are not published. Do not run `composer require artisan-build/sink-client` until the 1.0.0 package line is published.

After those 1.0.0 packages are published, run these steps in the Laravel app whose mail you want to capture:

1. Install the client.

   ```shell
   composer require artisan-build/sink-client:^1.0
   ```

2. Run the installer and enter the `sink.ingest` credential at the masked prompt.

   ```shell
   php artisan sink:install --url=https://sink.example.com
   ```

   The installer asks before writing `.env` and may also ask to pin the installed client major in `composer.json`.

3. Enable Sink only in environments where mail must be captured.

   ```dotenv
   MAIL_MAILER=sink
   ```

   The installer writes `SINK_URL` and `SINK_TOKEN` to that app's `.env`. Installing the package alone does not change the app's mailer. In `production`, the transport also refuses to start unless `SINK_ALLOW_PRODUCTION=true` is explicitly set.

   The client retries failed HTTP requests, including permanent failures such as `401` and `422`, uses an idempotency key so a retry does not create a duplicate, and throws if delivery to Sink never succeeds.

4. Send a smoke-test message.

   ```shell
   php artisan tinker --execute='Illuminate\Support\Facades\Mail::raw("hello", fn ($message) => $message->to("someone@example.test")->subject("Sink smoke test"));'
   ```

   Open `/inbox` on the Sink server. You should see one message with subject **Sink smoke test** and the `app` label you entered as the ingest credential's Subject reference. Locally, the queue worker should report the parse job as `DONE`; on Cloud, the managed queue should return to no pending jobs.

Run `php artisan sink:update` in the source app to compare its envelope version with the configured Sink server. Upgrade the Sink server before upgrading clients.

## Use the inbox and MCP

Open `/inbox` after signing in to search and inspect captured messages. Sink renders HTML in a sandboxed frame and keeps raw MIME and attachment bytes in the configured storage disk.

Connect an HTTP MCP client to `https://sink.example.com/mcp` with this header:

```text
Authorization: Bearer <sink.mcp credential>
```

Sink provides these ten tools:

- `list_apps`: list app labels with message counts and latest receipt times.
- `list_recent`: list recent message metadata without body text.
- `count_messages`: count messages by app, subject, recipient, stream, or time window.
- `recipients`: list recipient addresses and their `to`, `cc`, or `bcc` kind.
- `assert_count`: assert that a filtered message count equals an expected integer.
- `stats`: group message statistics by app, subject, or recipient domain.
- `message_detail`: return headers, recipients, attachments, and other metadata, but not body text.
- `links`: return normalized URLs extracted from a message without returning body text.
- `body_matches`: test for a body substring and return only a boolean and occurrence count.
- `purge`: delete messages in an explicit metadata scope; it refuses an unscoped deletion.

## Configuration

### Sink server

Leave these unset unless you need to change the documented default. Laravel Cloud resource variables are not listed here because Cloud must inject them.

| Variable | Default | Purpose |
| --- | --- | --- |
| `SINK_ROUTE_PREFIX` | empty | Prefixes ingest, capabilities, and inbox routes, so `sink` moves the inbox to `/sink/inbox` and requires source apps to use a `SINK_URL` ending in `/sink`. It does not prefix MCP; change `SINK_MCP_PATH` separately. |
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
| `SINK_URL` | required | Base URL of the Sink installation, including `SINK_ROUTE_PREFIX` when the server uses one. |
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

Run every command from the directory you cloned into; check `pwd` first. Use `php artisan config:clear` after changing `.env`.

### A credential returns `401`

Check that it is live, installation-owned, and has the right purpose. Ingest requires `consumption`; MCP requires `mcp`. Tokens created before this version do not have a purpose and must be re-minted.

### Messages arrive but stay unparsed

Locally, start `php artisan queue:work` and keep it running. On Laravel Cloud, verify that the managed queue is attached and processing jobs. Remove any hand-written `QUEUE_CONNECTION`, `SQS_*`, or queue credentials that shadow Cloud's injected values. Leave `SINK_QUEUE_CONNECTION` unset when parse jobs should use the default managed queue.

### Source apps receive `404` after adding a route prefix

Include the prefix in every source app's `SINK_URL`. For example, when `SINK_ROUTE_PREFIX=sink`, use `SINK_URL=https://sink.example.com/sink`; the client appends `/ingest` and `/capabilities` itself.

### Storage or database works locally but fails on Cloud

Check for manually configured `DB_*`, `DB_CONNECTION`, `AWS_*`, `FILESYSTEM_DISK`, cache, or queue variables. Remove values tied to attached Cloud resources so the managed environment file can supply the correct settings.

### Installed package behavior does not match this checkout

Run a real `composer install --no-interaction` in the checkout. Do not reuse or symlink another checkout's `vendor` directory.

## License

Sink is open-source software licensed under the [MIT license](LICENSE).
