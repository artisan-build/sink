# Security Policy

Sink sits on an untrusted mail ingest path and stores captured staging/test email.
We take security reports seriously.

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Instead, report privately to **security@artisan.build**. Include:

- A description of the vulnerability and its impact.
- Steps to reproduce, or a proof of concept.
- The affected package(s) and version(s): `sink-contracts`, `sink-client`,
  `sink-server`, or the Sink app itself.

You will receive an acknowledgement as soon as we are able, and we will keep you
informed as we work on a fix. We ask that you give us a reasonable window to
remediate before any public disclosure.

## Scope

This policy covers the `artisan-build/sink` monorepo and its three split packages
(`sink-contracts`, `sink-client`, `sink-server`).

## Security model

- **Bearer-token auth.** `POST /ingest` and the HTTP MCP endpoint at
  `SINK_MCP_PATH` (default `/mcp`) require `Authorization: Bearer <token>`.
  Requests without a resolving token fail closed with `401`.
- **Production fuse.** The `sink-client` transport refuses to run in `production`
  unless `SINK_ALLOW_PRODUCTION=true`, so Sink cannot silently swallow production
  mail by accident.
- **Capture-only.** Sink records messages; it never delivers, forwards, or BCCs
  captured mail.
- **Sandboxed render.** Captured HTML is rendered for humans in a sandboxed iframe
  with a strict `Content-Security-Policy`.
- **Body-blind MCP.** No MCP tool returns rendered or raw body content.
  `body_matches` returns only a boolean and match count, never matched text.
- **Invitation-only auth.** The first admin is created with `create-admin`; admins
  invite users. There is no open registration flow.
- **Per-app idempotency scoping.** Ingest upserts by source app and idempotency key,
  so retries collapse within the app that sent the message without crossing token
  boundaries.

## Supported versions

Sink is maintained for how [Artisan Build](https://artisan.build) uses it.
Security fixes land on the latest release line.

| Version | Supported |
| --- | --- |
| Latest | Yes |
| Older releases | No |
