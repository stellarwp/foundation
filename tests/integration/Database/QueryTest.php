<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
	 * Raw conditions compose with normal groups and retain bound numeric comparisons.
	 */
	public function test_raw_conditions_preserve_precedence_and_bind_values_by_type(): void {
		$this->seedEntries();
		$query = $this->entries->query()
			->selectRaw('? AS marker, id', [
				'bound projection',
			])
			->where('active', true)
			->where(static fn (WhereGroup $group) => $group->whereRaw('amount > ?', [
				15,
			])->orWhereRaw('name = ?', [
				'First',
			]))
			->whereRaw('? <= ?', [
				500,
				2000,
			])
			->whereRaw('(created_at IS NULL OR created_at >= NOW())')
			->orderBy('id');
		$this->assertSame([
			[
				'marker' => 'bound projection',
				'id'     => 1,
			],
			[
				'marker' => 'bound projection',
				'id'     => 3,
			],
		], $query->get());
		$this->assertSame([], $this->entries->query()->where('id', 999)->orWhereRaw('name = ?', [
			"First' OR 1 = 1 --",
		])->get());
	}

	/**
	 * Raw write predicates retain update-value ordering and boolean parameter normalization.
	 */
	public function test_raw_conditions_apply_to_updates_and_deletes(): void {
		$this->seedEntries();
		$this->assertSame(2, $this->entries->query()->whereRaw('amount >= ?', [
			20,
		])->update([
			'category' => 'updated',
		]));
		$this->assertSame(1, $this->entries->query()->whereRaw('category = ?', [
			'updated',
		])->whereRaw('active = ?', [
			false,
		])->delete());
		$this->assertSame([
			1,
			3,
		], $this->entries->query()->orderBy('id')->pluck('id'));
	}

	/**
	 * Raw ordering composes with column ordering and survives repeated terminal reads.
	 */
	public function test_raw_ordering_preserves_order_bindings_and_query_state(): void {
		$this->seedEntries();
		$query = $this->entries->query()->where('id', '>', 0)
			->orderByRaw('active = ? DESC', [
				true,
			])
			->orderByRaw('id = ? DESC', [
				3,
			])
			->orderBy('id');
		$sql = $query->toSql();
		$this->assertSame([
			3,
			1,
			2,
		], $query->pluck('id'));
		$this->assertSame(3, $query->first()['id'] ?? null);
		$this->assertSame(3, $query->count());
		$this->assertSame('60.00', $query->sum('amount'));
		$this->assertSame('30.00', $query->max('amount'));
		$this->assertTrue($query->exists());
		$this->assertSame(3, $query->get()[0]['id']);
		$this->assertSame($sql, $query->toSql());

		$query->limit(1);
		$this->assertSame(1, $query->count());
		$this->assertSame('20.00', $query->sum('amount'));
		$this->assertSame('20.00', $query->max('amount'));
		$this->assertTrue($query->exists());
		$query->offset(1);
		$this->assertSame('10.00', $query->sum('amount'));
	}

	/**
	 * Ordering parameters follow projection, join, filter and HAVING parameters.
	 */
	public function test_raw_ordering_follows_all_other_read_bindings(): void {
		$this->seedEntries();
		$query = $this->entries->query('e')
			->selectRaw('? AS marker, e.category, SUM(e.amount) AS total', [
				'bound projection',
			])
			->join('foundation_consumer_entries as other', static fn (JoinClause $join) => $join
				->on('e.id', '=', 'other.id')->where('other.id', '>', 0))
			->where('e.amount', '>=', 10)
			->groupBy('e.category')
			->having('total', '>', 15)
			->orderByRaw('e.category <=> ? DESC', [
				null,
			]);
		$this->assertSame([
			[
				'marker'   => 'bound projection',
				'category' => null,
				'total'    => '20.00',
			],
			[
				'marker'   => 'bound projection',
				'category' => 'a',
				'total'    => '40.00',
			],
		], $query->get());
		$this->assertSame(2, $query->count());
		$this->assertSame('60.00', $query->sum('total'));
	}

	/**
	 * Ordered writes bind SET and WHERE values before ordering parameters.
	 */
	public function test_raw_ordering_selects_rows_for_limited_writes(): void {
		$this->seedEntries();
		$this->assertSame(1, $this->entries->query()->where('id', '>', 0)
			->orderByRaw('id = ? DESC', [
				2,
			])->limit(1)->update([
				'category' => 'chosen',
			]));
		$this->assertSame(2, $this->entries->query()->where('category', 'chosen')->first()['id'] ?? null);
		$this->assertSame(1, $this->entries->query()->where('active', true)
			->orderByRaw('id = ? DESC', [
				3,
			])->limit(1)->delete());
		$this->assertSame([
			1,
			2,
		], $this->entries->query()->orderBy('id')->pluck('id'));
	}

	/**
	 * Reject blank ordering without retaining its unused bindings or changing the query.
	 */
	public function test_blank_raw_ordering_leaves_existing_order_unchanged(): void {
		$this->seedEntries();
		$query = $this->entries->query()->orderBy('id', 'desc');
		$sql   = $query->toSql();

		try {
			$query->orderByRaw(" \t\n", [
				'unused',
			]);
			$this->fail('Blank raw ordering must be rejected.');
		} catch (InvalidArgumentException $failure) {
			$this->assertSame('A raw ordering expression must contain SQL.', $failure->getMessage());
		}

		$this->assertSame($sql, $query->toSql());
		$this->assertSame([
			3,
			2,
			1,
		], $query->pluck('id'));
	}

	/**
	 * Column reads preserve filters, ordering and paging without changing the original projection.
	 */
	public function test_pluck_preserves_query_state_and_reads_a_qualified_column(): void {
		$this->seedEntries();
		$query = $this->entries->query('e')
			->selectRaw('? AS marker', [
				'original',
			])
			->where('e.active', true)
			->orderBy('e.id', 'desc')
			->limit(1)
			->offset(1);
		$sql  = $query->toSql();
		$rows = $query->get();

		$this->assertSame([
			'First',
		], $query->pluck('e.name'));
		$this->assertSame([
			'10.00',
		], $query->pluck('e.amount'));
		$this->assertSame($sql, $query->toSql());
		$this->assertSame($rows, $query->get());
	}

	/**
	 * Column lists preserve nulls and duplicates, with empty and distinct results represented directly.
	 */
	public function test_pluck_preserves_nulls_duplicates_and_empty_results(): void {
		$this->assertSame([], $this->entries->query()->pluck('id'));
		$this->seedEntries();
		$this->assertSame([
			'a',
			'a',
			null,
		], $this->entries->query()->orderBy('id')->pluck('category'));
		$this->assertSame([
			null,
			'a',
		], $this->entries->query()->distinct()->orderBy('category')->pluck('category'));
		$this->assertSame([], $this->entries->query()->where('id', 999)->pluck('name'));
		$this->assertSame([], $this->entries->query()->limit(0)->pluck('name'));
	}

	/**
	 * Pluck accepts exactly one column, never a wildcard projection.
	 */
	public function test_pluck_rejects_a_wildcard(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->entries->query()->pluck('*');
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
	 * Single substring searches bind literal text and retain SQL null semantics.
	 */
	public function test_single_substring_search_matches_literal_text_and_empty_strings(): void {
		$this->entries->insert([
			[
				'id'       => 1,
				'name'     => '100%_! complete',
				'category' => 'literal',
			],
			[
				'id'       => 2,
				'name'     => '100xyz complete',
				'category' => null,
			],
			[
				'id'       => 3,
				'name'     => "x' OR 1 = 1 --",
				'category' => '',
			],
		]);

		$this->assertSame('100%_! complete', $this->entries->query('e')->whereContains('e.name', '%_!')->first()['name'] ?? null);
		$this->assertSame(1, $this->entries->query()->whereContains('name', "x' OR 1 = 1 --")->count());
		$this->assertSame(0, $this->entries->query()->whereContains('name', 'absent')->count());
		$this->assertSame(2, $this->entries->query()->whereContains('category', '')->count());
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
	 * Sums retain decimal precision and aggregate only the filtered result rows.
	 */
	public function test_sum_preserves_exact_decimals_and_query_state(): void {
		$this->seedEntries();
		$this->entries->update([
			'amount' => '9999999999.99',
		], [
			'id' => 1,
		]);
		$query = $this->entries->query()->where('active', true)->orderBy('id');
		$sql   = $query->toSql();
		$rows  = $query->get();

		$this->assertSame('10000000019.99', $query->sum('amount'));
		$this->assertSame($sql, $query->toSql());
		$this->assertSame($rows, $query->get());
		$this->assertSame('9999999999.99', $query->limit(1)->sum('amount'));
		$this->assertSame('20.00', $query->offset(1)->sum('amount'));
	}

	/**
	 * Approximate numeric expressions retain the database's floating-point result.
	 */
	public function test_sum_preserves_floating_point_results(): void {
		$this->seedEntries();
		$this->assertSame(60.0, $this->entries->query()->selectRaw('amount * 1e0 AS approximate_amount')->sum('approximate_amount'));
	}

	/**
	 * Empty and all-null inputs return integer zero; numeric zero keeps its database representation.
	 */
	public function test_sum_returns_zero_when_no_non_null_values_remain(): void {
		$this->assertSame(0, $this->entries->query()->sum('amount'));
		$this->seedEntries();
		$this->assertSame(0, $this->entries->query()->where('id', 999)->sum('amount'));
		$this->assertSame(0, $this->entries->query()->limit(0)->sum('amount'));
		$this->assertSame(0, $this->entries->query()->offset(10)->sum('amount'));
		$this->assertSame(0, $this->entries->query()->selectRaw('NULL AS amount')->sum('amount'));
		$this->assertSame('0.00', $this->entries->query()->selectRaw('amount - amount AS zero')->sum('zero'));
	}

	/**
	 * Grouping, distinctness and aliases define the values supplied to a sum.
	 */
	public function test_sum_preserves_grouped_distinct_and_aliased_projections(): void {
		$this->seedEntries();
		$this->assertSame('40.00', $this->entries->query('e')->select('e.amount AS total')->orderBy('e.id')->limit(2)->sum('total'));
		$this->assertSame('1', $this->entries->query()->select('active')->distinct()->sum('active'));
		$this->assertSame('40.00', $this->entries->query()
			->selectRaw('category, SUM(amount) AS total')
			->groupBy('category')
			->having('total', '>', 25)
			->sum('total'));
	}

	/**
	 * Summing must not silently rewrite a limited query's explicit selection.
	 */
	public function test_sum_rejects_an_unselected_column_in_a_limited_query(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Aggregate column "amount" is not selected.');
		$this->entries->query()->select('name')->limit(10)->sum('amount');
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

	/**
	 * Arithmetic updates support defaults, signed amounts and fractions without mutating the query.
	 */
	public function test_increment_and_decrement_change_only_matching_rows(): void {
		$this->seedEntries();
		$query = $this->entries->query()->where('id', 1);
		$sql   = $query->toSql();
		$this->assertSame(1, $query->increment('amount'));
		$this->assertSame('11.00', $query->first()['amount'] ?? null);
		$this->assertSame(1, $query->decrement('amount'));
		$this->assertSame('10.00', $query->first()['amount']);
		$this->assertSame(1, $query->increment('amount', 2.5));
		$this->assertSame(1, $query->decrement('amount', 0.25));
		$this->assertSame('12.25', $query->first()['amount']);
		$this->assertSame(1, $query->increment('amount', -2));
		$this->assertSame(1, $query->decrement('amount', -3));
		$query->increment('amount', 0);
		$this->assertSame('13.25', $query->first()['amount']);
		$this->assertSame($sql, $query->toSql());
		$this->assertSame('30.00', $this->entries->query()->where('id', 2)->first()['amount'] ?? null);
		$this->assertSame(0, $this->entries->query()->where('id', 999)->decrement('amount'));
	}

	/**
	 * The arithmetic operand precedes WHERE and ORDER BY bindings in limited updates.
	 */
	public function test_arithmetic_updates_honor_ordering_and_limits(): void {
		$this->seedEntries();
		$query = $this->entries->query()->where('active', true)
			->orderByRaw('id = ? DESC', [
				3,
			])->limit(1);
		$this->assertSame(1, $query->increment('amount', 5));
		$this->assertSame('25.00', $this->entries->query()->where('id', 3)->first()['amount'] ?? null);
		$this->assertSame(1, $query->decrement('amount', 2));
		$this->assertSame('23.00', $this->entries->query()->where('id', 3)->first()['amount'] ?? null);
		$this->assertSame('10.00', $this->entries->query()->where('id', 1)->first()['amount'] ?? null);
	}

	/**
	 * Integer parameters preserve large integer values and SQL leaves null operands null.
	 */
	public function test_arithmetic_preserves_large_integers_and_nulls(): void {
		$this->observer->executeStatement('ALTER TABLE ' . $this->entries->quotedName() . ' ADD counter BIGINT NULL');
		$this->seedEntries();
		$query = $this->entries->query()->where('id', 1);
		$query->update([
			'counter' => '9007199254740992',
		]);
		$query->increment('counter');
		$this->assertSame('9007199254740993', (string) ($query->first()['counter'] ?? ''));
		$query->decrement('counter');
		$this->assertSame('9007199254740992', (string) ($query->first()['counter'] ?? ''));
		$this->entries->query()->where('id', 2)->increment('counter');
		$this->assertSame([
			null,
		], $this->entries->query()->where('id', 2)->pluck('counter'));
	}

	/**
	 * Arithmetic uses the caller's transaction and preserves its escaping business failure.
	 */
	public function test_arithmetic_updates_participate_in_managed_transactions(): void {
		$this->seedEntries();
		$failure = new RuntimeException('Cancel the operation.');

		try {
			$this->db->transactional(function () use ($failure): void {
				$this->entries->query()->where('id', 1)->increment('amount', 5);
				$this->entries->query()->where('id', 2)->decrement('amount', 3);

				throw $failure;
			});
		} catch (RuntimeException $caught) {
			$this->assertSame($failure, $caught);
		}

		$this->assertSame('10.00', $this->entries->query()->where('id', 1)->first()['amount'] ?? null);
		$this->assertSame('30.00', $this->entries->query()->where('id', 2)->first()['amount'] ?? null);
		$this->db->transactional(fn () => $this->entries->query()->where('id', 1)->increment('amount', 5));
		$this->assertSame('15.00', $this->observer->fetchOne('SELECT amount FROM ' . $this->entries->quotedName() . ' WHERE id = 1'));
	}

	/**
	 * Arithmetic shares ordinary update restrictions and rejects invalid operands before execution.
	 *
	 * @dataProvider invalidArithmetic
	 */
	#[DataProvider('invalidArithmetic')]
	public function test_arithmetic_rejects_invalid_requests(string $method, string $option, string $column, int|float $amount): void {
		$query = $this->entries->query();

		if ($option !== 'unfiltered') {
			$query->where('id', 1);
		}

		if ($option === 'projection') {
			$query->select('amount');
		}

		$this->expectException(InvalidArgumentException::class);
		$query->{$method}($column, $amount);
	}

	/**
	 * Invalid requests must fail equally for both arithmetic operations.
	 *
	 * @return iterable<string, array{string, string, string, int|float}>
	 */
	public static function invalidArithmetic(): iterable {
		foreach ([
			'increment',
			'decrement',
		] as $method) {
			foreach ([
				'unfiltered'   => [
					'unfiltered',
					'amount',
					1,
				],
				'projection'   => [
					'projection',
					'amount',
					1,
				],
				'column'       => [
					'filtered',
					'amount + 1',
					1,
				],
				'infinity'     => [
					'filtered',
					'amount',
					INF,
				],
				'not-a-number' => [
					'filtered',
					'amount',
					NAN,
				],
			] as $name => $arguments) {
				yield $method . '-' . $name => [
					$method,
					...$arguments,
				];
			}
		}
	}

	/**
	 * A locking read holds the row after nested success and releases it with the outer transaction.
	 *
	 * @dataProvider lockingReads
	 */
	#[DataProvider('lockingReads')]
	public function test_locking_reads_block_another_writer_until_commit_or_rollback(string $method, bool $rollback): void {
		$this->seedEntries();
		$this->observer->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
		$failure = new RuntimeException('Cancel the locked operation.');

		try {
			$this->db->transactional(function () use ($method, $rollback, $failure): void {
				$this->db->transactional(function () use ($method): void {
					$query = $this->entries->query()->where('id', 1)->lockForUpdate();
					$rows  = $method === 'pluck' ? $query->pluck('name') : $query->{$method}();
					$this->assertNotEmpty($rows);
				});

				$this->assertSame('First', $this->observer->fetchOne('SELECT name
					FROM ' . $this->entries->quotedName() . '
					WHERE id = 1'));

				try {
					$this->observer->executeStatement('UPDATE ' . $this->entries->quotedName() . "
						SET name = 'Competing write'
						WHERE id = 1");
					$this->fail('The locking read must block another writer.');
				} catch (LockWaitTimeoutException) {
					$this->assertTrue($this->db->isTransactionActive());
				}

				if ($rollback) {
					throw $failure;
				}
			});
		} catch (RuntimeException $caught) {
			$this->assertSame($failure, $caught);
		}

		$this->assertSame(1, $this->observer->executeStatement('UPDATE ' . $this->entries->quotedName() . "
			SET name = 'Released'
			WHERE id = 1"));
		$this->assertSame('Released', $this->entries->query()->where('id', 1)->first()['name'] ?? null);
	}

	/**
	 * Each row-reading terminal must carry the lock through both outer completion paths.
	 *
	 * @return iterable<string, array{string, bool}>
	 */
	public static function lockingReads(): iterable {
		foreach ([
			'first',
			'get',
			'pluck',
		] as $method) {
			yield $method . '-commit' => [
				$method,
				false,
			];

			yield $method . '-rollback' => [
				$method,
				true,
			];
		}
	}

	/**
	 * Locking SELECTs keep pagination, parameters, aggregate wrappers and independent clone state.
	 */
	public function test_locking_reads_preserve_query_options_and_bindings(): void {
		$this->seedEntries();
		$original = $this->entries->query()->where('id', '>', 0)->orderByRaw('id + ?', [
			0,
		])->limit(2)->offset(1);
		$query = clone $original;
		$query->lockForUpdate()->lockForUpdate();
		$sql = $query->toSql();
		$this->assertStringEndsWith("LIMIT 2 OFFSET 1\nFOR UPDATE", $sql);
		$this->assertStringNotContainsString('FOR UPDATE', $original->toSql());
		$this->assertSame([
			0,
			0,
		], $query->getBindings());
		$this->assertFalse($this->db->isTransactionActive());
		$this->db->transactional(function () use ($query): void {
			$this->assertSame(2, $query->first()['id'] ?? null);
			$this->assertSame([
				2,
				3,
			], $query->pluck('id'));
			$this->assertCount(2, $query->get());
			$this->assertSame(2, $query->count());
			$this->assertSame('30.00', $query->max('amount'));
			$this->assertSame('50.00', $query->sum('amount'));
			$this->assertTrue($query->exists());
			$this->assertSame(3, $this->entries->query()->lockForUpdate()->count());
			$this->assertTrue($this->entries->query()->where('id', 1)->lockForUpdate()->exists());
			$this->assertFalse($this->entries->query()->lockForUpdate()->limit(0)->exists());
		});
		$this->assertSame($sql, $query->toSql());
	}

	/**
	 * Write terminals must not silently ignore a requested locking read.
	 *
	 * @param 'insert'|'insertGetId'|'upsert'|'update'|'increment'|'decrement'|'delete' $method
	 *
	 * @dataProvider lockedWrites
	 */
	#[DataProvider('lockedWrites')]
	public function test_writes_reject_the_read_lock_option(string $method): void {
		$query = $this->entries->query()->where('id', 1)->lockForUpdate();
		$this->expectException(InvalidArgumentException::class);

		match ($method) {
			'insert'      => $this->entries->query()->lockForUpdate()->insert([]),
			'insertGetId' => $this->entries->query()->lockForUpdate()->insertGetId([]),
			'upsert'      => $this->entries->query()->lockForUpdate()->upsert([], [
				'name',
			]),
			'update'      => $query->update([
				'name' => 'Changed',
			]),
			'increment'   => $query->increment('amount'),
			'decrement'   => $query->decrement('amount'),
			'delete'      => $query->delete(),
		};
	}

	/**
	 * Every write terminal must honor the read-only option boundary.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function lockedWrites(): iterable {
		foreach ([
			'insert',
			'insertGetId',
			'upsert',
			'update',
			'increment',
			'decrement',
			'delete',
		] as $method) {
			yield $method => [
				$method,
			];
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
