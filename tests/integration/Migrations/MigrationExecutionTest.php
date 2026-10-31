<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Migrations;

use Doctrine\DBAL\Exception;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\Migration;
use StellarWP\Foundation\Migrations\MigrationsProvider;
use StellarWP\Foundation\Migrations\Migrator;
use StellarWP\Foundation\Migrations\Schema\Blueprint;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Current migrations execute independently of unavailable or changed historical declarations.
 */
final class MigrationExecutionTest extends DatabaseTestCase
{
	private string $entries;
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
		$this->entries = $this->privateTable($this->suffix . '_entries');
		$this->history = $this->privateTable($this->suffix . '_history');
	}

	/**
	 * A recorded declaration is never invoked while applying or previewing its successor.
	 */
	public function test_applied_declarations_are_not_replayed(): void {
		$create = new class($this->suffix . '_entries') extends Migration {
			public int $calls = 0;

			/**
			 * Receive this test's table name.
			 */
			public function __construct(
				private readonly string $table,
			) {
			}

			/**
			 * Count how often the declaration is evaluated.
			 */
			public function up(Blueprint $schema): void {
				$this->calls++;
				$schema->create($this->table)->bigIncrements();
			}
		};
		$migrator = $this->runner($create);
		$migrator->migrate('1');
		$migrator->preview();
		$migrator->migrate();
		$migrator->rollback();
		$this->assertSame(1, $create->calls);
	}

	/**
	 * Missing history is visible while new work can proceed on existing tables.
	 */
	public function test_missing_applied_files_do_not_block_forward_work_or_an_unrelated_rollback(): void {
		$migrator = $this->runner();
		$migrator->migrate('1');
		$this->observer->insert($this->history, [
			'version' => '2',
		]);
		$migrator->migrate();
		$this->assertSame([
			'1',
			'2',
			'3',
		], $this->versions());
		$this->assertSame('missing', $migrator->status()[1]->state());
		$migrator->rollbackTo('2');
		$this->assertSame([
			'1',
			'2',
		], $this->versions());
		$this->assertSame([], $migrator->rollbackTo('2'));
	}

	/**
	 * Validate the entire selected reversal before dropping any columns or tables.
	 */
	public function test_missing_selected_files_stop_rollback_and_refresh_before_any_reversal(): void {
		$migrator = $this->runner();
		$migrator->migrate();
		$this->observer->insert($this->history, [
			'version' => '2',
		]);

		foreach ([
			'rollback',
			'refresh',
		] as $operation) {
			try {
				$operation === 'rollback' ? $migrator->rollback(3) : $migrator->refresh();
				$this->fail('The missing middle declaration must stop the entire reversal.');
			} catch (MigrationInterrupted $failure) {
				$this->assertStringContainsString('before reversing it: 2', $failure->getMessage());
			}

			$this->assertSame([
				'1',
				'2',
				'3',
			], $this->versions());
			$this->assertSame([], $this->observer->fetchFirstColumn('SELECT note FROM ' . $this->entries));
		}
	}

	/**
	 * Earlier DDL can commit before a later statement fails; neither retry nor history hides it.
	 */
	public function test_partial_ddl_failure_leaves_work_unrecorded_and_requires_repair(): void {
		$alter = new class($this->suffix) extends Migration {
			/**
			 * Receive the isolated table prefix.
			 */
			public function __construct(
				private readonly string $suffix,
			) {
			}

			/**
			 * Add a column before an intentionally invalid relationship.
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->suffix . '_entries')->string('note')->nullable();
				$schema->table($this->suffix . '_entries')->foreignKey('missing', 'id')->references($this->suffix . '_absent', 'id');
			}
		};
		$migrator = $this->runner(alter: $alter);
		$migrator->migrate('1');
		$this->observer->insert($this->entries, [
			'id' => 1,
		]);

		try {
			$migrator->migrate();
			$this->fail('The absent referenced table must fail the second DDL statement.');
		} catch (Exception) {
			$this->assertSame([
				null,
			], $this->observer->fetchFirstColumn('SELECT note FROM ' . $this->entries));
		}

		$this->assertSame([
			'1',
		], $this->versions());

		try {
			$migrator->migrate();
			$this->fail('Retry must not silently adopt the already-created column.');
		} catch (MigrationInterrupted $failure) {
			$this->assertStringContainsString('note already exists', $failure->getMessage());
		}

		$this->assertSame([
			'1',
		], $this->versions());
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries));
	}

	/**
	 * Compose two ordinary declarations with a gap for unavailable history.
	 */
	private function runner(?Migration $create = null, ?Migration $alter = null): Migrator {
		$create ??= new class($this->suffix . '_entries') extends Migration {
			/**
			 * Receive this test's table name.
			 */
			public function __construct(
				private readonly string $table,
			) {
			}

			/**
			 * Create the application table.
			 */
			public function up(Blueprint $schema): void {
				$schema->create($this->table)->bigIncrements();
			}

			/**
			 * Remove the application table.
			 */
			public function down(Blueprint $schema): void {
				$schema->drop($this->table);
			}
		};
		$alter ??= new class($this->suffix . '_entries') extends Migration {
			/**
			 * Receive this test's table name.
			 */
			public function __construct(
				private readonly string $table,
			) {
			}

			/**
			 * Add a note to existing entries.
			 */
			public function up(Blueprint $schema): void {
				$schema->table($this->table)->string('note')->nullable();
			}

			/**
			 * Remove this migration's column.
			 */
			public function down(Blueprint $schema): void {
				$schema->table($this->table)->dropColumn('note');
			}
		};
		$this->container->mergeArrayVar(MigrationsProvider::MIGRATIONS, [
			new MigrationRegistration('1', $create),
			new MigrationRegistration('3', $alter),
		]);

		return $this->container->get(Migrator::class);
	}

	/**
	 * Read the recorded identities from the observer session.
	 *
	 * @return list<string>
	 */
	private function versions(): array {
		return $this->observer->fetchFirstColumn('SELECT version FROM ' . $this->history . ' ORDER BY version');
	}
}
