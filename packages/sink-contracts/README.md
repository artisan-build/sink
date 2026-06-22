# sink-contracts

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The versioned wire envelope shared between `sink-client` and `sink-server`.

This package is intentionally tiny: it is the single place Sink's wire compatibility will live.

The package is dependency-light by design. The idempotency key is an opaque client-supplied string;
this package does not validate or mint ULIDs.

## Envelope

`ArtisanBuild\SinkContracts\Envelope` is the wire DTO that both `sink-client` and `sink-server`
share. It serializes to JSON with these snake-case keys, in this order:

- `envelope_version` — integer major version. Current version: `1`.
- `idempotency_key` — client-minted opaque unique key for this send.
- `sent_at` — client-observed ISO-8601 timestamp string.
- `stream` — nullable string, reserved for future per-run isolation. V1 senders leave this `null`,
  but the key is always present on the wire.
- `message` — base64-encoded raw RFC-822 MIME string. Sink contracts treat it as opaque bytes.
- `truncation` — one of `none`, `attachments_dropped`, or `headers_only`. Defaults to `none`.

There is no `app` field in the envelope. The server resolves the source application from the bearer
token used to ingest the message.

## Compatibility

The envelope evolves additively within a major version. New optional fields may be added, but
existing fields are never removed or repurposed. Parsers therefore ignore unknown extra keys and
supply defaults for absent optional keys.

Breaking wire changes require a new major version so servers can reject senders that are ahead with
a loud 4xx before attempting to parse the payload.

## Installation

```bash
composer require artisan-build/sink-contracts
```

You usually don't install this directly; it arrives as a dependency of `sink-client` or
`sink-server`.

## License

MIT. See [LICENSE](LICENSE).
