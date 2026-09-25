<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Query\Database;
use StellarWP\Foundation\Database\Query\JoinClause;
use StellarWP\Foundation\Database\Query\Query;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Reject invalid query intent without mutating a usable builder or application data.
 */
final class QueryValidationTest extends DatabaseTestCase
{
	/**
	 * Rejected fluent calls preserve both the projection and its aggregate semantics.
	 */
	public function test_rejected_fluent_calls_leave_the_query_usable(): void {
		$query = $this->container->get(Database::class)->table($this->suffix)
			->selectRaw('COUNT(*) AS total')
			->where('id', 999);
		$sql      = $query->toSql();
		$bindings = $query->getBindings();
		$invalid  = [
			'projection'      => static fn (Query $query) => $query->select('id', 'invalid column'),
			'order'           => static fn (Query $query) => $query->orderBy('id', 'sideways'),
			'limit'           => static fn (Query $query) => $query->limit(-1),
			'offset'          => static fn (Query $query) => $query->offset(-1),
			'incomplete join' => fn (Query $query) => $query->join($this->suffix, 'id'),
			'empty join'      => fn (Query $query) => $query->leftJoin($this->suffix, static function (JoinClause $join): void {
			}),
		];

		foreach ($invalid as $operation => $change) {
			$this->assertRejected(static fn () => $change($query), $operation);
			$this->assertSame($sql, $query->toSql(), $operation);
			$this->assertSame($bindings, $query->getBindings(), $operation);
			$this->assertTrue($query->exists(), $operation);
			$this->assertSame(1, $query->count(), $operation);
		}

		$this->assertOriginal();
	}

	/**
	 * Guard failures cannot clear a table or run a partially specified write.
	 */
	public function test_invalid_writes_leave_existing_rows_unchanged(): void {
		$queries = $this->container->get(Database::class);
		$invalid = [
			'unfiltered update'        => fn () => $queries->table($this->suffix)->update([
				'name' => 'Changed',
			]),
			'unfiltered delete'        => fn () => $queries->table($this->suffix)->delete(),
			'empty update'             => fn () => $queries->table($this->suffix)->where('id', 1)->update([]),
			'aliased delete'           => fn () => $queries->table($this->suffix, 'e')->where('e.id', 1)->delete(),
			'empty upsert columns'     => fn () => $queries->table($this->suffix)->upsert([
				'id'   => 1,
				'name' => 'Changed',
			], []),
			'filtered defaults insert' => fn () => $queries->table($this->suffix)->where('id', 1)->insertGetId([]),
			'filtered empty insert'    => fn () => $queries->table($this->suffix)->where('id', 1)->insert([]),
			'ordered empty insert'     => fn () => $queries->table($this->suffix)->orderBy('id')->insert([]),
			'aliased empty insert'     => fn () => $queries->table($this->suffix, 'e')->insert([]),
			'aliased empty upsert'     => fn () => $queries->table($this->suffix, 'e')->upsert([], [
				'name',
			]),
		];

		foreach ($invalid as $operation => $write) {
			$this->assertRejected($write, $operation);
			$this->assertOriginal();
		}

		$this->assertSame('Original', $queries->table($this->suffix)->where('id', 1)->first()['name'] ?? null);
	}

	/**
	 * Insert and update reject the same invalid unqualified column names.
	 */
	public function test_insert_and_update_reject_invalid_column_names(): void {
		$query   = $this->container->get(Database::class)->table($this->suffix);
		$columns = [
			'e.name',
			'name`',
			'name = NULL',
		];

		foreach ($columns as $column) {
			$this->assertRejected(static fn () => $query->insert([
				$column => 'changed',
			]), $column);
			$this->assertRejected(static fn () => (clone $query)->where('id', 1)->update([
				$column => 'changed',
			]), $column);
		}

		$this->assertOriginal();
	}

