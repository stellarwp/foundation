# Foundation Lock Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Database-backed expiring locks for Foundation. Coordinate requests and workers
through the same primary WordPress database using the shared Foundation Lock
contract and managed lock operations.

## Installation

```shell
composer require stellarwp/foundation-lock-database
```

## Documentation

See the [Database Lock guide](https://foundation.nexcess.dev/components/lock/database/)
for provider registration, application backend selection, storage initialization,
configuration, and testing. Start with the [Lock guide](https://foundation.nexcess.dev/components/lock/) to compare backends and use `LockOperation`.
