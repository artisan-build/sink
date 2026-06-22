# sink-server

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The **receive side** of Sink. It is consumed by the Sink app and will provide ingest, parse,
storage, prune, the inbox UI, and the body-blind MCP server.

This scaffold only provides package registration and config wiring. Ingest, routes, migrations,
UI, and MCP tools land in later build steps.

## Installation

```bash
composer require artisan-build/sink-server
```

This package is consumed by the Sink app. See the
[Sink app README](https://github.com/artisan-build/sink) for environment setup.

## License

MIT. See [LICENSE](LICENSE).
