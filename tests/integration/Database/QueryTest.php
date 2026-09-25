<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use StellarWP\Foundation\Database\Query\Database;
use StellarWP\Foundation\Database\Query\Executor;
use StellarWP\Foundation\Database\Query\IdentifierQuoter;
use StellarWP\Foundation\Database\Query\InsertWriter;
use StellarWP\Foundation\Database\Query\JoinClause;
use StellarWP\Foundation\Database\Query\Upsert\ServerBuilder;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;
use StellarWP\Foundation\Database\Query\WhereGroup;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\Consumer\Entry_Table;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;

/**
 * Exercise consumer queries against the shared managed WordPress connection.
 */
final class QueryTest extends DatabaseTestCase
{
	private Database $queries;
	private Entry_Table $entries;

	protected function setUp(): void {
		parent::setUp();
		$this->queries  = $this->container->get(Database::class);
		$this->entries  = $this->container->get(Entry_Table::class);
		$this->tables[] = $this->entries->quotedName();
		$this->observer->executeStatement('CREATE TABLE ' . $this->entries->quotedName() . ' (
			id INT PRIMARY KEY,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NULL UNIQUE,
			category VARCHAR(30) NULL,
			amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
			active BOOLEAN NOT NULL DEFAULT 0,
			created_at DATETIME(6) NULL
		) ENGINE=InnoDB');
	}

	/**
	 * Bound values, nested predicates, arrays and nulls compose without raw SQL.
	 */
	public function test_conditions_compose_and_terminal_reads_leave_the_query_unchanged(): void {
		$this->seedEntries();
		$query = $this->entries->query('e')
			->select('e.id', 'e.name')
			->where([
				'e.active' => true,
			])
			->where(static fn (WhereGroup $group) => $group
				->where('e.category', 'a')
				->orWhere('e.name', 'Third'))
			->where('e.id', '>=', 1)
			->orderBy('e.id');
		$sql = $query->toSql();
		$this->assertSame(2, $query->count());
		$this->assertTrue($query->exists());
		$this->assertSame('First', $query->first()['name'] ?? null);
		$this->assertCount(2, $query->get());
		$this->assertSame($sql, $query->toSql());
		$this->assertSame(1, $this->entries->query()->whereNull('category')->count());
		$this->assertSame(2, $this->entries->query()->where('category', '!=', null)->count());
		$this->assertSame(1, $this->entries->query()->where('category', null)->count());
		$this->assertSame(2, $this->entries->query()->whereNotNull('category')->count());
		$this->assertNull($this->entries->query()->where('id', 999)->first());
	}

	/**
	 * Raw projections may change cardinality even without explicit grouping.
	 */
	public function test_raw_aggregates_preserve_their_result_row_on_an_empty_table(): void {
		$query = $this->entries->query()->selectRaw('COUNT(*) AS total');
		$this->assertTrue($query->exists());
		$this->assertSame(1, $query->count());
		$this->assertSame(0, (int) $query->max('total'));
		$this->assertSame(0, (int) ($query->first()['total'] ?? null));
		$this->assertSame(0, $query->select('id')->count());
	}

	/**
	 * Cloned grouped queries own independent HAVING state.
	 */
	public function test_cloned_query_conditions_do_not_change_the_original(): void {
		$this->seedEntries();
		$original = $this->entries->query()->selectRaw('category, COUNT(*) AS total')->groupBy('category');
		$copy     = clone $original;
		$copy->having('total', '>', 1);
		$this->assertSame(2, $original->count());
		$this->assertSame(1, $copy->count());
	}

	/**
	 * Empty membership and escaped literal search terms have explicit meanings.
	 */
	public function test_empty_lists_and_literal_search_patterns(): void {
		$this->entries->query()->insert([
			[
				'id'   => 1,
				'name' => '100%_! complete',
			],
			[
				'id'   => 2,
				'name' => '100xyz complete',
			],
		]);
		$this->assertSame(0, $this->entries->query()->whereIn('id', [])->count());
		$this->assertSame(2, $this->entries->query()->whereNotIn('id', [])->count());
		$this->assertSame(0, $this->entries->query()->whereContainsAny('name', [])->count());
		$this->assertSame('100%_! complete', $this->entries->query()->whereContainsAny('name', [
			'%_!',
		])->first()['name'] ?? null);
		$this->assertSame(2, $this->entries->query()->whereContainsAny('name', [
			'%_!',
			'xyz',
		])->count());
		$this->assertSame(1, $this->entries->query()->whereIn('id', [
			2,
		])->count());
	}

