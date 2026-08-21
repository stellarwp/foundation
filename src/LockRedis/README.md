# Foundation Lock Redis

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Redis-backed expiring locks for Foundation. This package implements the shared
`stellarwp/foundation-lock` contract using atomic Redis acquisition, release,
and refresh operations.

## Installation

```shell
composer require stellarwp/foundation-lock-redis
```

Install one supported Redis client:

```shell
composer require "predis/predis:>=3.0 <4.0"
```

Alternatively, install and enable the PhpRedis extension.

## Documentation

See the [Foundation Lock guide](https://foundation.stellarwp.com/components/lock/)
for backend selection, Redis configuration, container registration, lease
handling, failure behavior, and usage examples.