	/**
	 * Shaped joins need an unambiguous projection before forming a derived table.
	 */
	public function test_shaped_join_rejects_wildcards_and_accepts_an_explicit_projection(): void {
		$query = $this->container->get(Database::class)->table($this->suffix, 'e')
			->join(new TableReference($this->source->prefix . $this->suffix, 'matching'), 'matching.id', '=', 'e.id')
			->distinct();

		$this->assertRejected($query->count(...), 'implicit wildcard');
		$query->select('e.*');
		$this->assertRejected($query->count(...), 'qualified wildcard');
		$query->selectRaw('1 AS marker');
		$this->assertRejected($query->count(...), 'qualified wildcard with raw selection');
		$this->assertSame(1, $query->select('e.id')->count());
		$this->assertOriginal();
	}

	/**
	 * A known missing output fails before database execution, preserving query state.
	 */
	public function test_aggregates_reject_missing_explicit_outputs_without_connecting(): void {
		$driver = $this->createMock(Driver::class);
		$driver->expects($this->never())->method('connect');
		$this->container->singleton(Connection::class, new Connection([], $driver));
		$queries = $this->container->get(Database::class);
		$cases   = [
			$queries->table($this->suffix)->select('name')->limit(10),
			$queries->table($this->suffix)->select('name')->distinct(),
			$queries->table($this->suffix)->select('name')->groupBy('name'),
			$queries->table($this->suffix)->select('amount AS total')->limit(10),
		];

		foreach ($cases as $query) {
			$sql = $query->toSql();

			try {
				$query->max('amount');
				$this->fail('An aggregate cannot read an excluded output column.');
			} catch (InvalidArgumentException $failure) {
				$this->assertSame('Aggregate column "amount" is not selected. Include it in select() or use its selected alias.', $failure->getMessage());
			}

			$this->assertSame($sql, $query->toSql());
		}
	}

	/**
	 * A zero limit remains empty when first() applies its own maximum.
	 */
	public function test_first_respects_an_existing_zero_limit(): void {
		$query = $this->container->get(Database::class)->table($this->suffix)->limit(0);
		$this->assertNull($query->first());
		$this->assertSame([], $query->get());
		$this->assertSame(0, $query->count());
		$this->assertOriginal();
	}

	/**
	 * Names are validated before an invalid identity can enter a query.
	 */
	public function test_invalid_table_references_and_unknown_wordpress_tables_are_rejected(): void {
		$queries = $this->container->get(Database::class);
		$this->assertRejected(static fn () => $queries->wordpress('not_a_core_table'), 'unknown core table');
		$this->assertRejected(static fn () => new TableReference(''), 'empty table name');
		$this->assertRejected(static fn () => new TableReference(str_repeat('x', 65)), 'overlong table name');
		$this->assertRejected(static fn () => new TableReference('wp_entries', 'invalid alias'), 'invalid alias');
		$this->assertSame(1, $queries->table($this->suffix)->count());
	}

	/**
	 * Resolving the query service and composing SQL never opens its connection.
	 */
	public function test_query_construction_and_compilation_do_not_connect(): void {
		$driver = $this->createMock(Driver::class);
		$driver->expects($this->never())->method('connect');
		$driver->expects($this->never())->method('getDatabasePlatform');
		$db = new Connection([], $driver);
		$this->container->singleton(Connection::class, $db);
		$query = $this->container->get(Database::class)->table($this->suffix)->where('id', 1);
		$this->assertStringContainsString('`' . $this->source->prefix . $this->suffix . '`', $query->toSql());
		$this->assertSame([
			1,
		], $query->getBindings());
		$this->assertFalse($db->isConnected());
	}

	/**
	 * Expect rejection while allowing the scenario to verify subsequent behavior.
	 *
	 * @param Closure(): mixed $operation
	 */
	private function assertRejected(Closure $operation, string $description): void {
		try {
			$operation();
		} catch (InvalidArgumentException) {
			$this->addToAssertionCount(1);

			return;
		}

		$this->fail('Expected invalid query state to be rejected: ' . $description);
	}
}
