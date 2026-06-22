# sink-contracts

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/sink`](https://github.com/artisan-build/sink) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

The versioned wire envelope shared between `sink-client` and `sink-server`.

This package is intentionally tiny: it is the single place Sink's wire compatibility will live.
Envelope DTOs are implemented in the next build step.

## Installation

```bash
composer require artisan-build/sink-contracts
```

You usually don't install this directly; it arrives as a dependency of `sink-client` or
`sink-server`.

## License

MIT. See [LICENSE](LICENSE).
