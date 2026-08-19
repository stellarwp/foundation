# Foundation Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-database
```

## Overview

Foundation Database is a WordPress-backed database package. It provides a configured migrator, migration runner, migration and table collections, `wpdb`/`dbDelta` schema services, a database-backed lock, and a WP-CLI migration command.

This package intentionally targets WordPress runtime APIs instead of acting as a generic database abstraction. Migration classes depend on a small schema contract so application packages can define migration behavior without calling `wpdb` directly.

Foundation Database requires WordPress 6.2 or newer because its query layer
uses the `%i` identifier placeholder. Database-backed locks additionally require
fractional-second temporal values: MySQL 5.6.4 or newer, or MariaDB 5.3 or
newer.

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
- `StellarWP\Foundation\Database\Migration\Store`
- `StellarWP\Foundation\Database\Migration\Runner`
- `StellarWP\Foundation\Database\Migration\Migrator`
- `StellarWP\Foundation\Database\Lock\DatabaseLock` for the migration runner

By default, WordPress tables are named:

- `<wp_prefix>nexcess_foundation_migrations`
- `<wp_prefix>nexcess_foundation_locks`

Configure these through the Foundation config keys `database.migrations_table` and `database.locks_table` when an application needs different table names. Configured table names are treated as exact full table names and are not passed through `Database::tableName()`, so include the WordPress prefix yourself when overriding them.

Example `config.php` values:

```php
<?php declare(strict_types=1);

return [
	'database' => [
		// Leave empty or omit these keys to use the default WordPress-prefixed names.
		'migrations_table' => $_ENV['FOUNDATION_DATABASE_MIGRATIONS_TABLE'] ?? '',
		'locks_table'      => $_ENV['FOUNDATION_DATABASE_LOCKS_TABLE'] ?? '',
		'lock_name'        => $_ENV['FOUNDATION_DATABASE_LOCK_NAME'] ?? 'foundation-database-migrations',
		'lock_ttl'         => (int) ($_ENV['FOUNDATION_DATABASE_LOCK_TTL'] ?? 300),
	],
	'wpcli'    => [
		'command_prefix' => $_ENV['FOUNDATION_WPCLI_COMMAND_PREFIX'] ?? 'nx',
	],
];
```

If overriding table names, provide the full table name:

```php
return [
	'database' => [
		'migrations_table' => 'wp_custom_foundation_migrations',
		'locks_table'      => 'wp_custom_foundation_locks',
	],
];
```

`database.lock_ttl` must cover the complete migration operation. The migration
runner reports unconfirmed ownership if an otherwise successful operation
cannot release its ownership token. Increase the TTL for long-running
migrations; the runner does not refresh the lease while a migration is
executing.

## Using Database Locks

`DatabaseProvider` registers `DatabaseLock` for direct use and uses it for
migrations, but intentionally does not select it as the application's global
lock implementation. Register `DatabaseProvider` before an application provider
that chooses the database implementation:

```php
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\Lock\DatabaseLock;
use StellarWP\Foundation\Lock\Contracts\Lock;

$this->container->bind(
	Lock::class,
	static fn (C $c): DatabaseLock => $c->get(DatabaseLock::class)
);
```

`DatabaseLock` uses the database server's UTC clock for acquisition, expiration,
refresh, and release decisions. This keeps competing PHP processes on one
authoritative timeline even when their host clocks differ.

Database lock names are byte-exact and may not exceed 191 bytes.

Lock writes and their verification reads must use the same authoritative
primary connection. Standard `wpdb` satisfies this requirement. Projects with a
database drop-in that routes `SELECT` queries to replicas must pin lock-table
reads to the writer; otherwise replication lag can make a successful acquisition
or refresh fail closed.

The database lock table must exist before application services acquire locks.
Prepare it during activation or deployment through the configured migrator:

```php
use StellarWP\Foundation\Database\Migration\Migrator;

