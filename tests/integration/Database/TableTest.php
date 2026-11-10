<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use DateTimeImmutable;
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
		$id = $this->entries->insertGetId(['name' => "O'Reilly", 'active' => true]);
		$this->assertSame("O'Reilly", ($this->entries->query()->select('name')->where('id', $id)->first()['name'] ?? null));
		$this->assertSame(1, (int) $this->entries->update(['name' => 'Changed'], ['id' => $id]));
		$this->assertSame(1, (int) ($this->entries->query()->select('active')->first()['active'] ?? null));

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

	/**
	 * Both entry points share bulk input, scalar conversion, dates, and empty input.
	 */
	public function test_table_and_query_insertions_have_identical_behavior(): void {
		$this->observer->executeStatement('ALTER TABLE ' . $this->entries->quotedName() . '
			ADD COLUMN amount DECIMAL(12, 2) NULL,
			ADD COLUMN created_at DATETIME(6) NULL');

		foreach ([
			$this->entries,
			$this->entries->query(),
		] as $target) {
			$this->assertSame(0, $target->insert([]));
			$this->assertSame(1, $target->insert([
				'name'       => "O'Reilly",
				'active'     => true,
				'amount'     => '12.30',
				'created_at' => new DateTimeImmutable('2026-09-25 12:34:56.123456+08:00'),
			]));
			$this->assertSame(2, $target->insert([
				[
					'name'   => 'Two',
					'active' => false,
				],
				[
					'active' => true,
					'name'   => null,
				],
			]));
			$row = $this->entries->query()->orderBy('id')->first();
			$this->assertNotNull($row);
			$this->assertArrayHasKey('name', $row);
			$this->assertSame("O'Reilly", $row['name']);
			$this->assertSame(1, (int) $row['active']);
			$this->assertSame('12.30', $row['amount']);
			$this->assertSame('2026-09-25 12:34:56.123456', $row['created_at']);
			$this->assertSame(3, $this->entries->count());
			$this->entries->deleteAll();
		}
	}

	/**
	 * Table and fluent mutations share value normalization and equality semantics.
	 */
	public function test_table_and_query_updates_and_deletes_have_identical_behavior(): void {
		$this->observer->executeStatement('ALTER TABLE ' . $this->entries->quotedName() . ' ADD COLUMN updated_at DATETIME(6) NULL');
		$first = $this->entries->insertGetId([
			'name' => 'Table',
		]);
		$second = $this->entries->insertGetId([
			'name' => 'Query',
		]);
		$updatedAt = new DateTimeImmutable('2026-09-25 12:34:56.123456+08:00');
		$values    = [
			'name'       => null,
			'active'     => false,
			'updated_at' => $updatedAt,
		];
		$this->assertSame(1, (int) $this->entries->update($values, [
			'id'         => $first,
			'active'     => false,
			'updated_at' => null,
		]));
		$this->assertSame(1, (int) $this->entries->query()->where([
			'id'         => $second,
			'active'     => false,
			'updated_at' => null,
		])->update($values));
		$rows = $this->entries->query()->select('name', 'active', 'updated_at')->get();
		$this->assertCount(2, $rows);
		$this->assertSame($rows[0], $rows[1]);
		$this->assertSame('2026-09-25 12:34:56.123456', $rows[0]['updated_at']);
		$this->assertSame(1, (int) $this->entries->delete([
			'id'         => $first,
			'name'       => null,
			'active'     => false,
			'updated_at' => $updatedAt,
		]));
		$this->assertSame(1, (int) $this->entries->query()->where([
			'id'         => $second,
			'name'       => null,
			'active'     => false,
			'updated_at' => $updatedAt,
		])->delete());
		$this->assertSame(0, $this->entries->count());
	}

	/**
	 * Both table mutations participate in the same caller-owned transaction.
	 */
	public function test_table_update_and_filtered_delete_roll_back_together(): void {
		$first = $this->entries->insertGetId([
			'name' => 'First',
		]);
		$second = $this->entries->insertGetId([
			'name' => 'Second',
		]);

		try {
			$this->db->transactional(function () use ($first, $second): void {
				$this->assertSame(1, (int) $this->entries->update([
					'name' => 'Changed',
				], [
					'id' => $first,
				]));
				$this->assertSame(1, (int) $this->entries->delete([
					'id' => $second,
				]));

				throw new RuntimeException('Cancel mutations');
			});
		} catch (RuntimeException $failure) {
			$this->assertSame('Cancel mutations', $failure->getMessage());
		}

		$this->assertSame([
			'First',
			'Second',
		], $this->observer->fetchFirstColumn('SELECT name FROM ' . $this->entries->quotedName() . ' ORDER BY id'));
	}

	/**
	 * Requesting an ID always inserts a row, including when only defaults are needed.
	 */
	public function test_generated_ids_follow_single_row_and_defaults_only_insertions(): void {
		foreach ([
			$this->entries,
			$this->entries->query(),
		] as $target) {
			$previous = $target->insertGetId([
				'name'   => 'Previous',
				'active' => true,
			]);
			$this->assertSame(0, $target->insert([]));
			$id = $target->insertGetId([]);
			$this->assertNotSame((string) $previous, (string) $id);
			$row = $this->entries->query()->where('id', $id)->first();
			$this->assertNotNull($row);
			$this->assertNull($row['name']);
			$this->assertSame(0, (int) $row['active']);
		}

		$this->assertSame(4, $this->entries->count());
	}

	/**
	 * Explicit Doctrine conversions remain available on the shared connection.
	 */
	public function test_native_insert_supports_explicit_doctrine_types(): void {
		$this->observer->executeStatement('ALTER TABLE ' . $this->entries->quotedName() . ' ADD COLUMN payload TEXT');
		$this->db->insert($this->entries->quotedName(), [
			'name'    => 'Typed',
			'payload' => [
				'enabled' => true,
			],
		], [
			'payload' => Types::JSON,
		]);

		$row = $this->entries->query()->first();
		$this->assertNotNull($row);
		$this->assertArrayHasKey('payload', $row);
		$this->assertSame([
			'enabled' => true,
		], json_decode((string) $row['payload'], true));
		$this->assertSame(1, (int) $this->db->update($this->entries->quotedName(), [
			'payload' => [
				'enabled' => false,
			],
		], [
			'name' => 'Typed',
		], [
			'payload' => Types::JSON,
		]));
		$updated = $this->entries->query()->first();
		$this->assertNotNull($updated);
		$this->assertArrayHasKey('payload', $updated);
		$this->assertSame([
			'enabled' => false,
		], json_decode((string) $updated['payload'], true));
		$this->assertSame(1, (int) $this->db->delete($this->entries->quotedName(), [
			'name' => 'Typed',
		], [
			'name' => Types::STRING,
		]));
	}

	/**
	 * A bulk table insertion participates in the caller's existing transaction.
	 */
	public function test_bulk_table_insert_rolls_back_with_the_callers_transaction(): void {
		try {
			$this->db->transactional(function (): void {
				$this->entries->insert([
					[
						'name' => 'First',
					],
					[
						'name' => 'Second',
					],
				]);

				throw new RuntimeException('Cancel bulk insertion');
			});
		} catch (RuntimeException $failure) {
			$this->assertSame('Cancel bulk insertion', $failure->getMessage());
		}

		$this->assertSame(0, $this->entries->count());
	}

	public function test_queries_are_fresh_and_capture_the_site_when_built(): void {
		$old   = $this->entries->query('entry')->select('entry.id');
		$fresh = $this->entries->query();
		$this->assertNotSame($old, $fresh);
		$oldName = $this->entries->name();
		$this->source->set_prefix($this->source->prefix . 'other_');
		$this->assertStringContainsString($oldName, $old->toSql());
		$this->assertNotSame($oldName, $this->entries->name());
		$this->assertStringContainsString($this->entries->name(), $this->entries->query()->select('id')->toSql());
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
		], array_column($this->entries->query()->select('name')->get(), 'name'));
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
		], array_column($this->entries->query()->select('name')->get(), 'name'));
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
				$this->assertSame(0, (int) $this->entries->query()->count());
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
			$this->assertSame('Reconnected', ($this->entries->query()->select('name')->first()['name'] ?? null));

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
