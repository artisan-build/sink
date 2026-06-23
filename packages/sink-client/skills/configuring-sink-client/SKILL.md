---
name: configuring-sink-client
description: "Configure a Laravel app to capture outbound mail in a self-hosted Sink instance via artisan-build/sink-client. Use when installing, enabling, wiring, or troubleshooting Sink mail capture in a source app, pointing an app at a Sink server, running sink:install, setting MAIL_MAILER=sink, or verifying test mail arrived in Sink."
---

# Configuring the Sink client in a source app

`artisan-build/sink-client` registers a Laravel mail transport named `sink` so **this app's** outbound mail is
captured by your self-hosted Sink server instead of being delivered. This is per-app: you need the Sink **base
URL** and a **token** for this app. The Sink operator issues it from their local Sink app clone with
`php artisan token:create <label>`, where `<label>` is a human token label such as this source app's name.

## Prerequisites

- PHP **^8.3**; Laravel **11, 12, or 13** (`sink-client` supports `illuminate ^11|^12|^13`).
- The Sink URL, for example `https://<your-sink>`.
- The plaintext token the Sink operator handed you. It is shown once by the local driver command; the deployed
  Sink environment stores only a hash.

## Steps

1. **Install** from Packagist:
   ```sh
   composer require artisan-build/sink-client
   ```
2. **Configure** with the installer (accepts flags non-interactively):
   ```sh
   php artisan sink:install --url=https://<your-sink> --token=<plaintext-token>
   php artisan config:clear
   ```
   It writes `SINK_URL` and `SINK_TOKEN` to `.env`, and pins `artisan-build/sink-client` to a caret major in
   `composer.json`.
3. **Select the Sink mailer** only where outbound mail should be captured:
   ```dotenv
   MAIL_MAILER=sink
   ```
4. **Production fuse:** if `APP_ENV=production`, Sink refuses to run unless you explicitly set:
   ```dotenv
   SINK_ALLOW_PRODUCTION=true
   ```
   This prevents accidentally swallowing real production mail.
5. **Verify receipt on the Sink side.** Send a test mail from the source app, then query Sink:
   ```sh
   php artisan tinker --execute="Mail::raw('Sink smoke test', fn ($mail) => $mail->to('recipient@example.test')->subject('Sink smoke test'));"
   ```
   In the Sink inbox UI, confirm the message appears. For an agent/MCP check, call Sink's `count_messages`
   tool for the app/time window and expect the count to increase.

## How it works

When `MAIL_MAILER=sink` is selected and both `SINK_URL` and `SINK_TOKEN` are present, the client serializes the
Symfony message to raw RFC-822 MIME, wraps it in a versioned envelope, and POSTs it to `{SINK_URL}/ingest` with
`Authorization: Bearer SINK_TOKEN`. It is capture-only: it does not deliver mail anywhere else.

The transport is fail-loud. If Sink cannot accept the message after retries, the send throws so queued mail jobs
fail visibly instead of silently dropping captured mail.

## Upgrading

```sh
php artisan sink:update
```

This derives the server's `/capabilities` endpoint and reports whether this client's envelope major is inside the
server-supported range. Rule: upgrade the **Sink server first**, then bump clients.

## Troubleshooting "mail isn't arriving"

- **`SINK_URL` and `SINK_TOKEN` must both be present**; run `php artisan config:clear` after changing `.env`.
- **`MAIL_MAILER=sink` must be selected.** Presence of `SINK_URL`/`SINK_TOKEN` alone never hijacks another
  mailer.
- **Production fuse:** if `APP_ENV=production`, set `SINK_ALLOW_PRODUCTION=true` intentionally or use a
  non-production environment for captured mail.
- **Server returns 401:** this app's token does not resolve in Sink. Ask the Sink operator to re-issue it from
  their local Sink app clone with `php artisan token:create <label>`, then update `SINK_TOKEN` here with the
  plaintext token they hand back.
- **Server rejects compatibility:** run `php artisan sink:update`. If the client envelope is ahead of the server,
  upgrade the Sink server first.
- **No message in the inbox:** confirm the code actually sent mail after `MAIL_MAILER=sink` was loaded, check the
  source app queue failure logs, then query Sink's MCP `count_messages` for the expected app/time window.

## Privacy

Sink is intended for staging, integration, and E2E mail capture. It stores raw MIME and attachments in your own
Cloud object-storage bucket and searchable metadata in your own Postgres database. Do not point production mail at
Sink unless the production fuse was reviewed and explicitly enabled.
