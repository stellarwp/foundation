# Foundation Database

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-database
```

## Overview

Foundation Database is a WordPress-backed database package. It provides a configured migrator, migration and table collections, `wpdb`/`dbDelta` schema services, a database-backed lock, and a WP-CLI migration command.

This package intentionally targets WordPress runtime APIs instead of acting as a generic database abstraction. Migration classes depend on a small schema contract so application packages can define migration behavior without calling `wpdb` directly.

Foundation Database requires WordPress 6.2 or newer because its query layer
uses the `%i` identifier placeholder. Database-backed locks additionally require
fractional-second temporal values: MySQL 5.6.4 or newer, or MariaDB 5.3 or
newer.

## Running Migrations

Use the included WP-CLI command as the standard way to initialize migration
storage and run migrations. Register the WP-CLI provider before the database
provider so the database command is added to the configured command list:

```php
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\WPCli\WPCliProvider;

$container->register(WPCliProvider::class);
$container->register(DatabaseProvider::class);
```

During deployment, initialize the migration store and then run pending
migrations:

```bash
wp nx migrate --initialize
wp nx migrate --run
```

`--initialize` is idempotent and creates or reconciles Foundation's internal
migration and lock tables. Run it before migration operations, including after
updating Foundation Database. Migration operations fail with an actionable
error when storage has not been initialized.

Use the remaining commands to inspect or manage migrations:

```bash
# Show migration status.
wp nx migrate

# Roll back the latest migration batch.
wp nx migrate --rollback

# Roll back every known migration and run them again.
wp nx migrate --refresh --yes

# Drop only the internal migration ledger.
wp nx migrate --drop-store --yes
```

`--drop-store` preserves application tables and shared lock storage. It causes
all configured migrations to appear pending after storage is initialized again;
it is not a substitute for rollback because it does not call migration `down()`
methods. Use only one operation flag at a time. `--yes` only skips confirmation
for destructive operations.

These examples use the default `nx` command prefix. Change
`wpcli.command_prefix` in `config.php` when the application uses another prefix.

## Database Configuration

The recommended WP-CLI setup above registers `DatabaseProvider`. Projects that
run migrations programmatically must still register it in the application
container:

```php
use StellarWP\Foundation\Database\DatabaseProvider;

$container->register(DatabaseProvider::class);
```

The provider registers:

- `StellarWP\Foundation\Database\Database`
- `StellarWP\Foundation\Database\Contracts\Database`
- `StellarWP\Foundation\Database\Schema`
- `StellarWP\Foundation\Database\Table\Tables\MigrationTable`
- `StellarWP\Foundation\Database\Table\Tables\LockTable`
- `StellarWP\Foundation\Database\Contracts\Repository` for the migration ledger
- `StellarWP\Foundation\Database\Migration\Migrator`
- `StellarWP\Foundation\Database\Lock\DatabaseLock` for the migrator

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

`database.lock_ttl` must cover the complete migration operation. The migrator
reports unconfirmed ownership if an otherwise successful operation
cannot release its ownership token. Increase the TTL for long-running
migrations; the migrator does not refresh the lease while a migration is
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
The preferred deployment workflow initializes it with the migration store:

```bash
wp nx migrate --initialize
```

If an application cannot run WP-CLI during deployment, it may initialize the
store programmatically during activation or another controlled lifecycle:

```php
use StellarWP\Foundation\Database\Migration\Migrator;

$container->get(Migrator::class)->initialize();
```

Initializing the migration store also reconciles existing internal tables with
their current definitions.

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

`Database::insert()` returns the number of affected rows, which works for both
auto-increment and application-assigned identifiers such as ULIDs. Use
`Database::insertGetId()` only when the table has an auto-increment key and the
generated integer identifier is needed.

## Defining Migrations

Migrations implement `StellarWP\Foundation\Database\Contracts\Migration`:

```php
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Contracts\Schema;

final readonly class CreateReportsTable implements Migration
{
	public function __construct(
		private Database $database
	) {
	}

	public function id(): string {
		return '2026_06_23_000001_create_reports_table';
	}

	public function up(Schema $schema): void {
		$table = $this->database->tableName('reports');

		$schema->createOrUpdateSql(
			sprintf(
				'CREATE TABLE %s (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				title varchar(191) NOT NULL,
				PRIMARY KEY  (id)
			);',
				$schema->quoteIdentifier($table)
			)
		);
	}

	public function down(Schema $schema): void {
		$table = $this->database->tableName('reports');

		$schema->execute(sprintf(
			'DROP TABLE IF EXISTS %s',
			$schema->quoteIdentifier($table)
		));
	}
}
```

Migration IDs are byte-exact and case-sensitive. They must be nonblank, contain
no surrounding whitespace, fit within 191 bytes, and not be an integer-like
string such as `123`; these rules keep PHP collection keys and the MySQL ledger
consistent.

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

After registering migrations, use the WP-CLI deployment workflow described in
[Running Migrations](#running-migrations). Registering `DatabaseProvider` does
not initialize storage or execute migrations.

If WP-CLI is unavailable during deployment, application code may use the
configured `Migrator` directly from a controlled activation or version-update
lifecycle:

```php
use StellarWP\Foundation\Database\Migration\Migrator;

$migrator = $container->get(Migrator::class);
$migrator->initialize();
$migrator->run();
```

Call `initialize()` before `run()`, `rollback()`, `refresh()`, or `dropStore()`.
Migration operations fail with `UninitializedStore` rather than changing
internal table definitions implicitly.

Recorded migration implementations must remain registered for as long as their
ledger entries may be rolled back. `rollback()` and `refresh()` validate every
selected ledger entry before changing schema and fail without a partial rollback
when an implementation is unavailable.

Completed migration IDs are skipped on later runs. Because migration changes and
their ledger updates are not one atomic operation, write `up()` and `down()`
methods so they can recover from retries after partial work or failed ledger
writes.

## Evolving Tables

`TableDefinition` and `Schema::createOrUpdate()` use WordPress `dbDelta()` to create tables and reconcile changes that `dbDelta()` supports, such as adding columns and indexes. Use `Schema::createOrUpdateSql()` when a migration must provide explicit dbDelta-compatible SQL. They should not be relied on to remove or rename columns, replace indexes, manage foreign keys, or backfill data.

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
