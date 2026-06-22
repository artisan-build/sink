# sink-client

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The **send side** of Sink. It will provide the Laravel mail transport that captures outbound
messages and posts them to a self-hosted Sink server.

This scaffold only provides package registration and config wiring. The mail transport lands in a
later build step.

## Installation

```bash
composer require artisan-build/sink-client
```

## License

MIT. See [LICENSE](LICENSE).
