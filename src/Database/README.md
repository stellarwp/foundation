# Foundation Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Database provides a shared Doctrine DBAL connection, application tables,
and managed transactions for WordPress applications.

## Installation

Requires PHP 8.3, mysqli, and Doctrine DBAL **4.5.x** (`~4.5.0`).

Requires MySQL **5.7.9+** or MariaDB **10.4.3+**, matching
[Doctrine DBAL 4.5 platform support](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.5/reference/platforms.html).

```shell
composer require stellarwp/foundation-database
```

For schema history and upgrade commands, install [Foundation Migrations](https://foundation.nexcess.dev/components/migrations/).

## Documentation

See the [Foundation Database documentation](https://foundation.nexcess.dev/components/database/)
for configuration, query building, transactions, and testing.
