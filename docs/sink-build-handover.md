# Sink — Build Handover

**Self-hosted, unmetered staging/test mail capture for Laravel, on Laravel Cloud.**

Sink is a mail **trap** you fork and deploy to your own Laravel Cloud account. Drop the client
into any Laravel app, set `MAIL_MAILER=sink`, and every outbound message is captured to a
self-hosted inbox instead of being delivered — viewable by humans in a web UI and queryable by a
coding agent over MCP. Compute scales to zero, storage auto-purges, bursty sends ride a managed
queue.

> One mailer in, nothing out. No metering, no per-message bill, no third-party inbox holding your
> staging mail.

This document is the build spec. It is written to be handed to Claude Code and built straight
through. It mirrors the conventions already established in
[`artisan-build/hone`](https://github.com/artisan-build/hone) and
[`artisan-build/matte`](https://github.com/artisan-build/matte); read both repos' READMEs first —
Sink is the same machine with a different payload.

---

## Why Sink exists

Local mail catchers (Mailpit, MailHog) are single-developer and ephemeral — they live on a laptop
and forget everything on restart. Hosted sandboxes (Mailtrap) are metered, another vendor, and
another inbox holding your data. Neither fits a **team** that wants a persistent, shared,
agent-queryable staging inbox running on infrastructure it owns.

Sink is the self-hosted **floor**: a shared staging/test inbox on compute you control, billed only
for the Cloud resources it uses. Its differentiator over Mailpit is that it is persistent,
team-shared, and **agent-queryable** — an agent running feature tests can assert on outbound mail
over MCP. Its differentiator over Mailtrap is that it is yours, unmetered, and single-tenant.

**Positioning.** Mailpit is the right tool for a single developer's local loop, and Mailtrap is the
polished hosted product with deliverability scoring and team features. Sink is the floor for teams
that want a persistent shared staging trap on their own Cloud account, with a coding agent as a
first-class consumer. Outgrow it → Mailtrap is the upgrade. MIT licensed.

**Support posture.** Written for how Artisan Build uses it. Bugs get fixed; feature requests are a
fork away; client-specific features are not backfilled into the OSS release.

---

## Decisions (locked — do not re-litigate)

1. **Mail transport, not an SMTP server.** Scale-to-zero is HTTP-wake; a persistent SMTP listener
   is always-on and would kill the cost story. Sink is a Laravel mail transport that POSTs captured
   messages to an HTTP `/ingest`. Laravel-only is acceptable and intentional (Hone is too). No SMTP
   server in v1.
2. **Capture-only.** The transport delivers nothing. The fail-safe direction is "no mail went out,"
   never "staging mail reached real customers." No capture-and-forward/BCC mode in v1.
3. **Explicit activation + production fuse.** The transport activates only when `MAIL_MAILER=sink`
   is explicitly selected *and* `SINK_URL`/`SINK_TOKEN` are present. It additionally refuses to run
   when `APP_ENV=production` unless `SINK_ALLOW_PRODUCTION=true`, so it can never silently swallow
   production mail.
4. **Fail loud, with retry.** The client POSTs with retry-and-backoff (cold-start tolerant). On
   exhaustion it throws, so the source app's queued mail job fails visibly. It never silently drops
   a message it was asked to trap.
5. **Per-send idempotency key.** Unlike Matte, Sink does **not** dedupe by content — two identical
   messages are two distinct sends, and counting distinct sends is the point. The client mints a
   ULID per send; the server upserts on it so client retries collapse while genuine sends stay
   distinct. This is what makes "how many actually got sent" trustworthy.
6. **Server parses, client stays dumb.** The client ships opaque raw MIME + a thin envelope. The
   server parses subject/recipients/headers/links/attachment-metadata at ingest. All
   interpretation lives server-side in one upgradable place (same discipline as Hone's jsonb and
   Matte's opaque image bytes).
7. **Human UI, invitation-only.** Sink is the first suite app with a human interface. The UI shows
   rendered email (sandboxed). Auth is invitation-only: a `create-admin` command creates the first
   admin; the admin invites users; there is no open registration. No ownership-handoff UI — that's
   a TablePlus job.
8. **Agents are body-blind.** No MCP tool ever returns rendered or raw body text. Agents get
   envelope metadata, an extracted link list, and a `body_matches(id, pattern) → bool` assertion
   tool — assert on body content without receiving it. Humans see full bodies only in the UI.
9. **Storage follows Matte.** Raw MIME + attachments live in object storage; searchable metadata
   lives in Postgres. Retention default **7 days**, plus size caps. Pruned by a scheduled
   `sink:maintain`.
10. **Shared scaffolding into built-for-cloud.** Tokens already live there. `create-admin`,
    invitations, the UI auth gate, the generic `*:install` scaffold, and a deterministic
    cloud-CLI-wrapping provisioning installer are added there, consumed by Sink (and the other
    in-flight UI app).
11. **Conventions held.** Monorepo + three split packages + slim shell, `kibble:split`, lockstep
    releases, issues/PRs disabled on splits, versioned additive envelope, server-first upgrades,
    loud 4xx when a sender runs ahead. Even though Sink's contract is very thin, it keeps the same
    shape.

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

- **One isolated environment per client** on Laravel Cloud — its own compute, Postgres, Redis, and
  object-storage bucket, sized and billed per client. Single-tenant; isolation is environmental.
- **Unlimited source apps per environment**, tagged by the token that sent them (the `app`
  dimension), exactly like Hone.
- **Cross-boundary transport is HTTP**, not shared Redis — source apps are separate deployments.

---

## Repository layout

A **monorepo**. Three packages under `packages/`, each split read-only to its own repo and
published to Packagist. The **Sink app** at the root is a slim Laravel shell that wires
`sink-server`, hands out tokens, and stays thin so there's no Sink-specific business logic to drift.

| Package | Installed in | Role |
| --- | --- | --- |
| `artisan-build/sink-contracts` | both packages | The versioned wire envelope. The single place compatibility lives. Very thin. |
| `artisan-build/sink-client` | monitored apps | The send side: the `sink` mail transport, activation guards, retry/backoff, idempotency key, `sink:install` / `sink:update`. |
| `artisan-build/sink-server` | the Sink app | The receive side: ingest, parse, storage, prune, the human inbox UI, and the MCP server. |

Issues and PRs disabled on the three split repos; all development in the monorepo. Releases are
lockstep `v*` tags driving `php artisan kibble:split`, identical to Matte's `release.yml`. Keep
inter-package constraints on the same major (`sink-server` requires `sink-contracts: ^1.0`).

---

## `sink-contracts` — the envelope

Deliberately thin. The message bytes are opaque to the envelope; only the envelope is
version-sensitive.

Envelope fields:

- `envelope_version` — integer major. Evolves **additively within a major**; new optional fields
  only, never remove or repurpose.
- `idempotency_key` — client-minted ULID, unique per send. The server upserts on it.
- `sent_at` — client-observed ISO-8601 timestamp.
- `stream` — optional, nullable string. **Reserved** for future per-run isolation; v1 senders leave
  it null. Present in the contract now so adding it later is a no-op wire change.
- `message` — base64-encoded raw RFC-822 MIME (opaque).
- `truncation` — optional enum: `none` (default), `attachments_dropped`, `headers_only`. Set by the
  client when a message exceeds size limits (see Size limits). The send is always recorded even when
  the payload is trimmed, so counts stay correct.

The source app is **not** carried in the envelope — the server resolves it from the bearer token,
the same as Hone.

Wire:

- `POST {SINK_URL}/ingest`, `Authorization: Bearer <token>`, JSON envelope body.
- `202 Accepted` with the stored message id on success (including idempotent replays).
- `4xx` with an upgrade message when `envelope_version` is **newer** than the server supports
  ("your senders are ahead of this Sink instance — upgrade it").
- `401` when the token does not resolve.
- The server parses every older envelope major.

---

## `sink-client` — the transport

**Mechanism.** Register a `sink` transport in the package service provider via
`Mail::extend('sink', …)`, returning a Symfony transport whose `doSend()`:

1. Serializes the message to raw RFC-822 MIME.
2. Mints an idempotency ULID, builds the envelope (`sent_at`, `stream` from `SINK_STREAM` if set).
3. POSTs to `{SINK_URL}/ingest` with `Bearer {SINK_TOKEN}`.
4. Returns without delivering anywhere else. **Capture-only.**

Teams opt in with `MAIL_MAILER=sink` plus a `mailers.sink` config entry the package publishes.

**Activation guards (fail-closed):**

- Active only when `MAIL_MAILER=sink` is explicitly selected **and** `SINK_URL` + `SINK_TOKEN` are
  present. Presence alone never hijacks another mailer.
- **Production fuse:** if `APP_ENV === 'production'` and `SINK_ALLOW_PRODUCTION` is not truthy, the
  transport refuses to construct and throws a clear exception at boot. It cannot silently swallow
  production mail.

**Retry / backoff:**

- Default 3 attempts, exponential backoff with jitter, per-attempt timeout generous enough for a
  scale-to-zero cold start.
- On exhaustion: **throw**. The source app's queued mail job fails loudly and visibly. Never a
  silent drop.
- Configurable: `SINK_RETRY_ATTEMPTS`, `SINK_RETRY_BASE_MS`, `SINK_TIMEOUT`.

**Size limits (client-side):**

- `SINK_MAX_MESSAGE_BYTES` ceiling. Over the soft limit, strip attachment bytes and set
  `truncation: attachments_dropped` (headers + bodies still sent). Over a hard ceiling, send a
  headers-only stub with `truncation: headers_only`. The send is always recorded so counts are
  exact; only the payload shrinks.

**Commands:**

- `sink:install` — prompt-or-flags (`--url=`, `--token=`) for `SINK_URL` and `SINK_TOKEN`, write
  them to `.env`, print the `MAIL_MAILER=sink` instruction (and the production-fuse note), and pin
  `artisan-build/sink-client` to a clean caret major constraint. Mirrors `hone:install`.
- `sink:update` — derive `/capabilities` from `SINK_URL`, report whether the client's envelope major
  is inside the server's supported range. Mirrors `hone:update`.

Ships a `configuring-sink-client` skill (under
`vendor/artisan-build/sink-client/skills/configuring-sink-client/`) covering `composer require`,
`sink:install`, setting `MAIL_MAILER=sink`, the production fuse, verifying a test send arrives, and
troubleshooting.

> Positioning note for the README: Sink is for staging / integration / E2E where real rendering and
> a shared persistent inbox matter. It is **not** a replacement for `Mail::fake()` in unit tests.

---

## `sink-server` — ingest, parse, store, UI, MCP

**Ingest** (`/ingest`): bearer auth via built-for-cloud token resolution; envelope-version check
(loud 4xx when ahead); upsert by `idempotency_key`; raw MIME → object storage at a deterministic
key; enqueue a parse job; return `202`.

**Parse worker:** parse the MIME and write a metadata row to Postgres — subject, from, to/cc/bcc,
date, message-id, full header set, a **normalized extracted link list**, attachment metadata
(filename, mime, size — bytes go to object storage, not the metadata row), total size, source app
(from token), `truncation`, timestamps. **No content-hash dedupe.**

**Retention / prune:** scheduled `sink:maintain` (mirrors `hone:maintain`) runs `sink:prune`,
deleting metadata rows and their object-storage blobs older than `SINK_RETENTION_DAYS` (default
`7`), plus `SINK_MAX_MESSAGES` and `SINK_MAX_TOTAL_BYTES` caps. A bucket lifecycle rule is the
backstop.

**Human inbox UI** (invitation-only):

- Message list with filters: source app, recipient, subject, date range.
- Message view: rendered HTML in a **sandboxed iframe with a strict CSP**, plus raw source,
  full headers, the extracted link list, and downloadable attachments.
- Purge controls (scoped).
- Admin: user invitations. No open registration, no ownership-handoff UI.
- Auth provided by built-for-cloud (`create-admin`, invitations, the auth gate).

**MCP server:** registered at `SINK_MCP_PATH` (default `/mcp`), `Authorization: Bearer` required,
fail-closed `401` otherwise; also registered as a stdio server under `SINK_MCP_LOCAL_NAME` (default
`sink`) for local Claude Code. **Every tool is read-only metadata except the one scoped purge tool,
and no tool returns body content.**

---

## MCP tool catalogue (body-blind, assertion-oriented)

Discovery:

- `list_apps` — source apps that have sent, with counts and last-seen.
- `list_recent(filters)` — recent message **metadata** (subject, from, to, sent_at, app, size,
  attachment names, link count). No body.

Counting / assertion — the headline use case:

- `count_messages(filters)` — count matching `{app, subject (exact/contains), recipient, since,
  until, stream}`. Answers *"the staging env sent a 'new event in chicago' campaign to ~5000 — how
  many actually went?"*
- `recipients(filters)` — the recipient addresses matching a filter, so an agent can find which of
  the expected recipients are missing. (Addresses are necessary, irreducible metadata.)
- `assert_count(filters, expected)` — sugar over `count_messages`: returns pass/fail + actual vs
  expected for clean test assertions.
- `stats(group_by, filters)` — counts grouped by subject / app / recipient-domain over a window.

Per-message (still body-blind):

- `message_detail(id)` — full metadata for one message: headers, link list, attachment metadata,
  sizes, timestamps. No rendered or raw body.
- `links(id)` — the normalized extracted URL list for a message (the useful "does it link to the
  right host" assertion input).
- `body_matches(id, pattern)` — boolean: does the text or HTML body match the pattern. Returns
  true/false (and a match count), never the matched text. Assert on body content without receiving
  it.

Mutating (the only write tool):

- `purge(filters)` — agent-triggered purge for test isolation/reset. Requires an explicit scope
  (app / subject / recipient / time window); refuses an unscoped wipe by default. This is the one
  tool that deletes.

---

## built-for-cloud additions

Already provides: `token:create` / `rotate` / `revoke` / `list` / `usage`, `FALLBACK_TOKEN`, hashed
`api_tokens`. Sink consumes those unchanged. Add, as shared utilities consumed by Sink and the other
in-flight UI app:

- **`create-admin`** — creates the first admin user with full authority; refuses if an admin already
  exists unless `--force`.
- **Invitations** — invitation model + flow; invitation-only registration, no open signup.
- **UI auth gate** — middleware/guard the suite's UI apps reuse, so the auth story is identical
  across them and doesn't drift.
- **Generic installer scaffold** — lift the `*:install` pattern (prompt-or-flags → write env → pin
  caret constraint) out of the client packages so future apps inherit it. Sink is the first consumer
  alongside its own `sink:install`.
- **Cloud provisioning installer** — a deterministic wrapper around the `cloud` CLI that runs every
  step an agent **can** run unattended, and explicitly hands off the interactive/blocking steps
  (login, browser/2FA confirmations) to the human with copy-paste instructions. This is the
  backbone of the `provisioning-sink-on-cloud` skill: the agent does the deterministic work, the
  human runs the handful of commands that always block unattended agents.

---

## Data model

Postgres (the `sink` connection; in the default app setup `SINK_DB_*` points at the same database
as `DB_*`):

- `messages` — `id`, `idempotency_key` (unique), `app`, `stream` (nullable), `subject`,
  `from_address`, `from_name`, `message_id`, `sent_at`, `received_at`, `size_bytes`,
  `attachment_count`, `link_count`, `truncation`, `raw_object_key`.
- `message_recipients` — `message_id`, `kind` (`to`/`cc`/`bcc`), `address`, `name`. (Separate table
  so `recipients`/`count_messages` filter efficiently.)
- `message_headers` — `message_id`, `name`, `value`.
- `message_links` — `message_id`, `url` (normalized), `label`.
- `message_attachments` — `message_id`, `filename`, `mime`, `size_bytes`, `object_key`.

Object storage (bucket): raw MIME at `raw_object_key`; attachment bytes at `object_key`. Both swept
by `sink:prune` and a bucket lifecycle backstop.

Plus the built-for-cloud `api_tokens`, `users`, and `invitations` tables.

---

## Configuration

**Source app (`sink-client`):**

`SINK_URL`, `SINK_TOKEN`, `MAIL_MAILER=sink`, `SINK_STREAM` (optional, reserved),
`SINK_ALLOW_PRODUCTION` (default false), `SINK_RETRY_ATTEMPTS` (3), `SINK_RETRY_BASE_MS`,
`SINK_TIMEOUT`, `SINK_MAX_MESSAGE_BYTES`.

**Sink app (`sink-server`):**

`DB_CONNECTION=pgsql` + `DB_*`; `SINK_DB_*` (usually copied from `DB_*`); object-storage bucket
credentials; `QUEUE_CONNECTION=redis` + `SINK_QUEUE_CONNECTION=redis`; `SINK_RETENTION_DAYS` (7),
`SINK_MAX_MESSAGES`, `SINK_MAX_TOTAL_BYTES`; `SINK_MCP_PATH` (`/mcp`), `SINK_MCP_LOCAL_NAME`
(`sink`); `FALLBACK_TOKEN` (bootstrap); app auth secrets. Do not enable any Cloud-managed mail
integration for the Sink app.

---

## Deploying on Laravel Cloud

One isolated environment per client: the Sink app, one Postgres database, Redis, and one
object-storage bucket. Run the scheduler (schedules `sink:maintain`) and a Redis worker cluster
(drains parse jobs).

The `provisioning-sink-on-cloud` skill (built on built-for-cloud's cloud-CLI wrapper) provisions
Postgres, Redis, the bucket, a web instance, a managed queue, and the scheduler; wires `SINK_*`;
deploys; migrates; runs `create-admin`; and issues the first source-app token — running everything
deterministically and pausing only for the interactive Cloud steps the human must run. It
specializes the Cloud CLI's generic `deploying-laravel-cloud` skill, the same way Hone and Matte do.

Adding a source app:

```shell
# On the Sink server (via built-for-cloud):
php artisan token:create <app-id>

# In the source Laravel app:
composer require artisan-build/sink-client
php artisan sink:install        # prompts for SINK_URL + token; reminds you to set MAIL_MAILER=sink
```

---

## Build order (sequenced for Claude Code)

1. **Scaffold** the monorepo: slim shell app + `packages/sink-{contracts,client,server}`, shared
   tooling (Pint, PHPStan/Larastan, Rector, Pest, ide-helper, `boost.json`, `AGENTS.md`,
   `CLAUDE.md`), `kibble:split` + lockstep `release.yml`, splits with issues/PRs disabled.
2. **`sink-contracts`**: the envelope + version rules. Thin.
3. **`sink-client`**: transport, activation guards, production fuse, retry/backoff, idempotency key,
   size-limit handling, `sink:install` / `sink:update`. Testable against a stub ingest server.
4. **`sink-server` ingest path**: `/ingest`, token auth, envelope check, upsert, object-storage
   write, queue, parse worker, the data model, `sink:maintain` / `sink:prune`.
5. **built-for-cloud**: `create-admin`, invitations, UI auth gate (reuse existing tokens).
6. **`sink-server` UI**: invitation-only inbox, sandboxed render, headers/links/attachments/raw,
   scoped purge.
7. **`sink-server` MCP**: the body-blind assertion tool catalogue.
8. **built-for-cloud cloud provisioning installer** + `provisioning-sink-on-cloud` and
   `configuring-sink-client` skills.
9. **Docs**: README in the Hone/Matte shape; `SECURITY.md` (Sink sits on an ingest path); the
   compatibility/versioning and releasing sections.

---

## Out of scope (v1 non-goals)

- **No SMTP server.** HTTP ingest via the `sink` transport only.
- **No capture-and-forward / BCC-archive.** Capture-only.
- **No per-run streams** — the envelope reserves the field; tagging is by source app + time window
  in v1.
- **No agent access to body content** — assertion tools only.
- **No open registration / no ownership-handoff UI.**
- **No deliverability or spam scoring** — Mailtrap's domain.

## Deferred (candidate v2)

- Per-run **streams** for parallel-CI isolation (additive envelope field already reserved).
- An always-on **SMTP edge tier** (separate, non-scale-to-zero) for non-Laravel senders.
- Spam/deliverability analysis, link-click simulation.

---

## License

MIT.
