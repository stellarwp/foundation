<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Consumer\Entry_Table;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

final class TableTest extends DatabaseTestCase
{
	private Entry_Table $entries;

	protected function setUp(): void {
		parent::setUp();
		$this->entries  = $this->container->get(Entry_Table::class);
		$this->tables[] = $this->entries->quotedName();
		$this->observer->executeStatement('CREATE TABLE ' . $this->entries->quotedName() . ' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191), active BOOLEAN NOT NULL DEFAULT 0) ENGINE=InnoDB');
	}

	public function test_ordinary_table_calls_share_the_transaction_and_bind_values(): void {
		$id = $this->entries->insertGetId(['name' => "O'Reilly", 'active' => true], ['active' => Types::BOOLEAN]);
		$this->assertSame("O'Reilly", $this->entries->query()->select('name')->where('id = :id')->setParameter('id', $id)->fetchOne());
		$this->assertSame(1, (int) $this->entries->update(['name' => 'Changed'], ['id' => $id]));
		$this->assertSame(1, (int) $this->entries->query()->select('active')->fetchOne());

		try {
			$this->db->transactional(function (): void {
				$this->entries->insert(['name' => 'Rolled back']);

				throw new \RuntimeException('Cancel');
			});
		} catch (\RuntimeException $failure) {
			$this->assertSame('Cancel', $failure->getMessage());
		}
		$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries->quotedName()));
		$this->assertSame(1, (int) $this->entries->delete(['id' => $id]));
	}

	public function test_queries_are_fresh_and_capture_the_site_when_built(): void {
		$old   = $this->entries->query('entry')->select('entry.id');
		$fresh = $this->entries->query();
		$this->assertNotSame($old, $fresh);
		$oldName = $this->entries->name();
		$this->source->set_prefix($this->source->prefix . 'other_');
		$this->assertStringContainsString($oldName, $old->getSQL());
		$this->assertNotSame($oldName, $this->entries->name());
		$this->assertStringContainsString($this->entries->name(), $this->entries->query()->select('id')->getSQL());
	}

	public function test_count_and_delete_all_preserve_the_identity_sequence(): void {
		$this->assertSame(0, $this->entries->count());
		$this->entries->insert([
			'id'   => 40,
			'name' => 'Original',
		]);
		$this->entries->insert([
			'name' => 'Another',
		]);

		$this->assertSame(2, $this->entries->count());
		$this->assertSame(2, (int) $this->entries->deleteAll());
		$this->assertSame(0, $this->entries->count());
		$this->assertSame(0, (int) $this->entries->deleteAll());
		$this->assertSame(42, (int) $this->entries->insertGetId([
			'name' => 'Next',
		]));
	}

	public function test_delete_all_rolls_back_with_the_callers_transaction(): void {
		$this->entries->insert([
			'name' => 'Original',
		]);

		try {
			$this->db->transactional(function (): void {
				$this->entries->deleteAll();
				$this->assertSame(0, $this->entries->count());
				$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries->quotedName()));
				$this->entries->insert([
					'name' => 'Replacement',
				]);

				throw new RuntimeException('Cancel replacement');
			});
		} catch (RuntimeException $failure) {
			$this->assertSame('Cancel replacement', $failure->getMessage());
		}

		$this->assertSame([
			'Original',
		], $this->entries->query()->select('name')->fetchFirstColumn());
	}

	public function test_truncate_empties_the_table_and_resets_auto_increment(): void {
		$this->entries->insert([
			'id'   => 40,
			'name' => 'Original',
		]);
		$this->entries->truncate();

		$this->assertSame(0, $this->entries->count());
		$this->assertSame(1, (int) $this->entries->insertGetId([
			'name' => 'First',
		]));
	}

	public function test_truncate_rejects_a_transaction_before_committing_or_deleting_rows(): void {
		$this->entries->insert([
			'name' => 'Original',
		]);
		$this->db->beginTransaction();
		$this->entries->insert([
			'name' => 'Uncommitted',
		]);

		try {
			$this->entries->truncate();
			$this->fail('Truncation must reject an active transaction.');
		} catch (DatabaseException $failure) {
			$this->assertStringContainsString('use deleteAll()', $failure->getMessage());
			$this->assertTrue($this->db->isTransactionActive());
			$this->assertSame(2, $this->entries->count());
			$this->assertSame(1, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries->quotedName()));
		} finally {
			$this->db->rollBack();
		}

		$this->assertSame([
			'Original',
		], $this->entries->query()->select('name')->fetchFirstColumn());
	}

	public function test_whole_table_deletions_preserve_foreign_key_enforcement(): void {
		$children = $this->privateTable($this->suffix . '_children');
		$entries  = $this->entries->quotedName();
		$this->observer->executeStatement("CREATE TABLE {$children} (
			id INT PRIMARY KEY,
			entry_id BIGINT UNSIGNED NOT NULL,
			FOREIGN KEY (entry_id) REFERENCES {$entries} (id) ON DELETE RESTRICT
		) ENGINE=InnoDB");
		$id = $this->entries->insertGetId([
			'name' => 'Referenced',
		]);
		$this->observer->insert($children, [
			'id'       => 1,
			'entry_id' => $id,
		]);

		foreach ([
			'deleteAll',
			'truncate',
		] as $method) {
			try {
				$this->entries->$method();
				$this->fail('Foreign-key restrictions must be enforced.');
			} catch (DriverException) {
				$this->assertSame(1, $this->entries->count());
				$this->assertSame(1, (int) $this->db->fetchOne('SELECT @@SESSION.foreign_key_checks'));
			}
		}
	}

	public function test_whole_table_operations_report_a_missing_table(): void {
		$this->observer->executeStatement('DROP TABLE ' . $this->entries->quotedName());

		foreach ([
			'count',
			'deleteAll',
			'truncate',
		] as $method) {
			try {
				$this->entries->$method();
				$this->fail('A missing table must not look empty.');
			} catch (TableNotFoundException $failure) {
				$this->assertStringContainsString($this->entries->name(), $failure->getMessage());
			}
		}
	}

	public function test_empty_mutation_criteria_are_rejected(): void {
		foreach (['update', 'delete'] as $method) {
			try {
				$method === 'update' ? $this->entries->update(['name' => 'All'], []) : $this->entries->delete([]);
				$this->fail('Expected empty criteria rejection.');
			} catch (InvalidArgumentException) {
				$this->assertSame(0, (int) $this->entries->query()->select('COUNT(*)')->fetchOne());
			}
		}
	}

	public function test_idle_connection_replacement_supports_queries_and_transactions(): void {
		$old          = $this->native($this->source);
		$oldStatement = $this->db->prepare('SELECT CONNECTION_ID()');
		$this->source->__set('dbh', $this->native($this->observerSource));

		try {
			$this->assertSame($this->native($this->observerSource)->thread_id, (int) $this->db->fetchOne('SELECT CONNECTION_ID()'));
			$this->assertSame('updated', $this->db->transactional(function (Connection $db): string {
				$this->entries->insert(['name' => 'Reconnected']);

				return 'updated';
			}));
			$this->assertSame('Reconnected', $this->entries->query()->select('name')->fetchOne());

			try {
				$oldStatement->executeQuery();
				$this->fail('An old statement must not run on a replaced session.');
			} catch (\RuntimeException $failure) {
				$this->assertStringContainsString('connection changed', $failure->getMessage());
			}
		} finally {
			$this->source->__set('dbh', $old);
		}
	}
}
