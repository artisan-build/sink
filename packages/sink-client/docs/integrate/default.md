# Integrate Sink into a Laravel app

## Install

Install the published client package:

```bash
composer require artisan-build/sink-client
```

The package requires PHP 8.3 or later and Laravel 13.17 or later within the Laravel 13 release. Laravel discovers `ArtisanBuild\SinkClient\SinkClientServiceProvider` from the package manifest, so do not register it manually.

The package merges its configuration automatically. Publish a local config file only when the app needs to override it outside environment variables:

```bash
php artisan vendor:publish --tag=sink-client-config --no-interaction
```

## Configure

Set the following environment values. Keep credentials in the deployment environment or secret manager, never in source control.

| Key | Required | Default | Purpose and source |
| --- | --- | --- | --- |
| `MAIL_MAILER` | Yes, to capture mail | App default | Set this to the literal value `sink`. Installing the package does not change the selected mailer. |
| `SINK_URL` | Yes | None | The Sink server base URL supplied by the Sink deployment, without a trailing `/ingest`. The transport appends `/ingest`. |
| `SINK_TOKEN` | Yes | None | The bearer credential for this source app. Obtain it using the safe flow in the next section. |
| `SINK_STREAM` | No | `null` | An optional stream label sent with each envelope. Leave it unset unless the Sink operator gives this app a stream convention. |
| `SINK_ALLOW_PRODUCTION` | Only in production | `false` | Set to `true` only after explicitly deciding that a production app must capture instead of deliver mail. |
| `SINK_RETRY_ATTEMPTS` | No | `3` | Total HTTP attempts before a send throws. Values below `1` are clamped to `1`. |
| `SINK_RETRY_BASE_MS` | No | `200` | Base delay in milliseconds for exponential retry backoff plus random jitter. Values below `1` are clamped to `1`. |
| `SINK_TIMEOUT` | No | `15` | Per-attempt HTTP timeout in seconds. Values below `0.1` are clamped to `0.1`. |
| `SINK_MAX_MESSAGE_BYTES` | No | `10485760` | Raw MIME threshold in bytes. Oversized messages first lose attachments, then fall back to headers only. Values below `0` are clamped to `0`. |

`APP_ENV=production` activates the production fuse described below; do not change the application environment merely to bypass it.

## Get a credential

For a source app hosted on Laravel Cloud or Forge, use Scalpels' `connect_site` tool after confirming the target site with the human. Pass the `team`, `host`, opaque `target`, and `provider_deployment` returned by Scalpels' listing tools. Scalpels writes the Sink URL and credential into the hosting environment; the credential is never returned or shown.

For other hosting, have the Sink operator issue a source-app credential with Sink's operator-run `php artisan token:create <label>` command. Move the one-time value directly into the source app's secret manager or enter it in the masked prompt from:

```bash
php artisan sink:install --url=https://sink.example.test
```

The installer writes `SINK_URL` and `SINK_TOKEN`, pins `artisan-build/sink-client` to its installed major, and reminds the operator to select `MAIL_MAILER=sink`. Do not put the token in the `--token` option from an agent command because command text can be recorded.

Never ask for or place a plaintext credential in chat, a commit, an issue, a pull request, a command transcript, or a tool result.

## Call sites

Do not instantiate `SinkClient`; that class exposes no send or query methods. Select the `sink` mailer and keep the app's existing Laravel mail call sites. A minimal send is:

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Sink integration smoke', function ($message): void {
    $message->from('sender@example.test');
    $message->to('recipient@example.test');
    $message->subject('Sink integration smoke');
});
```

The transport turns each Symfony message into this request:

```http
POST {SINK_URL}/ingest
Authorization: Bearer {SINK_TOKEN}
Content-Type: application/json

