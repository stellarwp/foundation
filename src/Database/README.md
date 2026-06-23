# Foundation Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-database
```

## Overview

Foundation Database is a WordPress-backed database package. It provides a small migration runner, migration and table collections, `wpdb`/`dbDelta` schema services, a database-backed lock, and a WP-CLI migration command.

This package intentionally targets WordPress runtime APIs instead of acting as a generic database abstraction. Migration classes depend on a small schema contract so application packages can define migration behavior without calling `wpdb` directly.

## Registering The Provider

Register `DatabaseProvider` in the application container when the project needs Foundation-managed migrations:

```php
use StellarWP\Foundation\Database\DatabaseProvider;

$container->register(DatabaseProvider::class);
```

The provider registers:

- `StellarWP\Foundation\Database\Database`
- `StellarWP\Foundation\Database\Contracts\Database`
- `StellarWP\Foundation\Database\Schema`
- `StellarWP\Foundation\Database\Table\Collection`
- `StellarWP\Foundation\Database\Table\Tables\MigrationTable`
- `StellarWP\Foundation\Database\Table\Tables\LockTable`
- `StellarWP\Foundation\Database\Contracts\Repository` for the migration ledger
- `StellarWP\Foundation\Database\Migration\Runner`
- `StellarWP\Foundation\Database\Lock\DatabaseLock` for the migration runner

By default, WordPress tables are named:

- `<wp_prefix>nexcess_foundation_migrations`
- `<wp_prefix>nexcess_foundation_locks`

Configure these through the Foundation config keys `database.migrations_table` and `database.locks_table` when an application needs different table names. Configured table names are treated as full table names, so include the WordPress prefix yourself when overriding them.

## Running Queries

Application services can inject `StellarWP\Foundation\Database\Contracts\Database` when they need to run queries:

```php
use StellarWP\Foundation\Database\Contracts\Database;

final readonly class ReportRepository
{
    public function __construct(
        private Database $database
    ) {
    }

    public function published(): array
    {
        return $this->database
            ->table('reports')
            ->select('id', 'title')
            ->where('status', '=', 'published')
            ->orderBy('id', 'DESC')
            ->limit(25)
            ->get();
    }
}
```

Queries can be inspected before they are executed:

```php
$query = $database
    ->table('reports')
    ->where('status', '=', 'published')
    ->limit(25);

$query->toSql();
$query->bindings();
$query->toPreparedSql();
```

## Defining Migrations

Migrations implement `StellarWP\Foundation\Database\Contracts\Migration`:

```php
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

final readonly class CreateReportsTable implements Migration
{
    public function id(): string
    {
        return '2026_06_23_000001_create_reports_table';
    }

    public function up(Schema $schema): void
    {
        $schema->createOrUpdate(
            sprintf(
                'CREATE TABLE %s (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                title varchar(191) NOT NULL,
                PRIMARY KEY  (id)
            );',
                $schema->quoteIdentifier('wp_reports')
            )
        );
    }

    public function down(Schema $schema): void
    {
        $schema->execute(sprintf(
            'DROP TABLE IF EXISTS %s',
            $schema->quoteIdentifier('wp_reports')
        ));
    }
}
```

Applications should bind `DatabaseProvider::MIGRATIONS` to the ordered list of `Migration` instances they want the runner to manage. If migrations are bound before registering `DatabaseProvider`, the provider will preserve the existing binding.

Foundation's own migration infrastructure tables implement `StellarWP\Foundation\Database\Contracts\Table` and are wired into `Table\Collection`. Applications can use the same `Table` contract for their own custom tables. When a table should be recorded in the migration ledger, wrap it in `StellarWP\Foundation\Database\Table\CreateTable` and add that migration instance to `DatabaseProvider::MIGRATIONS`.

## WP-CLI

The package includes a `migrate` command class for projects using `stellarwp/foundation-wpcli`. Register it from the consuming application's CLI provider with the rest of the project's commands.

Available flags:

- `--run` runs pending migrations.
- `--rollback` rolls back the latest migration batch.
- `--refresh` rolls back all known migrations and runs them again.
- `--drop` drops the migrations and lock tables after confirmation.
- `--create-table` creates the migrations and lock tables without running migrations.
- `--yes` skips confirmation prompts for destructive actions.

Running the command without a flag prints migration status.