$container->get(Migrator::class)->prepare();
```

Preparing the migration store also reconciles existing internal tables with
their current definitions.

Projects using the included WP-CLI command can instead run:

```bash
wp nx migrate --prepare
```

Once configured, application services should depend on the shared `Lock`
contract. See the
[Foundation Lock usage examples](https://github.com/stellarwp/foundation-lock#preventing-duplicate-work)
for resource-scoped acquisition, release, and lease handling.

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

Applications should add migrations to `DatabaseProvider::MIGRATIONS` with `mergeArrayVar()` so multiple providers/packages can contribute migrations:

```php
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;

$this->container->mergeArrayVar(DatabaseProvider::MIGRATIONS, static fn (C $c): array => [
	$c->get(CreateReportsTable::class),
]);
```

If migrations are added before registering `DatabaseProvider`, the provider will preserve the existing values. Other providers may also add migrations after `DatabaseProvider` is registered, as long as they do so before the migration collection or migrator is resolved.

Register contributing providers in the order their migrations must run. The migration collection preserves registration order, so a migration that depends on an earlier schema or data change must be contributed after that dependency.

Application feature tables should usually be represented by migrations. If a table only needs normal create/drop behavior, define it with `StellarWP\Foundation\Database\Contracts\Table`, wrap it in `StellarWP\Foundation\Database\Table\CreateTable`, and add that migration instance to `DatabaseProvider::MIGRATIONS`.

```php
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

final readonly class ReportsTable implements Table
{
	public const string ID = 'reports_table';

	public function __construct(
		private Database $database
	) {
	}

	public function id(): string {
		return self::ID;
	}

	public function name(): string {
		return $this->database->tableName('reports');
	}

	public function definition(): TableDefinition {
		return TableDefinition::for($this)
			->bigIncrements('id')
			->string('status', 20)->default('draft')
			->longText('payload')
			->dateTime('published_at')->nullable()
			->tinyInteger('failed', 1)->unsigned()->default(false)
			->index('status', 'status');
	}
}
```

```php
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Table\CreateTable;

$this->container->mergeArrayVar(DatabaseProvider::MIGRATIONS, static fn (C $c): array => [
	new CreateTable($c->get(ReportsTable::class)), // ReportsTable implements Contracts\Table.
]);
```

Application code that needs to run migrations should inject `StellarWP\Foundation\Database\Migration\Migrator`. It is the configured entry point for preparing the migration store, running pending migrations, rolling back, refreshing, dropping migration storage, and reading migration status.

```php
use StellarWP\Foundation\Database\Migration\Migrator;

final readonly class PluginUpdater
{
	public function __construct(
		private Migrator $migrator
	) {
	}

	public function update(): void {
		$this->migrator->run();
	}
}
```

`run()`, `rollback()`, and `refresh()` prepare the migration store automatically before executing migrations.

Registering `DatabaseProvider` does not execute migrations. Call `Migrator::run()` from the application's activation or version-update lifecycle, or run `wp nx migrate --run` during deployment. Completed migration IDs are skipped on later runs. Because migration changes and their ledger updates are not one atomic operation, write `up()` and `down()` methods so they can recover from retries after partial work or failed ledger writes.

## Evolving Tables

`TableDefinition` and `Schema::createOrUpdate()` use WordPress `dbDelta()` to create tables and reconcile changes that `dbDelta()` supports, such as adding columns and indexes. They should not be relied on to remove or rename columns, replace indexes, manage foreign keys, or backfill data.

Use an explicit, versioned migration for destructive or data-dependent changes. Such migrations can inspect table and index state with `Schema::hasTable()` and `Schema::hasIndex()`; inject `Database` when column inspection through `Database::columnExists()` is required. Use `Schema::execute()` or focused helpers such as `dropIndex()` for the required SQL. Make rollback behavior explicit; throw `IrreversibleMigration::forMigration(self::ID)` when a migration cannot be safely reversed.

## Generators

If the project also installs `stellarwp/foundation-cli` as a development dependency, scaffold a database provider, table class, and matching migration in a consuming WordPress project:

```bash
vendor/bin/foundation make:database-provider
vendor/bin/foundation make:database-table Reports_Table
vendor/bin/foundation make:database-migration Create_Reports_Table
```

The provider generator reads the project's first `autoload.psr-4` namespace from `composer.json` and writes `src/Database/Provider.php` by default. Register the Foundation `DatabaseProvider` first, then the generated application provider:

```php
use Acme\Plugin\Database\Provider;
use StellarWP\Foundation\Database\DatabaseProvider;

