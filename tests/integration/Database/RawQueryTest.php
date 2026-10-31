<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Types\Types;
use RuntimeException;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use StellarWP\Foundation\Database\Query\Database;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Execute consumer-owned SQL with inferred types on the managed connection.
 */
final class RawQueryTest extends DatabaseTestCase
{
	private Database $queries;

	protected function setUp(): void {
		parent::setUp();
		$this->queries = $this->container->get(Database::class);
		$this->observer->executeStatement("ALTER TABLE {$this->table}
			ADD credits_used INT NOT NULL DEFAULT 0,
			ADD period_start DATETIME NULL,
			ADD metadata JSON NULL");
	}

	/**
	 * Raw operations share inference and explicit conversions while retaining their result shapes.
	 */
	public function test_insert_select_and_delete_use_bound_values_and_preserve_results(): void {
		$this->assertSame(1, $this->queries->insert(
			"INSERT INTO {$this->table} (
				id,
				name,
				credits_used,
				period_start,
				metadata
			)
			VALUES (:id, :name, :credits, :period, :metadata)",
			[
				'id'       => 2,
				'name'     => "O'Reilly",
				'credits'  => false,
				'period'   => new DateTimeImmutable('2026-10-02 12:34:56'),
				'metadata' => [
					'source' => 'portal',
				],
			],
			[
				'period'   => Types::DATETIME_IMMUTABLE,
				'metadata' => Types::JSON,
			],
		));
		$rows = $this->queries->select(
			"SELECT name,
				credits_used,
				period_start,
				metadata
			FROM {$this->table}
			WHERE id IN (?)
				AND credits_used = ?",
			[
				[
					2,
					3,
				],
				0,
			],
			[
				ArrayParameterType::INTEGER,
			],
		);
		$this->assertCount(1, $rows);
		$this->assertSame("O'Reilly", $rows[0]['name']);
		$this->assertSame(0, (int) $rows[0]['credits_used']);
		$this->assertSame('2026-10-02 12:34:56', $rows[0]['period_start']);
		$this->assertSame([
			'source' => 'portal',
		], json_decode((string) $rows[0]['metadata'], true, flags: JSON_THROW_ON_ERROR));
		$this->assertSame(1, $this->queries->delete(
			"DELETE FROM {$this->table}
			WHERE id IN (:ids)
				AND name = :name",
			[
				'ids'  => [
					2,
				],
				'name' => "O'Reilly",
			],
			[
				'ids' => ArrayParameterType::INTEGER,
			],
		));
		$this->assertSame([], $this->queries->select("SELECT name
			FROM {$this->table}
			WHERE id = :id", [
			'id' => 2,
		]));
		$this->assertSame(0, $this->queries->delete("DELETE FROM {$this->table}
			WHERE id = ?", [
			2,
		]));
		$this->assertOriginal();
	}

	/**
	 * Select shares numeric inference, supports binding-free SQL, and returns all rows.
	 */
	public function test_select_infers_numeric_expression_types_and_returns_associative_rows(): void {
		$rows = $this->queries->select('SELECT IF(1, 0 + ?, ?) <= ? AS allowed', [
			500,
			500,
			2000,
		]);
		$this->assertSame(1, (int) $rows[0]['allowed']);
		$this->assertSame([
			[
				'name' => 'Original',
			],
			[
				'name' => 'Second',
			],
		], $this->queries->select("SELECT name
			FROM {$this->table}
			UNION ALL
			SELECT 'Second' AS name
			ORDER BY name"));
	}

	/**
	 * The Licensing expression must compare numeric credits, including its reset branch.
	 */
	public function test_conditional_update_enforces_quota_and_resets_the_period(): void {
		$sql = "UPDATE {$this->table}
			SET credits_used = IF(period_start <=> ?, credits_used + ?, ?),
				period_start = ?
			WHERE id = ?
				AND IF(period_start <=> ?, credits_used + ?, ?) <= ?";
		$claims = [
			[
				'period'   => null,
				'amount'   => 500,
				'affected' => 1,
				'used'     => 500,
			],
			[
				'period'   => null,
				'amount'   => 1800,
				'affected' => 0,
				'used'     => 500,
			],
			[
				'period'   => '2026-10-01 00:00:00',
				'amount'   => 1800,
				'affected' => 1,
				'used'     => 1800,
			],
			[
				'period'   => '2026-10-01 00:00:00',
				'amount'   => 300,
				'affected' => 0,
				'used'     => 1800,
			],
			[
				'period'   => '2026-10-01 00:00:00',
				'amount'   => 200,
				'affected' => 1,
				'used'     => 2000,
			],
		];

		foreach ($claims as $claim) {
			$this->assertSame($claim['affected'], $this->queries->update($sql, [
				$claim['period'],
				$claim['amount'],
				$claim['amount'],
				$claim['period'],
				1,
				$claim['period'],
				$claim['amount'],
				$claim['amount'],
				2000,
			]));
			$this->assertSame($claim['used'], (int) $this->observer->fetchOne("SELECT credits_used
				FROM {$this->table}
				WHERE id = 1"));
			$this->assertSame($claim['period'], $this->observer->fetchOne("SELECT period_start
				FROM {$this->table}
				WHERE id = 1"));
		}
	}

	/**
	 * Explicit conversions coexist with inferred named values and numeric strings.
	 */
	public function test_named_update_supports_json_and_array_parameter_types(): void {
		$this->assertSame(1, $this->queries->update(
			"UPDATE {$this->table}
			SET metadata = :metadata,
				name = :name,
				credits_used = :credits,
				period_start = :period
			WHERE id IN (:ids)",
			[
				'metadata' => [
					'source' => 'portal',
				],
				'name'     => '00500',
				'credits'  => true,
				'period'   => null,
				'ids'      => [
					1,
					2,
				],
			],
			[
				'metadata' => Types::JSON,
				'ids'      => ArrayParameterType::INTEGER,
			],
		));
		$row = $this->observer->fetchAssociative("SELECT name,
			credits_used,
			period_start,
			metadata
			FROM {$this->table}
			WHERE id = 1");
		$this->assertIsArray($row);
		$this->assertSame('00500', $row['name']);
		$this->assertSame(1, (int) $row['credits_used']);
		$this->assertNull($row['period_start']);
		$this->assertSame([
			'source' => 'portal',
		], json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR));
	}

	/**
	 * SQL without placeholders still returns the affected-row count.
	 */
	public function test_update_accepts_sql_without_bindings(): void {
		$this->assertSame(1, $this->queries->update("UPDATE {$this->table}
			SET credits_used = 5
			WHERE id = 1"));
		$this->assertSame(5, (int) $this->observer->fetchOne("SELECT credits_used
			FROM {$this->table}
			WHERE id = 1"));
	}

	/**
	 * Raw writes remain provisional and raw reads see them on the shared transaction.
	 */
	public function test_raw_operations_share_the_callers_transaction(): void {
		$failure = new RuntimeException('Cancel claim');

		try {
			$this->db->transactional(function () use ($failure): void {
				$this->assertSame(1, $this->queries->update("UPDATE {$this->table}
					SET name = ?
					WHERE id = ?", [
					'Provisional',
					1,
				]));
				$this->assertSame(1, $this->queries->insert("INSERT INTO {$this->table} (
					id,
					name
				)
				VALUES (?, ?)", [
					2,
					'Inserted',
				]));
				$this->assertSame([
					[
						'name' => 'Provisional',
					],
					[
						'name' => 'Inserted',
					],
				], $this->queries->select("SELECT name
					FROM {$this->table}
					ORDER BY id"));
				$this->assertSame(1, $this->queries->delete("DELETE FROM {$this->table}
					WHERE id = ?", [
					1,
				]));

				throw $failure;
			});
		} catch (RuntimeException $caught) {
			$this->assertSame($failure, $caught);
		}

		$this->assertOriginal();
	}

	/**
	 * Native read failures propagate and poison the same managed transaction as writes.
	 */
	public function test_caught_select_failure_keeps_the_transaction_failed(): void {
		try {
			$this->db->transactional(function (): void {
				$this->queries->delete("DELETE FROM {$this->table}
					WHERE id = 1");

				try {
					$this->queries->select("SELECT missing_column
						FROM {$this->table}");
					$this->fail('The native database exception must reach the caller.');
				} catch (InvalidFieldNameException) {
					// A caught read failure still prevents a successful commit.
				}
			});
			$this->fail('The transaction must not commit after a caught read failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}

	/**
	 * Catching a database error does not allow earlier work to commit.
	 */
	public function test_caught_update_failure_keeps_the_transaction_failed(): void {
		try {
			$this->db->transactional(function (): void {
				$this->queries->update("UPDATE {$this->table}
					SET name = 'Provisional'
					WHERE id = 1");

				try {
					$this->queries->update("UPDATE {$this->table}
						SET missing_column = ?
						WHERE id = ?", [
						'Invalid',
						1,
					]);
					$this->fail('The native database exception must reach the caller.');
				} catch (InvalidFieldNameException) {
					// A caught statement failure still prevents a successful commit.
				}
			});
			$this->fail('The transaction must not commit after a caught statement failure.');
		} catch (TransactionFailed) {
			$this->assertOriginal();
		}
	}
}