{
  "envelope_version": 1,
  "idempotency_key": "<ULID>",
  "sent_at": "<ISO-8601 timestamp>",
  "stream": null,
  "message": "<base64-encoded raw MIME>",
  "truncation": "none"
}
```

`truncation` is `none`, `attachments_dropped`, or `headers_only`. A successful Sink server accepts the message with HTTP `202` and `{"id": <integer>}`. The transport consumes that response; Laravel call sites do not receive the stored id. A non-success response throws from the send.

The other public command is the compatibility check:

```bash
php artisan sink:update
```

It sends an authenticated `GET` to `{SINK_URL}/capabilities` and expects `{"envelope":{"min_major":<integer>,"max_major":<integer>}}`. A compatible response prints `Your Sink server understands envelope v1. You're good.` An unavailable endpoint or invalid response produces a warning rather than a failed exit; a client newer than the server fails and tells the operator to update the Sink server first.

Use this migration map only where the incumbent behavior has a real Sink equivalent:

| Incumbent usage | Sink equivalent |
| --- | --- |
| Mailtrap sandbox selected as the Laravel mailer | Set `MAIL_MAILER=sink`; keep Laravel `Mail` and mailable call sites. Sink has no equivalent for Mailtrap deliverability or spam scoring. |
| Mailosaur client calls that retrieve or assert captured email | There is no equivalent method in `sink-client`. Send through Laravel Mail, then use Sink MCP tools such as `list_recent`, `assert_count`, `body_matches`, and `links`. |
| Mailpit selected through SMTP settings | Set `MAIL_MAILER=sink`; keep Laravel Mail call sites. Sink has no SMTP server, so do not retain Mailpit SMTP host or port settings. Use Sink's UI or MCP instead of a Mailpit query API. |

## Behaviour to know

- Sink is a mail trap. The single activation switch is `MAIL_MAILER=sink`; merely installing or configuring the package does not capture mail.
- Nothing is ever delivered or forwarded. Do not enable Sink where real recipients must receive mail.
- A source-app send waits synchronously for `/ingest` to accept and store the raw MIME. The server returns `202`, then parses searchable metadata in a queued job. There is no client webhook or polling method; poll the Sink MCP assertion tools when parsed fields are not visible yet.
- Failed ingest requests retry up to `SINK_RETRY_ATTEMPTS` with exponential backoff and jitter. All attempts for one send reuse the same idempotency ULID. Exhaustion throws, so a queued Laravel mail job fails visibly.
- Missing `SINK_URL` or `SINK_TOKEN` throws `SinkNotConfigured`. In `production`, omitting `SINK_ALLOW_PRODUCTION=true` throws `SinkProductionFuse` before any HTTP request.
- `SINK_MAX_MESSAGE_BYTES` is a truncation threshold, not a rejection response. The client sends full MIME when within the threshold, drops attachment bodies when that is sufficient, and otherwise sends headers only. Inspect the stored `truncation` value before asserting on body or attachments.
- `SINK_STREAM` is nullable and reserved for optional stream tagging. App and time-window filters work without it.
- Sink's MCP is body-blind. An agent can list metadata, recipients, headers, links, and attachment metadata; count or assert counts by app, exact or partial subject, recipient, receive-time window, and stream; and test a case-insensitive body substring with `body_matches`. MCP returns only the body-match boolean and count, never raw or rendered body text.

## Verify

Run this in the configured source app. It sends a uniquely identified message and prints the exact subject:

```bash
php artisan tinker --execute='$id = (string) Illuminate\Support\Str::ulid(); Illuminate\Support\Facades\Mail::raw($id, fn ($mail) => $mail->to("sink-smoke@example.test")->subject("Sink smoke ".$id)); dump("Sink smoke ".$id);'
```

A successful command prints a subject like `Sink smoke 01K4...` and exits without an exception. Poll Sink's `assert_count` MCP tool with the printed subject until asynchronous parsing completes:

```json
{
  "subject": "Sink smoke 01K4...",
  "recipient": "sink-smoke@example.test",
  "expected": 1
}
```

The end-to-end check passes only when the tool returns:

```json
{"expected": 1, "actual": 1, "pass": true}
```

## Contribute back

If you used this default guide to migrate from a specific product and no migration guide for it
exists yet, offer to open a pull request to this repository adding
`packages/sink-client/docs/integrate/<incumbent>.md` with what you learned.