	/**
	 * A shaped aggregate retains selected aliases and positional binding order.
	 */
	public function test_limited_aggregates_preserve_explicit_projections_and_offsets(): void {
		$this->seedEntries();
		$query = $this->entries->query('e')
			->select('e.amount as total')
			->orderBy('total', 'desc')
			->limit(2)
			->offset(1);
		$this->assertSame(2, $query->count());
		$this->assertSame(20.0, (float) $query->max('total'));
		$this->assertTrue($query->exists());
		$this->assertCount(2, $query->get());
		$this->assertSame(20.0, (float) ($query->first()['total'] ?? null));
		$this->assertSame(2, $this->entries->query()->offset(1)->count());
		$this->assertSame(0, $this->entries->query()->limit(0)->count());
		$this->assertFalse($this->entries->query()->limit(0)->exists());
		$this->assertFalse($this->entries->query()->offset(10)->exists());
		$this->assertSame(30.0, (float) $this->entries->query('e')->orderBy('e.amount', 'desc')->limit(1)->max('e.amount'));
	}

	/**
	 * Grouped and distinct aggregates count the selected rows, not base records.
	 */
	public function test_grouped_alias_aggregates_and_distinct_selection(): void {
		$this->seedEntries();
		$this->entries->query()->where('id', 3)->update([
			'category' => 'a',
		]);
		$query = $this->entries->query()
			->selectRaw('category, COUNT(*) AS total')
			->groupBy('category')
			->having('total', '>', 1);
		$this->assertSame(1, $query->count());
		$this->assertSame(3, (int) $query->max('total'));
		$this->assertTrue($query->exists());
		$this->assertSame(3, (int) $query->get()[0]['total']);
		$this->assertTrue($query->having('total', 3)->exists());
		$this->assertSame(1, $this->entries->query()->select('category')->distinct()->count());
	}

	/**
	 * Joins accept table objects and order bindings by their SQL position.
	 */
	public function test_join_callbacks_keep_value_conditions_inside_the_on_clause(): void {
		$this->seedEntries();
		$query = $this->queries->table($this->entries, 'e')
			->leftJoin($this->entries->unprefixedName() . ' as matching', static fn (JoinClause $join) => $join
				->where(static fn (JoinClause $group) => $group
					->on('matching.id', '=', 'e.id')
					->where('matching.active', true)))
			->select('e.id', 'matching.name as matched')
			->selectRaw('? AS marker', [
				'bound marker',
			])
			->where('e.id', '>', 0)
			->orderBy('e.id');
		$this->assertSame([
			'bound marker',
			true,
			0,
		], $query->getBindings());
		$rows = $query->get();
		$this->assertCount(3, $rows);
		$this->assertSame('bound marker', $rows[0]['marker']);
		$this->assertSame('First', $rows[0]['matched']);
		$this->assertNull($rows[1]['matched']);
		$this->assertSame(3, $query->count());
		$this->assertSame(3, $this->queries->table($this->entries, 'e')
			->join($this->entries, $this->entries->name() . '.id', '=', 'e.id')
			->count());
		$this->assertSame(2, $query->limit(2)->count());
	}

