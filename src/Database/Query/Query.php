<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use Closure;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Query\ValueObjects\Fragment;
use StellarWP\Foundation\Database\Query\ValueObjects\QueryState;
use StellarWP\Foundation\Database\Query\ValueObjects\Selection;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;

/**
 * Describe a table query fluently and execute it on the shared managed connection.
 *
 * Terminal operations preserve this builder for subsequent reads.
 */
final class Query extends WhereGroup
{
	/**
	 * @var list<Selection>
	 */
	private array $selection = [];

	/**
	 * @var list<Fragment>
	 */
	private array $joins = [];

	/**
	 * @var list<string>
	 */
	private array $groups = [];

	/**
	 * @var list<string>
	 */
	private array $orders  = [];
	private bool $distinct = false;
	private ?int $limit    = null;
	private ?int $offset   = null;
	private WhereGroup $having;

	/**
	 * Receive shared collaborators and this query's resolved source.
	 *
	 * @internal Obtain fresh queries from a Table or Database.
	 */
	public function __construct(
		private readonly Compiler $compiler,
		private readonly Executor $executor,
		private readonly InsertWriter $writer,
		private readonly TableReferenceResolver $resolver,
		private readonly TableReference $table,
		IdentifierQuoter $quoter,
	) {
		$this->having = new WhereGroup($quoter);
		parent::__construct($quoter);
	}

	/**
	 * Select named columns, qualified columns, wildcards, or columns with aliases.
	 *
	 * @throws InvalidArgumentException When a column or output alias is invalid.
	 */
	public function select(string ...$columns): self {
		$this->selection = array_map(
			$this->quoter->selection(...),
			array_values($columns),
		);

		return $this;
	}

	/**
	 * Add a trusted SQL projection with positional values supplied separately.
	 *
	 * @param list<mixed> $bindings
	 */
	public function selectRaw(string $sql, array $bindings = []): self {
		$this->selection[] = new Selection(new Fragment($sql, $bindings));

		return $this;
	}

	/**
	 * Return distinct selected rows.
	 */
	public function distinct(): self {
		$this->distinct = true;

		return $this;
	}

	/**
	 * Require a matching row using column relationships or a join closure.
	 *
	 * @param string|Closure(JoinClause): mixed $first
	 *
	 * @throws InvalidArgumentException When a table, column, or join condition is invalid.
	 * @throws DatabaseException        When the application table name is invalid.
	 */
	public function join(Table|string|TableReference $table, string|Closure $first, ?string $operator = null, ?string $second = null): self {
		return $this->addJoin('INNER', $table, $first, $operator, $second);
	}

	/**
	 * Include the source row even when no joined row matches.
	 *
	 * @param string|Closure(JoinClause): mixed $first
	 *
	 * @throws InvalidArgumentException When a table, column, or join condition is invalid.
	 * @throws DatabaseException        When the application table name is invalid.
	 */
	public function leftJoin(Table|string|TableReference $table, string|Closure $first, ?string $operator = null, ?string $second = null): self {
		return $this->addJoin('LEFT', $table, $first, $operator, $second);
	}

	/**
	 * Group results by the supplied columns.
	 *
	 * @throws InvalidArgumentException When a grouping column is invalid.
	 */
	public function groupBy(string ...$columns): self {
		array_push($this->groups, ...array_map($this->quoter->column(...), $columns));

		return $this;
	}

	/**
	 * Filter grouped results by a column or selected alias.
	 *
	 * @throws InvalidArgumentException When a column or comparison is invalid.
	 */
	public function having(string $column, mixed $operator = null, mixed $value = null): self {
		if (func_num_args() === 2) {
			$this->having->where($column, $operator);
		} else {
			$this->having->where($column, $operator, $value);
		}

		return $this;
	}

	/**
	 * Order by a column or selected alias.
	 *
	 * @throws InvalidArgumentException When the direction is not asc or desc.
	 */
	public function orderBy(string $column, string $direction = 'asc'): self {
		$direction = strtoupper($direction);

		if (! in_array($direction, [
			'ASC',
			'DESC',
		], true)) {
			throw new InvalidArgumentException('Order direction must be asc or desc.');
		}

		$this->orders[] = $this->quoter->column($column) . ' ' . $direction;

		return $this;
	}

	/**
	 * Limit the result to a non-negative number of rows.
	 *
	 * @throws InvalidArgumentException When the row limit is negative.
	 */
	public function limit(int $rows): self {
		if ($rows < 0) {
			throw new InvalidArgumentException('The row limit cannot be negative.');
		}

		$this->limit = $rows;

		return $this;
	}

	/**
	 * Skip a non-negative number of result rows.
	 *
	 * @throws InvalidArgumentException When the row offset is negative.
	 */
	public function offset(int $rows): self {
		if ($rows < 0) {
			throw new InvalidArgumentException('The row offset cannot be negative.');
		}

		$this->offset = $rows;

		return $this;
	}

