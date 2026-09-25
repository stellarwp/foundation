<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Migrations\Exceptions\LedgerFailure;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\Schema\Blueprint;
use StellarWP\Foundation\Migrations\Schema\Renames\ChangeColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;
use StellarWP\Foundation\Migrations\Schema\Renames\PlatformColumnRename;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Explicit renames preserve rows, current attributes, and relationships.
 */
final class RenameMigrationTest extends DatabaseTestCase
{
	private string $reports;
	private string $archive;
	private string $history;

	protected function configuration(): array {
		return [
			'migrations' => [
				'table' => $this->suffix . '_history',
			],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->container->register(MigrationsProvider::class);
		$this->reports = $this->privateTable($this->suffix . '_reports');
		$this->archive = $this->privateTable($this->suffix . '_archive');
		$this->history = $this->privateTable($this->suffix . '_history');
	}

	/**
	 * Column rename preserves rows and unrelated schema in both directions.
	 */
	public function test_column_rename_preserves_rows_and_unrelated_schema_in_both_directions(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameTitle());
		$migrator->migrate('1');
		$this->insertReport();
		$this->observer->executeStatement('ALTER TABLE ' . $this->reports . ' ADD external_value INT NOT NULL DEFAULT 7, ADD INDEX external_lookup (external_value)');
		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->reports));
		$this->assertSame(7, (int) $this->observer->fetchOne('SELECT external_value FROM ' . $this->reports));
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_reports')->hasIndex('external_lookup'));
		$migrator->rollback();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
	}

	/**
	 * Table rename preserves rows and unrelated schema in both directions.
	 */
	public function test_table_rename_preserves_rows_and_unrelated_schema_in_both_directions(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameReports());
		$migrator->migrate('1');
		$this->insertReport();
		$this->observer->executeStatement('ALTER TABLE ' . $this->reports . ' ADD external_value INT NOT NULL DEFAULT 7');
		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT title FROM ' . $this->archive));
		$this->assertSame(7, (int) $this->observer->fetchOne('SELECT external_value FROM ' . $this->archive));
		$migrator->rollback();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
	}

	/**
	 * Column rename rejects existing source and destination.
	 */
	public function test_column_rename_rejects_existing_source_and_destination(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameTitle());
		$migrator->migrate('1');
		$this->observer->executeStatement('ALTER TABLE ' . $this->reports . ' ADD headline VARCHAR(80) NOT NULL');
		$this->expectException(MigrationInterrupted::class);
		$migrator->migrate();
	}

	/**
	 * Table rename rejects existing source and destination.
	 */
	public function test_table_rename_rejects_existing_source_and_destination(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameReports());
		$migrator->migrate('1');
		$this->observer->executeStatement('CREATE TABLE ' . $this->archive . ' LIKE ' . $this->reports);
		$this->expectException(InvalidArgumentException::class);
		$migrator->migrate();
	}

	/**
	 * Column rename rejects missing source and destination.
	 */
	public function test_column_rename_rejects_missing_source_and_destination(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameTitle());
		$migrator->migrate('1');
		$this->observer->executeStatement('ALTER TABLE ' . $this->reports . ' DROP COLUMN title');
		$this->expectException(MigrationInterrupted::class);
		$migrator->migrate();
	}

	/**
	 * Table rename rejects missing source and destination.
	 */
	public function test_table_rename_rejects_missing_source_and_destination(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameReports());
		$migrator->migrate('1');
		$this->observer->executeStatement('DROP TABLE ' . $this->reports);
		$this->expectException(InvalidArgumentException::class);
		$migrator->migrate();
	}

	/**
	 * Explicit drop and add does not implicitly rename a column.
	 */
	public function test_explicit_drop_and_add_does_not_implicitly_rename_a_column(): void {
		$replace = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->dropColumn('title');
				$table->string('headline', 80)->default('');
			}
		};
		$migrator = $this->migrations($this->createReports(), $replace);
		$migrator->migrate('1');
		$this->insertReport();
		$migrator->migrate();
		$this->assertSame('', $this->observer->fetchOne('SELECT headline FROM ' . $this->reports));
	}

	/**
	 * Timestamp precision and update behavior survive column and table renames.
	 *
	 * @param class-string<ColumnRename> $strategy
	 *
	 * @dataProvider renameStrategies
	 */
	#[DataProvider('renameStrategies')]
	public function test_timestamp_precision_and_update_behavior_survive_column_and_table_renames(string $strategy): void {
		$this->container->singleton(ColumnRename::class, $strategy);
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->renameColumn('updated_at', 'modified_at');
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->rename($this->suffix . '_archive', $this->suffix . '_reports');
				$schema->table($this->suffix . '_reports')->renameColumn('modified_at', 'updated_at');
			}
		};
		$migrator = $this->migrations($this->createReports(), $rename);
		$migrator->migrate('1');
		$this->insertReport();
		$migrator->migrate();
		$this->assertSame([], $migrator->migrate());
		$this->assertSame('2026-01-02 03:04:05.123456', $this->observer->fetchOne('SELECT modified_at FROM ' . $this->archive));
		$column = $this->observer->fetchAssociative(
			"SELECT DATETIME_PRECISION, EXTRA
			 FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'modified_at'",
			[
				$this->source->prefix . $this->suffix . '_archive',
			],
		);
		$this->assertIsArray($column);
		$this->assertSame(6, (int) $column['DATETIME_PRECISION']);
		$this->assertStringContainsString('on update', strtolower($column['EXTRA']));
		$migrator->rollback();
		$this->assertSame('2026-01-02 03:04:05.123456', $this->observer->fetchOne('SELECT updated_at FROM ' . $this->reports));
	}

	/**
	 * Foreign keys follow renamed tables and columns and can be removed by logical name.
	 */
	public function test_foreign_keys_follow_renamed_tables_and_columns_and_can_be_removed_by_logical_name(): void {
		$items        = $this->privateTable($this->suffix . '_items');
		$archiveItems = $this->privateTable($this->suffix . '_archive_items');
		$create       = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->create($this->suffix . '_items');
				$table->bigIncrements();
				$table->unsignedBigInteger('report_id');
				$table->index('report_lookup', 'report_id');
				$table->foreignKey('report', 'report_id')->references($this->suffix . '_reports', 'id')->cascadeOnDelete();
			}
		};
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->renameColumn('id', 'report_id');
				$schema->table($this->suffix . '_items')->renameColumn('report_id', 'archive_id');
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
				$schema->rename($this->suffix . '_items', $this->suffix . '_archive_items');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->rename($this->suffix . '_archive_items', $this->suffix . '_items');
				$schema->rename($this->suffix . '_archive', $this->suffix . '_reports');
				$schema->table($this->suffix . '_items')->renameColumn('archive_id', 'report_id');
				$schema->table($this->suffix . '_reports')->renameColumn('report_id', 'id');
			}
		};
		$drop = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_archive_items')->dropForeignKey('report');
			}
		};
		$migrator = $this->migrations($this->createReports(), $create, $rename, $drop);
		$migrator->migrate('2');
		$this->insertReport();
		$this->observer->insert($items, [
			'id'        => 2,
			'report_id' => 1,
		]);
		$migrator->migrate('3');
		$this->assertSame([], $migrator->migrate('3'));
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT archive_id FROM ' . $archiveItems));
		$migrator->rollback();
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT report_id FROM ' . $items));
		$migrator->migrate('3');
		$this->observer->delete($this->archive, [
			'report_id' => 1,
		]);
		$this->assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $archiveItems));
		$migrator->migrate();
		$this->assertSame([], $this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_archive_items')->getForeignKeys());
	}

	/**
	 * Preview simulates names for later migrations without changing the live tables or ledger.
	 *
	 * @param class-string<ColumnRename> $strategy
	 *
	 * @dataProvider renameStrategies
	 */
	#[DataProvider('renameStrategies')]
	public function test_preview_uses_renamed_schema_for_later_migrations_without_executing(string $strategy): void {
		$this->container->singleton(ColumnRename::class, $strategy);
		$alter = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_archive')->index('headline_lookup', 'headline');
			}
		};
		$migrator = $this->migrations($this->createReports(), $this->chainedRename(), $alter);
		$migrator->migrate('1');
		$this->insertReport();
		$preview = $migrator->preview();
		$this->assertCount(2, $preview);
		$this->assertStringContainsString('headline_lookup', implode('; ', $preview[1]->sql));
		$this->assertSame('Original', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
		$this->assertFalse($this->observer->createSchemaManager()->tablesExist([
			$this->source->prefix . $this->suffix . '_archive',
		]));
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->history));
		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->archive));
		$this->assertTrue($this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_archive')->hasIndex('headline_lookup'));
	}

	/**
	 * A rename preserves the live definition without checking historical declarations.
	 */
	public function test_rename_preserves_the_current_column_definition(): void {
		$migrator = $this->migrations($this->createReports(), $this->chainedRename());
		$migrator->migrate('1');
		$this->insertReport();
		$this->observer->executeStatement('ALTER TABLE ' . $this->reports . " MODIFY title VARCHAR(255) NOT NULL DEFAULT ''");
		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->archive));
		$this->assertSame(255, $this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_archive')->getColumn('headline')->getLength());
	}

	/**
	 * A self-reference follows both local and referenced column names and the table name.
	 */
	public function test_self_referencing_foreign_key_survives_table_and_column_renames(): void {
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('id', 'report_id');
				$table->renameColumn('parent_id', 'parent_report_id');
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->rename($this->suffix . '_archive', $this->suffix . '_reports');
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('parent_report_id', 'parent_id');
				$table->renameColumn('report_id', 'id');
			}
		};
		$migrator = $this->migrations($this->createReports(), $this->selfReference(), $rename);
		$migrator->migrate('2');
		$this->insertReport();
		$this->observer->insert($this->reports, [
			'id'        => 2,
			'parent_id' => 1,
		]);
		$migrator->migrate();
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT parent_report_id FROM ' . $this->archive . ' WHERE report_id = 2'));
		$migrator->rollback();
		$this->observer->delete($this->reports, [
			'id' => 1,
		]);
		$this->assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->reports));
	}

	/**
	 * A renamed table retains the logical identities of existing keys within one declaration.
	 */
	public function test_table_rename_and_foreign_key_drop_work_in_the_same_migration(): void {
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
				$schema->table($this->suffix . '_archive')->dropForeignKey('parent');
			}
		};
		$migrator = $this->migrations($this->createReports(), $this->selfReference(), $rename);
		$migrator->migrate();
		$this->assertSame([], $this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_archive')->getForeignKeys());
	}

	/**
	 * Explicit rename sequences may swap column names through a temporary name.
	 */
	public function test_column_name_cycles_execute_in_declaration_order(): void {
		$swap = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('title', 'temporary');
				$table->renameColumn('updated_at', 'title');
				$table->renameColumn('temporary', 'updated_at');
			}
		};
		$migrator = $this->migrations($this->createReports(), $swap);
		$migrator->migrate('1');
		$this->insertReport();

		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT updated_at FROM ' . $this->reports));
		$this->assertSame('2026-01-02 03:04:05.123456', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
	}

	/**
	 * A renamed source may be reused by a later operation in the same migration.
	 */
	public function test_renaming_and_recreating_the_source_column(): void {
		$reuse = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('title', 'headline');
				$table->string('title', 80)->default('');
			}
		};
		$migrator = $this->migrations($this->createReports(), $reuse);
		$migrator->migrate('1');
		$this->insertReport();

		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->reports));
		$this->assertSame('', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
	}

	/**
	 * Later renames follow an earlier name swap in the declared order.
	 */
	public function test_cycle_followed_by_another_rename_executes_in_order(): void {
		$swap = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('title', 'temporary');
				$table->renameColumn('subtitle', 'title');
				$table->renameColumn('temporary', 'subtitle');
				$table->renameColumn('updated_at', 'modified_at');
			}
		};
		$migrator = $this->migrations($this->createReports(), $swap);
		$migrator->migrate('1');
		$this->insertReport();

		$migrator->migrate();
		$this->assertSame('Other', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
		$this->assertSame('Original', $this->observer->fetchOne('SELECT subtitle FROM ' . $this->reports));
		$this->assertSame('2026-01-02 03:04:05.123456', $this->observer->fetchOne('SELECT modified_at FROM ' . $this->reports));
	}

	/**
	 * A create declaration must not silently discard a column rename.
	 */
	public function test_renaming_a_column_inside_a_create_declaration_is_rejected(): void {
		$create = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->create($this->suffix . '_reports');
				$table->bigIncrements();
				$table->string('title');
				$table->renameColumn('title', 'headline');
			}
		};
		$migrator = $this->migrations($create);

		try {
			$migrator->migrate();
			$this->fail('Rename declarations require an existing table.');
		} catch (InvalidArgumentException) {
			$this->assertFalse($this->observer->createSchemaManager()->tablesExist([
				$this->source->prefix . $this->suffix . '_reports',
			]));
		}
	}

	/**
	 * A rename can change the destination definition while preserving the existing value.
	 */
	public function test_column_rename_and_destination_definition_change_execute_together(): void {
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('title', 'headline');
				$table->string('headline', 255)->nullable()->default(null)->change();
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->renameColumn('headline', 'title');
				$table->string('title', 80)->default('')->change();
			}
		};
		$migrator = $this->migrations($this->createReports(), $rename);
		$migrator->migrate('1');
		$this->insertReport();
		$migrator->migrate();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->reports));
		$column = $this->observer->createSchemaManager()->introspectTable($this->source->prefix . $this->suffix . '_reports')->getColumn('headline');
		$this->assertSame(255, $column->getLength());
		$this->assertFalse($column->getNotnull());
		$migrator->rollback();
		$this->assertSame('Original', $this->observer->fetchOne('SELECT title FROM ' . $this->reports));
	}

	/**
	 * A completed rename with missing history is reported for operator repair.
	 */
	public function test_interrupted_rename_is_not_repeated_or_recorded_on_retry(): void {
		$migrator = $this->migrations($this->createReports(), $this->renameTitle());
		$migrator->migrate('1');
		$this->insertReport();
		$this->failHistoryWrite($migrator);

		try {
			$migrator->migrate();
			$this->fail('The old column is gone; repair must precede another run.');
		} catch (MigrationInterrupted $failure) {
			$this->assertStringContainsString('title does not exist', $failure->getMessage());
		}

		$this->assertSame('Original', $this->observer->fetchOne('SELECT headline FROM ' . $this->reports));
		$this->assertSame(0, (int) $this->observer->fetchOne("SELECT COUNT(*) FROM {$this->history} WHERE version = '2'"));
	}

	private function chainedRename(): Migration {
		return new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
				$table = $schema->table($this->suffix . '_archive');
				$table->renameColumn('title', 'heading');
				$table->renameColumn('heading', 'headline');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_archive');
				$table->renameColumn('headline', 'heading');
				$table->renameColumn('heading', 'title');
				$schema->rename($this->suffix . '_archive', $this->suffix . '_reports');
			}
		};
	}

	private function selfReference(): Migration {
		return new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->table($this->suffix . '_reports');
				$table->unsignedBigInteger('parent_id')->nullable();
				$table->index('parent_lookup', 'parent_id');
				$table->foreignKey('parent', 'parent_id')->references($this->suffix . '_reports', 'id')->cascadeOnDelete();
			}
		};
	}

	/**
	 * Exercise both SQL implementations with the same rename and preservation behavior.
	 *
	 * @return iterable<string, array{class-string<ColumnRename>}>
	 */
	public static function renameStrategies(): iterable {
		yield 'platform' => [
			PlatformColumnRename::class,
		];

		yield 'change' => [
			ChangeColumnRename::class,
		];
	}

	/**
	 * Preview retains complete timestamp definitions from both live and newly created tables.
	 */
	public function test_change_preview_preserves_timestamp_metadata_across_earlier_steps(): void {
		$this->container->singleton(ColumnRename::class, ChangeColumnRename::class);
		$create = new class($this->suffix) extends Migration {
			/**
			 * Receive the private table name.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->create($this->suffix . '_reports');
				$table->bigIncrements('id');
				$table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
			}
		};
		$alter = new class($this->suffix) extends Migration {
			/**
			 * Receive the private table name.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->string('title', 80);
			}
		};
		$rename = new class($this->suffix) extends Migration {
			/**
			 * Receive the private table name.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->renameColumn('updated_at', 'modified_at');
			}
		};
		$migrator = $this->migrations($create, $alter, $rename);
		$preview  = $migrator->preview();
		$this->assertStringContainsString('DATETIME(6)', strtoupper(implode('; ', $preview[2]->sql)));
		$this->assertStringContainsString('ON UPDATE CURRENT_TIMESTAMP(6)', strtoupper(implode('; ', $preview[2]->sql)));
		$migrator->migrate('1');
		$preview = $migrator->preview();
		$this->assertStringContainsString('DATETIME(6)', strtoupper(implode('; ', $preview[1]->sql)));
		$this->assertStringContainsString('ON UPDATE CURRENT_TIMESTAMP(6)', strtoupper(implode('; ', $preview[1]->sql)));
		$migrator->migrate();
		$this->assertSame([], $migrator->migrate());
	}

	private function migrations(Migration ...$migrations): Migrator {
		foreach (array_values($migrations) as $position => $migration) {
			$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [
				new MigrationRegistration((string) ($position + 1), $migration),
			]);
		}

		return $this->container->get(Migrator::class);
	}

	private function createReports(): Migration {
		return new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$table = $schema->create($this->suffix . '_reports');
				$table->bigIncrements();
				$table->string('title', 80)->default('');
				$table->string('subtitle', 80)->default('Other');
				$table->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->drop($this->suffix . '_reports');
			}
		};
	}

	private function renameTitle(): Migration {
		return new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->renameColumn('title', 'headline');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->table($this->suffix . '_reports')->renameColumn('headline', 'title');
			}
		};
	}

	private function renameReports(): Migration {
		return new class($this->suffix) extends Migration {
			/**
			 * Identify this test's private tables.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * {@inheritDoc}
			 */
			public function up(Blueprint $schema): void {
				$schema->rename($this->suffix . '_reports', $this->suffix . '_archive');
			}

			/**
			 * {@inheritDoc}
			 */
			public function down(Blueprint $schema): void {
				$schema->rename($this->suffix . '_archive', $this->suffix . '_reports');
			}
		};
	}

	private function insertReport(): void {
		$this->observer->insert($this->reports, [
			'id'         => 1,
			'title'      => 'Original',
			'updated_at' => '2026-01-02 03:04:05.123456',
		]);
	}

	private function failHistoryWrite(Migrator $migrator, string $target = Migrator::LATEST): void {
		$applied = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->history);
		$trigger = $this->observer->quoteSingleIdentifier($this->suffix . '_reject_history');
		$this->observer->executeStatement("CREATE TRIGGER $trigger BEFORE INSERT ON {$this->history} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger rejected'");

		try {
			$migrator->migrate($target);
			$this->fail('The history write must fail after the rename completed.');
		} catch (LedgerFailure) {
			$this->assertSame($applied, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->history));
		} finally {
			$this->observer->executeStatement('DROP TRIGGER ' . $trigger);
		}
	}
}
