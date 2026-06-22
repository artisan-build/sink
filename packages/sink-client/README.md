# sink-client

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The **send side** of Sink. It provides the Laravel `sink` mail transport that captures outbound
messages and posts them to a self-hosted Sink server instead of delivering them anywhere.

## Installation

```bash
composer require artisan-build/sink-client
```

Then configure the Sink server URL and token:

```bash
php artisan sink:install --url=https://sink.example.test --token=your-token
```

Enable capture explicitly in the host app:

```dotenv
MAIL_MAILER=sink
SINK_URL=https://sink.example.test
SINK_TOKEN=your-token
```

Installing the package alone does not change your default mailer. Sink only captures mail when the
application selects `MAIL_MAILER=sink`.

## Configuration

Publish the config if you need to tune retries, timeout, stream tagging, or message size limits:

```bash
php artisan vendor:publish --tag=sink-client-config
```

Available environment values:

- `SINK_URL`: Sink server base URL.
- `SINK_TOKEN`: Sink ingest token.
- `SINK_STREAM`: optional stream label for future per-run isolation.
- `SINK_RETRY_ATTEMPTS`: HTTP attempts before the send fails visibly. Default `3`.
- `SINK_RETRY_BASE_MS`: exponential backoff base in milliseconds. Default `200`.
- `SINK_TIMEOUT`: per-attempt HTTP timeout in seconds. Default `15`.
- `SINK_MAX_MESSAGE_BYTES`: raw MIME size ceiling before attachments are dropped or headers-only capture is used. Default `10485760`.

## Production Fuse

Sink refuses to construct the transport in `production` unless you explicitly set:

```dotenv
SINK_ALLOW_PRODUCTION=true
```

This prevents production mail from being silently swallowed. If Sink cannot capture a selected
message after retries are exhausted, the send throws so queued mail jobs fail visibly.

## Compatibility

Check server/client envelope compatibility with:

```bash
php artisan sink:update
```

If your Sink server does not expose `/capabilities` yet, the command reports that gracefully.

## License

MIT. See [LICENSE](LICENSE).