	/**
	 * Supported scalar conversions work under strict SQL mode.
	 */
	public function test_bulk_values_normalize_columns_booleans_and_fractional_dates(): void {
		$mode = $this->db->fetchOne('SELECT @@SESSION.sql_mode');
		$this->db->executeStatement("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

		try {
			$this->assertSame(2, (int) $this->entries->query()->insert([
				[
					'id'         => 1,
					'name'       => 'False',
					'active'     => false,
					'created_at' => new DateTimeImmutable('2026-09-25 12:34:56.123456'),
				],
				[
					'created_at' => null,
					'active'     => true,
					'name'       => 'True',
					'id'         => 2,
				],
			]));
			$rows = $this->entries->query()->orderBy('id')->get();
			$this->assertSame(0, (int) $rows[0]['active']);
			$this->assertSame(1, (int) $rows[1]['active']);
			$this->assertSame('2026-09-25 12:34:56.123456', $rows[0]['created_at']);
			$this->assertNull($rows[1]['created_at']);
		} finally {
			$this->db->executeStatement('SET SESSION sql_mode = ?', [
				$mode,
			]);
		}
	}

	/**
	 * Bulk shape errors are rejected before any row can be inserted.
	 */
	public function test_mismatched_bulk_columns_do_not_write_partial_data(): void {
		try {
			$this->smallChunkWriter()->insert(new TableReference($this->entries->name()), [
				[
					'id'   => 1,
					'name' => 'Valid first row',
				],
				[
					'id'     => 2,
					'active' => true,
				],
			]);
			$this->fail('Different column sets must be rejected.');
		} catch (InvalidArgumentException) {
			$this->assertSame(0, $this->entries->query()->count());
		}
	}

	/**
	 * Upsert uses any real unique constraint and updates only the named columns.
	 */
	public function test_upsert_matches_primary_or_unique_keys(): void {
		$this->entries->query()->insert([
			'id'    => 1,
			'name'  => 'Original',
			'email' => 'original@example.test',
		]);
		$this->entries->query()->upsert([
			[
				'id'    => 1,
				'name'  => 'By primary key',
				'email' => 'new@example.test',
			],
		], [
			'name',
		]);
		$row = $this->entries->query()->first();
		$this->assertNotNull($row);
		$this->assertSame('By primary key', $row['name']);
		$this->assertSame('original@example.test', $row['email']);
		$this->entries->query()->upsert([
			[
				'id'    => 2,
				'name'  => 'By unique email',
				'email' => 'original@example.test',
			],
		], [
			'name',
		]);
		$this->assertSame(1, $this->entries->query()->count());
		$this->assertSame('By unique email', $this->entries->query()->first()['name'] ?? null);
	}

	/**
	 * Single-table writes retain ordering and limits and require conditions.
	 */
	public function test_update_and_delete_honor_ordering_and_limits(): void {
		$this->seedEntries();
		$this->assertSame(1, (int) $this->entries->query()
			->where('id', '>', 0)
			->orderBy('id', 'desc')
			->limit(1)
			->update([
				'name' => 'Last',
			]));
		$this->assertSame('Last', $this->entries->query()->where('id', 3)->first()['name'] ?? null);
		$this->assertSame(1, (int) $this->entries->query()->where('id', '>', 0)->orderBy('id')->limit(1)->delete());
		$this->assertSame(2, $this->entries->query()->count());

		try {
			$this->entries->query()->delete();
			$this->fail('Unfiltered deletes must be rejected.');
		} catch (InvalidArgumentException) {
			$this->assertSame(2, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries->quotedName()));
		}
	}

	/**
	 * WordPress resolves shared users separately from per-site application tables.
	 */
	public function test_wordpress_references_capture_their_site_and_keep_global_users(): void {
		$this->seedEntries();
		$this->assertCount(3, $this->queries->table($this->entries, 'e')
			->leftJoin($this->queries->wordpress('users')->as('u'), 'u.ID', '=', 'e.id')
			->select('e.id', 'u.ID as user_id')
			->get());
		$originalBlog = $this->source->blogid;
		$users        = $this->source->users;
		$old          = $this->queries->table($this->queries->wordpress('posts'));
		$oldSql       = $old->toSql();

		try {
			$this->source->set_blog_id(98765);
			$this->assertSame($users, $this->queries->wordpress('users')->name);
			$this->assertSame($this->source->posts, $this->queries->wordpress('posts')->name);
			$this->assertSame($oldSql, $old->toSql());
			$sql = $this->queries->table($this->entries, 'e')
				->join($this->queries->wordpress('users')->as('u'), 'u.ID', '=', 'e.id')
				->toSql();
			$this->assertStringContainsString($this->source->prefix . $this->entries->unprefixedName(), $sql);
			$this->assertStringContainsString('`' . $users . '` AS `u`', $sql);
		} finally {
			$this->source->set_blog_id($originalBlog);
		}
	}

	/**
	 * Catching a query failure cannot turn a terminal transaction into success.
	 */
	public function test_caught_builder_execution_failure_rolls_back_prior_writes(): void {
		$this->seedEntries();

		try {
			$this->db->transactional(function (): void {
				$this->entries->query()->where('id', 1)->update([
					'name' => 'Provisional',
				]);

				try {
					$this->entries->query()->insert([
						'id'   => 1,
						'name' => 'Duplicate',
					]);
				} catch (UniqueConstraintViolationException) {
				}
			});
			$this->fail('A caught SQL error must still prevent commit.');
		} catch (TransactionFailed) {
			$this->assertSame('First', $this->observer->fetchOne('SELECT name FROM ' . $this->entries->quotedName() . ' WHERE id = 1'));
		}
	}

	/**
	 * Separate insert statements commit independently without a caller transaction.
	 */
	public function test_later_chunk_failure_leaves_earlier_chunk_without_a_transaction(): void {
		try {
			$this->smallChunkWriter()->insert(new TableReference($this->entries->name()), [
				[
					'id'   => 1,
					'name' => 'First chunk',
				],
				[
					'id'   => 1,
					'name' => 'Duplicate second chunk',
				],
			]);
			$this->fail('The second chunk must violate the primary key.');
		} catch (UniqueConstraintViolationException) {
			$this->assertSame('First chunk', $this->observer->fetchOne('SELECT name FROM ' . $this->entries->quotedName()));
			$this->assertSame(1, $this->entries->query()->count());
		}
	}

