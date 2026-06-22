# sink-server

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The **receive side** of Sink. It is consumed by the Sink app and provides the ingest path,
metadata parsing, object storage, and retention pruning. The inbox UI and body-blind MCP server
land in later build steps.

## Installation

```bash
composer require artisan-build/sink-server
```

This package is consumed by the Sink app. See the
[Sink app README](https://github.com/artisan-build/sink) for environment setup.

## Ingest

`POST /ingest` accepts a `sink-contracts` envelope authenticated with `Authorization: Bearer <token>`.
Tokens are resolved through `artisan-build/built-for-cloud`. Valid envelopes are upserted by
`idempotency_key`, raw MIME is written to object storage, and a queued parse job extracts searchable
metadata.

`GET /capabilities` is unauthenticated and reports the supported envelope major versions.

## Storage

Raw MIME is stored on `SINK_DISK` at `raw/{app}/{idempotency_key}.eml`. Attachments are extracted by
the parse worker and stored at `attachments/{app}/{idempotency_key}/{n}-{filename}`. Body text is not
persisted in database columns; bodies remain only in the raw MIME object.

## Configuration

Publish the config with:

```bash
php artisan vendor:publish --tag=sink-server-config
```

Useful environment variables:

- `SINK_ROUTE_PREFIX` defaults to empty.
- `SINK_QUEUE_CONNECTION` selects the parse queue connection.
- `SINK_DISK` falls back to `FILESYSTEM_DISK`, then `local`.
- `SINK_DB_*` configures an explicit Postgres `sink` connection. When omitted, `sink` mirrors the
  app default database connection so local sqlite and CI migrations keep working.
- `SINK_RETENTION_DAYS` defaults to `7`.
- `SINK_MAX_MESSAGES` optionally caps retained message count.
- `SINK_MAX_TOTAL_BYTES` optionally caps retained raw MIME bytes.

## Retention

`sink:maintain` runs `sink:prune`. The service provider schedules it hourly. Pruning deletes expired
messages and object-storage blobs, then enforces optional message-count and byte caps oldest-first.

## License

MIT. See [LICENSE](LICENSE).
