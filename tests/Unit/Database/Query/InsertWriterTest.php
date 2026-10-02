<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Database\Query;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use StellarWP\Foundation\Database\Query\Executor;
use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\InsertWriter;
use StellarWP\Foundation\Database\Query\Upsert\ValueBuilder;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;

final class InsertWriterTest extends TestCase
{
	public function test_ignored_inserts_bind_and_reorder_rows_across_chunks(): void {
		$connection = $this->createMock(Connection::class);
		$calls      = [];
		$connection->expects($this->exactly(2))->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $bindings) use (&$calls): string {
				$calls[] = [
					$sql,
					$bindings,
				];

				return count($calls) === 1 ? '1' : '0';
			},
		);
		$count = $this->writer($connection, 4)->insertOrIgnore(new TableReference('wp_entries'), [
			[
				'id'   => 1,
				'name' => "O'Reilly",
			],
			[
				'name' => 'duplicate',
				'id'   => 1,
			],
			[
				'id'   => 1,
				'name' => 'another duplicate',
			],
		]);
		$this->assertSame(1, $count);
		$this->assertSame([
			[
				'INSERT IGNORE INTO `wp_entries` (`id`, `name`) VALUES (?, ?), (?, ?)',
				[
					1,
					"O'Reilly",
					1,
					'duplicate',
				],
			],
			[
				'INSERT IGNORE INTO `wp_entries` (`id`, `name`) VALUES (?, ?)',
				[
					1,
					'another duplicate',
				],
			],
		], $calls);
	}

	public function test_empty_ignored_insert_performs_no_database_work(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$this->assertSame(0, $this->writer($connection)->insertOrIgnore(new TableReference('wp_entries'), []));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function insertMethods(): iterable {
		yield 'insert' => [
			'insert',
		];

		yield 'insertOrIgnore' => [
			'insertOrIgnore',
		];
	}

	public function test_rows_reorder_columns_and_split_at_parameter_limit(): void {
		$connection = $this->createMock(Connection::class);
		$calls      = [];
		$connection->expects($this->exactly(2))->method('executeStatement')->willReturnCallback(
			static function (string $sql, array $bindings) use (&$calls): int {
				$calls[] = [
					$sql,
					$bindings,
				];

				return intdiv(count($bindings), 2);
			},
		);
		$writer = $this->writer($connection, 4);
		$this->assertSame(3, $writer->insert(new TableReference('wp_entries'), [
			[
				'id'   => 1,
				'name' => 'one',
			],
			[
				'name' => 'two',
				'id'   => 2,
			],
			[
				'id'   => 3,
				'name' => 'three',
			],
		], [
			'name',
		]));
		$this->assertSame('INSERT INTO `wp_entries` (`id`, `name`) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $calls[0][0]);
		$this->assertSame([
			1,
			'one',
			2,
			'two',
		], $calls[0][1]);
		$this->assertSame([
			3,
			'three',
		], $calls[1][1]);
	}

	/**
	 * @dataProvider insertMethods
	 */
	#[DataProvider('insertMethods')]
	public function test_later_invalid_row_is_rejected_before_any_write(string $method): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$writer = $this->writer($connection, 1);
		$this->expectException(InvalidArgumentException::class);
		$writer->$method(new TableReference('wp_entries'), [
			[
				'id' => 1,
			],
			[
				'id' => new stdClass(),
			],
		]);
	}

	/**
	 * @dataProvider insertMethods
	 */
	#[DataProvider('insertMethods')]
	public function test_mismatched_columns_are_rejected_before_any_write(string $method): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$writer = $this->writer($connection, 1);
		$this->expectException(InvalidArgumentException::class);
		$writer->$method(new TableReference('wp_entries'), [
			[
				'id' => 1,
			],
			[
				'name' => 'two',
			],
		]);
	}

	/**
	 * @dataProvider insertMethods
	 */
	#[DataProvider('insertMethods')]
	public function test_a_later_chunk_failure_escapes_without_owning_transactions(string $method): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('beginTransaction');
		$connection->expects($this->never())->method('commit');
		$calls = 0;
		$connection->expects($this->exactly(2))->method('executeStatement')->willReturnCallback(
			static function () use (&$calls): int {
				if (++$calls === 2) {
					throw new RuntimeException('second chunk failed');
				}

				return 1;
			},
		);
		$writer = $this->writer($connection, 1);
		$this->expectExceptionMessage('second chunk failed');
		$writer->$method(new TableReference('wp_entries'), [
			[
				'id' => 1,
			],
			[
				'id' => 2,
			],
		]);
	}

	public function test_upsert_sums_string_counts_across_chunks(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->exactly(2))->method('executeStatement')->willReturnOnConsecutiveCalls('2', '1');
		$writer = $this->writer($connection, 1);
		$this->assertSame(3, $writer->insert(new TableReference('wp_entries'), [
			[
				'id' => 1,
			],
			[
				'id' => 2,
			],
		], [
			'id',
		]));
	}

	public function test_empty_insert_performs_no_database_work(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$this->assertSame(0, $this->writer($connection)->insert(new TableReference('wp_entries'), []));
	}

	public function test_single_associative_row_preserves_affected_rows(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->once())->method('executeStatement')->with('INSERT INTO `wp_entries` (`id`) VALUES (?)', [
			7,
		])->willReturn('1');
		$this->assertSame(1, $this->writer($connection)->insert(new TableReference('wp_entries'), [
			'id' => 7,
		]));
	}

	public function test_defaults_only_insert_reads_the_new_id_after_execution(): void {
		$connection = $this->createMock(Connection::class);
		$inserted   = false;
		$connection->expects($this->once())->method('executeStatement')
			->with('INSERT INTO `wp_entries` () VALUES ()', [], [])
			->willReturnCallback(static function () use (&$inserted): int {
				$inserted = true;

				return 1;
			});
		$connection->expects($this->once())->method('lastInsertId')->willReturnCallback(function () use (&$inserted): string {
			$this->assertTrue($inserted);

			return '9223372036854775808';
		});
		$this->assertSame('9223372036854775808', $this->writer($connection)->insertGetId(new TableReference('wp_entries'), []));
	}

	public function test_failed_single_row_insert_does_not_read_an_old_id(): void {
		$connection = $this->createMock(Connection::class);
		$failure    = new RuntimeException('Insert failed');
		$connection->expects($this->once())->method('executeStatement')->willThrowException($failure);
		$connection->expects($this->never())->method('lastInsertId');
		$this->expectExceptionObject($failure);
		$this->writer($connection)->insertGetId(new TableReference('wp_entries'), [
			'name' => 'Failed',
		]);
	}

	public function test_generated_id_rejects_bulk_input_before_any_write(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$connection->expects($this->never())->method('lastInsertId');
		$this->expectException(InvalidArgumentException::class);
		$this->writer($connection)->insertGetId(new TableReference('wp_entries'), [
			[
				'name' => 'One',
			],
		]);
	}

	public function test_defaults_only_insert_rejects_an_aliased_target(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$connection->expects($this->never())->method('lastInsertId');
		$this->expectException(InvalidArgumentException::class);
		$this->writer($connection)->insertGetId(new TableReference('wp_entries', 'e'), []);
	}

	/**
	 * @return iterable<string, array{array<mixed>, list<string>, int}>
	 */
	public static function invalidRows(): iterable {
		yield 'empty row' => [
			[
				[],
			],
			[],
			65535,
		];

		yield 'qualified column' => [
			[
				'e.id'                                => 1,
			],
			[],
			65535,
		];

		yield 'positional values' => [
			[
				1,
			],
			[],
			65535,
		];

		yield 'mixed named and numeric columns' => [
			[
				'name' => 'one',
				0      => 'two',
			],
			[],
			65535,
		];

		yield 'invalid parameter budget' => [
			[
				'id' => 1,
			],
			[],
			0,
		];

		yield 'missing update column' => [
			[
				'id' => 1,
			],
			[
				'name',
			],
			65535,
		];

		yield 'single row exceeds limit' => [
			[
				'id'   => 1,
				'name' => 'one',
			],
			[],
			1,
		];
	}

	/**
	 * @param array<string, mixed>|list<array<string, mixed>> $rows
	 * @param list<string>                                    $update
	 *
	 * @dataProvider invalidRows
	 */
	#[DataProvider('invalidRows')]
	public function test_invalid_input_is_rejected_before_writing(array $rows, array $update, int $limit): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$this->expectException(InvalidArgumentException::class);
		$this->writer($connection, $limit)->insert(new TableReference('wp_entries'), $rows, $update);
	}

	public function test_an_aliased_insert_is_rejected_even_when_there_are_no_rows(): void {
		$connection = $this->createMock(Connection::class);
		$connection->expects($this->never())->method('executeStatement');
		$this->expectException(InvalidArgumentException::class);
		$this->writer($connection)->insert(new TableReference('wp_entries', 'e'), []);
	}

	/**
	 * Compose the writer with the test connection and parameter budget.
	 */
	private function writer(Connection $connection, int $parameterLimit = 65535): InsertWriter {
		$quoter = new IdentifierQuoter();

		return new InsertWriter(
			new Executor($connection),
			new ValueBuilder($quoter),
			$quoter,
			$parameterLimit,
		);
	}
}