	/**
	 * Read all selected rows as associative arrays.
	 *
	 * @throws Exception                When execution fails.
	 * @throws InvalidArgumentException For unsupported bound values.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get(): array {
		return $this->executor->rows($this->compiler->select($this->state()));
	}

	/**
	 * Read the first selected row, or null when the query is empty.
	 *
	 * @throws Exception                When execution fails.
	 * @throws InvalidArgumentException For unsupported bound values.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		$result = $this->executor->first($this->compiler->select($this->state(), maximum: 1));

		return $result === false ? null : $result;
	}

	/**
	 * Count result rows, including the effects of grouping, distinctness, and paging.
	 *
	 * @throws Exception                When execution fails.
	 * @throws InvalidArgumentException For unsupported bound values or ambiguous shaped join projections.
	 */
	public function count(): int {
		return (int) $this->executor->value($this->compiler->aggregate($this->state(), 'COUNT', '*'));
	}

	/**
	 * Read the maximum column value, or null for an empty result.
	 *
	 * @throws Exception                When execution fails, including unresolved raw projection columns.
	 * @throws InvalidArgumentException For invalid or unselected columns, unsupported values, or ambiguous shaped join projections.
	 */
	public function max(string $column): mixed {
		return $this->executor->value($this->compiler->aggregate($this->state(), 'MAX', $column));
	}

	/**
	 * Determine whether this query returns any rows.
	 *
	 * @throws Exception                When execution fails.
	 * @throws InvalidArgumentException For unsupported bound values.
	 */
	public function exists(): bool {
		return (bool) $this->executor->value($this->compiler->exists($this->state()));
	}

	/**
	 * Insert one row or a list of rows; wrap large writes in transactional() for atomicity.
	 *
	 * @param array<string, mixed>|list<array<string, mixed>> $rows
	 *
	 * @throws InvalidArgumentException When rows or accumulated query options are invalid.
	 * @throws Exception                When a statement fails; earlier chunks remain unless inside a transaction.
	 */
	public function insert(array $rows): int {
		$this->compiler->insertable($this->state());

		return $this->writer->insert($this->table, $rows);
	}

	/**
	 * Insert exactly one row and return its generated identifier without truncation.
	 *
	 * Empty data inserts one row using the database defaults.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws InvalidArgumentException When data is not a single row or query options are invalid.
	 * @throws Exception                When insertion fails or no generated identifier is available.
	 */
	public function insertGetId(array $data): int|string {
		$this->compiler->insertable($this->state());

		return $this->writer->insertGetId($this->table, $data);
	}

	/**
	 * Insert rows, updating selected columns on any primary or unique-key conflict.
	 *
	 * @param array<string, mixed>|list<array<string, mixed>> $rows
	 * @param list<string>                                    $update Columns to replace with incoming values.
	 *
	 * @throws InvalidArgumentException When rows, update columns, or query options are invalid.
	 * @throws Exception                When a statement fails; use transactional() for atomic multi-chunk writes.
	 */
	public function upsert(array $rows, array $update): int {
		$this->compiler->insertable($this->state());

		if ($update === []) {
			throw new InvalidArgumentException('An upsert requires at least one update column.');
		}

		return $this->writer->insert($this->table, $rows, $update);
	}

	/**
	 * Update rows matching this query's conditions.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @throws InvalidArgumentException When filtering is omitted or query options cannot be honored.
	 * @throws Exception                When execution fails.
	 */
	public function update(array $values): int|string {
		return $this->executor->statement($this->compiler->update($this->state(), $values));
	}

	/**
	 * Delete rows matching this query's conditions.
	 *
	 * @throws InvalidArgumentException When filtering is omitted or query options cannot be honored.
	 * @throws Exception                When execution fails.
	 */
	public function delete(): int|string {
		return $this->executor->statement($this->compiler->delete($this->state()));
	}

	/**
	 * Inspect parameterized SELECT SQL without executing it.
	 */
	public function toSql(): string {
		return $this->compiler->select($this->state())->sql;
	}

	/**
	 * Inspect SELECT values in placeholder order.
	 *
	 * @return list<mixed>
	 */
	public function getBindings(): array {
		return $this->compiler->select($this->state())->bindings;
	}

	/**
	 * Keep a cloned builder's grouped conditions independent.
	 */
	public function __clone() {
		$this->having = clone $this->having;
	}

	/**
	 * Capture an immutable snapshot for compilation.
	 */
	private function state(): QueryState {
		return new QueryState(
			$this->table,
			$this->selection,
			$this->joins,
			$this->condition(),
			$this->distinct,
			$this->groups,
			$this->having->condition(),
			$this->orders,
			$this->limit,
			$this->offset,
		);
	}

	/**
	 * Capture a completed join without retaining its mutable clause builder.
	 *
	 * @param string|Closure(JoinClause): mixed $first
	 */
	private function addJoin(string $type, Table|string|TableReference $table, string|Closure $first, ?string $operator, ?string $second): self {
		$clause = new JoinClause($this->quoter);

		if ($first instanceof Closure) {
			$first($clause);
		} else {
			if ($operator === null || $second === null) {
				throw new InvalidArgumentException('A join requires two column names and a comparison operator.');
			}

			$clause->on($first, $operator, $second);
		}

		$condition = $clause->condition();

		if ($condition->sql === '') {
			throw new InvalidArgumentException('A join requires at least one ON condition.');
		}

		$this->joins[] = new Fragment(
			$type . ' JOIN ' . $this->resolver->sql($this->resolver->resolve($table)) . ' ON ' . $condition->sql,
			$condition->bindings,
		);

		return $this;
	}
}
