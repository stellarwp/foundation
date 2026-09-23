# Foundation Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Database provides a shared Doctrine DBAL connection, application tables,
managed transactions, and declarative migrations for WordPress applications.

## Installation

```shell
composer require stellarwp/foundation-database
```

The package includes `stellarwp/foundation-wpcli` for its migration command. Register
`WPCliProvider` to enable it, or use `Migrator` programmatically.

## Documentation

See the [Foundation Database documentation](https://foundation.nexcess.dev/components/database/)
for configuration, migrations, query building, and testing.