protected array $providers = [
	DatabaseProvider::class,
	Provider::class,
];
```

The table generator writes a Snake_Case table class under `src/Database/Tables` by default. The migration generator writes under `src/Database/Migrations` by default and references the matching table class.

Migration names matching `Create_*_Table`, or migrations generated with `--table-class`, use the table-backed migration stub and wrap the table in `CreateTable`. Other migration names use the generic migration stub.

If `src/Database/Provider.php` exists and contains the generated provider registration points, the table and migration generators automatically add imports and registrations to that provider. Pass `--provider=path/to/Provider.php` to update a non-standard provider file. Re-running a generator does not duplicate existing provider imports or registrations, including after WordPress code formatting. If an existing conventional provider cannot be updated safely, the generator creates the requested class and prints a warning with the manual registration step. An explicitly requested `--provider` that cannot be updated fails before generating the class.

Common options:

```bash
vendor/bin/foundation make:database-provider Provider \
  --namespace="Acme\\Plugin\\Database" \
  --path=src/Database

vendor/bin/foundation make:database-table Reports_Table \
  --namespace="Acme\\Plugin\\Database\\Tables" \
  --path=src/Database/Tables \
  --provider=src/Database/Provider.php \
  --id=reports_table \
  --table=reports

vendor/bin/foundation make:database-migration Create_Reports_Table \
  --namespace="Acme\\Plugin\\Database\\Migrations" \
  --path=src/Database/Migrations \
  --provider=src/Database/Provider.php \
  --id=2026_06_26_000001_create_reports_table \
  --table-class=Reports_Table \
  --table-namespace="Acme\\Plugin\\Database\\Tables"
```

Project-specific stub overrides live in:

```text
foundation/stubs/database/table.stub
foundation/stubs/database/migration.stub
foundation/stubs/database/table-migration.stub
foundation/stubs/database/provider.stub
```

When present, overrides are used instead of the default stubs from the `foundation-database` package.

Override stubs should use the same context-aware placeholders as the default stubs when writing PHP literals. For example, use `{{ id_php }}` and `{{ table_php }}` for values written into PHP constants, and use the `{{ foundation_database_* }}` import placeholders so Strauss-prefixed projects keep working.

## WP-CLI

The package includes a `migrate` command class for projects using `stellarwp/foundation-wpcli`. `DatabaseProvider` adds that command to `StellarWP\Foundation\WPCli\WPCliProvider::COMMANDS`; register the WP-CLI provider once in the consuming application so merged commands are registered on `cli_init`.

```php
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\WPCli\WPCliProvider;

$container->register(WPCliProvider::class);
$container->register(DatabaseProvider::class);
```

Run the command under the configured WP-CLI prefix:

```bash
wp nx migrate --run
```

Available flags:

- `--run` runs pending migrations.
- `--rollback` rolls back the latest migration batch.
- `--refresh` rolls back all known migrations and runs them again.
- `--drop` drops the migrations and lock tables after confirmation.
- `--prepare` prepares the migration store without running migrations.
- `--create-table` is an alias for `--prepare`.
- `--yes` skips confirmation prompts for destructive actions.

Use only one operation flag at a time. `--yes` is a modifier for confirmation prompts and can be combined with destructive operations.

Running the command without a flag prints migration status. If the migration store does not exist yet, the command warns first and shows all configured migrations as pending.
