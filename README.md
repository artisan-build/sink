# Sink

**Self-hosted, unmetered staging/test mail capture for Laravel, on Laravel Cloud.**

> **Don't want to run it?** Sink is MIT — fork and own it. Or [have Artisan Build run it](https://artisan.build/start?ref=gh-sink-top) in your Cloud account and keep it updated.

Sink is a mail trap you fork and deploy to your own Laravel Cloud account. Drop the
client into any Laravel app, set `MAIL_MAILER=sink`, and every outbound message is
captured to a self-hosted inbox instead of being delivered. Humans can inspect the
mail in a web UI; coding agents can assert on metadata and body-match booleans over
[MCP](https://modelcontextprotocol.io).

> **One mailer in, nothing out.** No metering, no per-message bill, no third-party
> inbox holding your staging mail.

Sink is capture-only by design. It is not an SMTP server, not a forwarding relay,
and not a replacement for `Mail::fake()` in unit tests.

---

## Why Sink exists

Local mail catchers like Mailpit are excellent for one developer's laptop, but they
are ephemeral and local. Hosted sandboxes like Mailtrap are polished, but they add
another vendor, metering, and another inbox holding your data.

Sink is a free, self-hosted **floor**: a persistent, shared, agent-queryable
staging/test inbox running on infrastructure you control. Storage and Cloud
resources are your only costs.

**Positioning.** Mailpit is the right tool for a single developer's local loop, and
Mailtrap is the full hosted product with deliverability scoring and broad team
features. Sink is the floor for Laravel teams that want a persistent shared staging
trap in their own Cloud account, with a coding agent as a first-class consumer.
Outgrow it -> **Mailtrap is the upgrade.** Sink is MIT licensed.

## Support posture

Sink is written for how [Artisan Build](https://artisan.build) uses it. Bugs get
fixed. Feature requests are a fork away. Client-specific features are **not**
backfilled into the OSS release. If you need the full hosted product, that is what
Mailtrap is for.

---

## Architecture

```
  Source app  (MAIL_MAILER=sink)
    └─ sink-client transport
         serialize Symfony message → raw MIME
         mint idempotency ULID + thin envelope
         POST /ingest  (Bearer SINK_TOKEN, retry + backoff, cold-start tolerant)
         capture-only · production fuse · fail-loud on exhaustion
              │
              ▼
  Sink app  (one isolated Laravel Cloud environment per client)
    /ingest → token auth → envelope-version check (4xx if sender is ahead)
            → upsert by idempotency key → raw MIME to object storage → enqueue
              │
              ▼
    Redis managed queue  (autoscale on depth, scale to zero)
              │
              ▼
    Parse worker → extract subject / from / to·cc·bcc / headers / links /
                   attachment metadata / sizes → Postgres (metadata)
                   raw MIME + attachment bytes → object storage
              │
      ┌───────┴───────────────────────────────┐
      ▼                                        ▼
  Human inbox UI                          MCP server  (Bearer)
  invitation-only                         body-blind assertion tools
  sandboxed HTML render                   ◄── coding agent / CI
  headers · links · raw · attachments
              │
              ▼
    Scheduled  sink:maintain → sink:prune
    (retention 7d default + max-messages + max-total-bytes; bucket lifecycle backstop)
```

- **One isolated environment per client** on Laravel Cloud: its own web instance,
  Postgres database, Redis, object-storage bucket, managed queue, and scheduler.
- **Unlimited source apps per environment.** Source apps are tagged by the bearer
  token that sent them, giving Sink its `app` dimension.
- **Cross-boundary transport is HTTP, not shared Redis.** Source apps are separate
  deployments; `POST /ingest` is the boundary.

---

## Repository layout

This is a **monorepo**. Three packages are developed here under `packages/`, each
split read-only to its own repository and published to Packagist:

| Package | Repo | Installed in | Role |
| --- | --- | --- | --- |
| [`artisan-build/sink-contracts`](https://github.com/artisan-build/sink-contracts) | read-only split | both packages | The versioned wire envelope. The single place compatibility lives. |
| [`artisan-build/sink-client`](https://github.com/artisan-build/sink-client) | read-only split | source Laravel apps | The send side: the `sink` mail transport, activation guards, retry/backoff, idempotency key, `sink:install`, and `sink:update`. |
| [`artisan-build/sink-server`](https://github.com/artisan-build/sink-server) | read-only split | the Sink app | The receive side: ingest, parse, object storage, prune, the human inbox UI, and the MCP server. |

The **Sink app** at this repository's root is a slim Laravel shell: auth, token
handout, and wiring `sink-server`. It stays thin so Sink-specific business logic
does not drift out of the packages.

### Contributing

Issues and PRs are **disabled** on the three split repos, matching Laravel's own
read-only split model. All development happens here in the monorepo. See
[`SECURITY.md`](SECURITY.md) for private vulnerability disclosure; Sink sits on an
untrusted ingest path.

---

## Compatibility & versioning

Across N independently-deployed senders and one self-hosted receiver, **version
skew is the normal state, not an error.** The wire protocol is built to tolerate
it:

- **Versioned envelope.** Every payload carries `envelope_version`. The envelope
  evolves **additively within a major**: new optional fields only, never remove or
  repurpose an existing field.
- **Backward-compatible server.** A newer `sink-server` parses every older envelope
  major it supports.
- **Loud failure on the dangerous case.** Ingest returns a clear **4xx** when a
  sender uses an envelope newer than the server understands: update the Sink app
  first.
- **Opaque message payload.** Only the thin Sink envelope is version-sensitive; the
  message is base64-encoded raw RFC-822 MIME and interpreted server-side.
- **Canonical upgrade order: update the Sink server first, then bump clients.** A
  backward-compatible server safely runs ahead of its senders; the hazard is a
  sender getting ahead of the server.
- **Reserved stream field.** `stream` is present but nullable in v1. It is reserved
  for future per-run isolation; v1 usage is app and time-window scoped.

---

## Releasing

Releases are lockstep `v*` tags from this monorepo. A release tag drives
`php artisan kibble:split`, which splits `sink-contracts`, `sink-client`, and
`sink-server` to their read-only mirrors and publishes the packages. Keep
inter-package constraints on the same major.

---

## Deploying on Laravel Cloud

Run one isolated Laravel Cloud environment per client: the Sink app, one Postgres
database, Redis, one object-storage bucket, a web instance, a managed queue, and
the scheduler. The scheduler runs `sink:maintain`, which runs `sink:prune`; Redis
workers drain parse jobs.

> **Using a coding agent? Let the skill do it.** This repo ships a
> [`provisioning-sink-on-cloud`](.claude/skills/provisioning-sink-on-cloud/SKILL.md)
> skill that provisions Postgres, Redis, object storage, web, queue, and scheduler;
> wires Sink configuration; deploys; migrates; runs `create-admin`; and issues the
> first source-app token.

Required production environment:

- `DB_CONNECTION=pgsql` plus normal `DB_*` values.
- Optional `SINK_DB_*` values when the Sink metadata connection should differ from
  the default database; otherwise the `sink` connection mirrors the app default.
- Object-storage credentials and `SINK_DISK` for raw MIME and attachment bytes.
- `QUEUE_CONNECTION=redis` and `SINK_QUEUE_CONNECTION=redis` for parse jobs.
- `SINK_RETENTION_DAYS`, default `7`, plus optional `SINK_MAX_MESSAGES` and
  `SINK_MAX_TOTAL_BYTES` caps.
- `SINK_MCP_PATH`, default `/mcp`, and `SINK_MCP_LOCAL_NAME`, default `sink`.
- App auth secrets and bearer tokens managed by `artisan-build/built-for-cloud`.

Do not enable a Cloud-managed mail integration for the Sink app. Sink is the inbox.

## Prefer not to operate it?

Running Sink yourself means an isolated environment per client — Postgres, Redis, object
storage, a managed queue, the scheduler running `sink:maintain`, admin invitations,
retention caps, and server-first upgrades. It's all documented because fork-and-own is a
first-class path.

If you'd rather just have a persistent, shared staging inbox that's always there,
**[Artisan Build will run Sink in your own Laravel Cloud
account](https://artisan.build/start?ref=gh-sink-deploy)** — your data, your bucket,
capture-only — and keep it current. A team mail trap without the operational tail.

## Adding a source app

On the Sink server, issue a source application token locally with the
`artisan-build/built-for-cloud` command:

```shell
php artisan token:create <label>
```

The command prints the plaintext token once and stores only its hash in
`api_tokens`. Rotate, revoke, list, and inspect usage with `token:rotate`,
`token:revoke`, `token:list`, and `token:usage`.

In the source Laravel app:

```shell
composer require artisan-build/sink-client
php artisan sink:install --url=<SINK_URL> --token=<token>
```

Then opt in explicitly:

```dotenv
MAIL_MAILER=sink
SINK_URL=<SINK_URL>
SINK_TOKEN=<token>
```

Installing the package alone does not change your mailer. Sink only captures mail
when `MAIL_MAILER=sink` is selected. The production fuse refuses to construct the
transport in `production` unless `SINK_ALLOW_PRODUCTION=true`, preventing Sink from
silently swallowing production mail.

> **Using a coding agent in the source app?** `sink-client` ships a
> `configuring-sink-client` skill under
> `vendor/artisan-build/sink-client/skills/configuring-sink-client/` once installed.
> Point your agent at it and ask it to configure the Sink client. It covers
> `composer require`, `sink:install`, setting `MAIL_MAILER=sink`, the production
> fuse, token creation by the Sink operator, verifying a test send arrives, and
> troubleshooting.

## Connecting a coding agent (MCP)

The HTTP MCP server is registered at `SINK_MCP_PATH`, default `/mcp`, and requires
`Authorization: Bearer <token>`. Requests without a resolving bearer token fail
closed with `401`. For local Claude Code use, the same server is registered as a
stdio MCP server under `SINK_MCP_LOCAL_NAME`, defaulting to `sink`.

Sink's MCP server is body-blind: no tool returns rendered or raw body content.
Agents get envelope metadata, recipients, headers, links, attachment metadata,
counts, and boolean body assertions.

MCP tools:

- `list_apps` — source apps that have sent, with counts and last-seen.
- `list_recent` — recent message metadata. No body.
- `count_messages` — count matching messages by app, subject, recipient, time
  window, and stream.
- `recipients` — recipient addresses matching a filter.
- `assert_count` — pass/fail count assertion with actual vs expected.
- `stats` — grouped counts by subject, app, or recipient domain.
- `message_detail` — full metadata for one message, including headers, links,
  attachment metadata, sizes, and timestamps. No body.
- `links` — normalized extracted URLs for one message.
- `body_matches` — returns only a boolean and match count for a pattern. Never the
  matched text.
- `purge` — the only mutating tool; deletes messages matching an explicit scope and
  refuses unscoped wipes.

## Upgrading

Upgrade the Sink server first, run its migrations, and then update source app
clients. Clients can run:

```shell
php artisan sink:update
```

The command derives `/capabilities` from `SINK_URL`, stripping a trailing `/ingest`
when present, and reports whether the client's envelope major is inside the
server's supported range. If a source app sends an envelope newer than the server
understands, `/ingest` returns a 4xx upgrade message instead of accepting it.

---

## Out of scope (v1 non-goals)

- **No SMTP server.** HTTP ingest via the `sink` transport only.
- **No capture-and-forward / BCC archive.** Capture-only; nothing is delivered.
- **No per-run streams in v1.** The field is reserved and nullable, but v1 scopes
  by app and time window.
- **No agent body access.** MCP assertion tools only; humans view bodies in the UI.
- **No open registration / no ownership-handoff UI.** Admins invite users; handoff
  is an operator task.
- **No deliverability or spam scoring.** That is Mailtrap's domain.

---

## Managed by Artisan Build

Sink is built and maintained by [Artisan Build](https://artisan.build). Fork it and own it,
or [have us deploy and maintain it](https://artisan.build/start?ref=gh-sink-footer) in your
Cloud account.

## License

Sink is open-sourced software licensed under the [MIT license](LICENSE).