	/**
	 * A caught error in a later chunk invalidates the caller's whole transaction.
	 */
	public function test_caught_later_chunk_failure_rolls_back_the_whole_import(): void {
		try {
			$this->db->transactional(function (): void {
				try {
					$this->smallChunkWriter()->insert(new TableReference($this->entries->name()), [
						[
							'id'   => 1,
							'name' => 'Provisional first chunk',
						],
						[
							'id'   => 1,
							'name' => 'Duplicate second chunk',
						],
					]);
				} catch (UniqueConstraintViolationException) {
				}
			});
			$this->fail('A caught chunk error must still prevent commit.');
		} catch (TransactionFailed) {
			$this->assertSame(0, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM ' . $this->entries->quotedName()));
		}
	}

	/**
	 * An unsupported condition cannot exploit null rewriting to bypass validation.
	 */
	public function test_invalid_operator_is_rejected_even_for_null(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->entries->query()->where('name', 'LIKE', null);
	}

	/**
	 * A grouped aggregate cannot silently add a missing column to its projection.
	 */
	public function test_grouped_column_aggregate_rejects_an_unselected_column(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Aggregate column "amount" is not selected.');
		$this->entries->query()->select('category')->groupBy('category')->max('amount');
	}

	/**
	 * Quoted column names follow the same rules for insertion and updates.
	 */
	public function test_insert_and_update_accept_the_same_digit_leading_column(): void {
		$this->observer->executeStatement('ALTER TABLE ' . $this->entries->quotedName() . ' ADD COLUMN `1col` VARCHAR(30)');
		$this->assertSame(1, $this->entries->query()->insert([
			'id'   => 10,
			'name' => 'Inserted',
			'1col' => 'before',
		]));
		$this->assertSame(1, $this->entries->query()->where('id', 10)->update([
			'1col' => 'after',
		]));
		$this->assertSame('after', $this->entries->query()->select('1col')->first()['1col'] ?? null);
	}

	/**
	 * Explicit output names, aliases and wildcards remain usable by shaped aggregates.
	 */
	public function test_aggregates_accept_selected_outputs_without_changing_the_projection(): void {
		$this->seedEntries();
		$query = $this->entries->query('e')->select('e.amount')->limit(2);
		$sql   = $query->toSql();
		$this->assertSame(30.0, (float) $query->max('e.amount'));
		$this->assertSame($sql, $query->toSql());
		$this->assertSame(30.0, (float) $query->select('e.amount AS Total')->max('total'));
		$this->assertSame(30.0, (float) $query->select('e.*')->max('amount'));
		$this->assertSame(30.0, (float) $query->select('*')->max('amount'));
		$this->assertSame(30.0, (float) $query->select()->max('amount'));
	}

	/**
	 * Select-only state cannot silently disappear from a mutation.
	 */
	public function test_shaped_writes_reject_unhonored_state(): void {
		$this->seedEntries();

		try {
			$this->entries->query()->select('id')->where('id', 1)->update([
				'name' => 'Must not run',
			]);
			$this->fail('An update cannot honor a selection.');
		} catch (InvalidArgumentException) {
			$this->assertSame('First', $this->entries->query()->where('id', 1)->first()['name'] ?? null);
		}

		try {
			$this->entries->query()->where('id', 999)->insert([
				'id'   => 4,
				'name' => 'Must not run',
			]);
			$this->fail('An insert cannot honor a condition.');
		} catch (InvalidArgumentException) {
			$this->assertSame(3, $this->entries->query()->count());
		}
	}

	private function smallChunkWriter(): InsertWriter {
		return new InsertWriter(
			$this->container->get(Executor::class),
			$this->container->get(ServerBuilder::class),
			$this->container->get(IdentifierQuoter::class),
			2,
		);
	}

	private function seedEntries(): void {
		$this->entries->query()->insert([
			[
				'id'       => 1,
				'name'     => 'First',
				'category' => 'a',
				'amount'   => '10.00',
				'active'   => true,
			],
			[
				'id'       => 2,
				'name'     => 'Second',
				'category' => 'a',
				'amount'   => '30.00',
				'active'   => false,
			],
			[
				'id'       => 3,
				'name'     => 'Third',
				'category' => null,
				'amount'   => '20.00',
				'active'   => true,
			],
		]);
	}
}
