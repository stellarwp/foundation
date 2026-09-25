# Foundation Migrations

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Declarative WordPress migrations with automatic file discovery, SQL preview,
version history, and database advisory locking.

## Installation

Requires PHP 8.3 and MySQL **5.7.9+** or MariaDB **10.4.3+**, matching
[Doctrine DBAL 4.4 platform support](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/platforms.html).

```shell
composer require stellarwp/foundation-migrations
```

Register `DatabaseProvider` followed by `MigrationsProvider`. Register `WPCliProvider`
first to enable the `migrate:*` WP-CLI commands, or inject `Migrator` for programmatic upgrades.

## Documentation

See the [Migrations guide](https://foundation.nexcess.dev/components/migrations/)
for setup, generating tables and migrations, deployment, adopting existing installations,
rollback, and repairing history after interrupted upgrades.
