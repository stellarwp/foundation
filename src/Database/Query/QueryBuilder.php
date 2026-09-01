<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Builds small, inspectable SELECT queries for WordPress database tables.
 */
final class QueryBuilder
{
	/**
	 * @var list<string>
	 */
	private array $columns = ['*'];

	/**
	 * @var list<string>
	 */
	private array $where = [];

	/**
	 * @var list<mixed>
	 */
	private array $bindings = [];

	/**
	 * @var list<string>
	 */
	private array $orderBy = [];

	private ?int $limit = null;

	private ?int $offset = null;

	private ?string $aggregate = null;

	public function __construct(
		private readonly Database $database,
		private readonly Table $table,
		private readonly ?string $alias = null
	) {
	}

	public function select(string ...$columns): self {
		$this->columns = $columns === [] ? ['*'] : array_values($columns);

		return $this;
	}

	/**
	 * Compare a column to a value. NULL values use IS NULL or IS NOT NULL semantics.
	 *
	 * @throws InvalidArgumentException When the column or operator is invalid, or the operator cannot compare against NULL.
	 */
	public function where(string $column, string $operator, mixed $value): self {
		$operator = $this->operator($operator);

		if ($value === null) {
			if (! in_array($operator, ['=', '!=', '<>'], true)) {
				throw new InvalidArgumentException('NULL comparisons only support =, !=, and <> operators.');
			}

			$this->where[] = sprintf(
				'%s IS%s NULL',
				$this->quoteColumn($column),
				$operator === '=' ? '' : ' NOT'
			);

			return $this;
		}

		$this->where[]    = sprintf('%s %s %%s', $this->quoteColumn($column), $operator);
		$this->bindings[] = $value;

		return $this;
	}

	/**
	 * @throws InvalidArgumentException When the column or direction is invalid.
	 */
	public function orderBy(string $column, string $direction = 'ASC'): self {
		$direction = strtoupper($direction);

		if (! in_array($direction, ['ASC', 'DESC'], true)) {
			throw new InvalidArgumentException('Order direction must be ASC or DESC.');
		}

		$this->orderBy[] = sprintf('%s %s', $this->quoteColumn($column), $direction);

		return $this;
	}

	/**
	 * @throws InvalidArgumentException When the limit or offset is invalid.
	 */
	public function limit(int $limit, ?int $offset = null): self {
		if ($limit < 1) {
			throw new InvalidArgumentException('Query limit must be greater than zero.');
		}

		if ($offset !== null && $offset < 0) {
			throw new InvalidArgumentException('Query offset cannot be negative.');
		}

		$this->limit  = $limit;
		$this->offset = $offset;

		return $this;
	}

	/**
	 * Return the maximum value for a column matching the current predicates.
	 *
	 * Ordering and pagination do not constrain aggregate input and are ignored.
	 *
	 * @throws DatabaseException        When table-name resolution or query execution fails.
	 * @throws InvalidArgumentException When the column is invalid.
	 */
	public function max(string $column): mixed {
		$query            = clone $this;
		$query->aggregate = sprintf('MAX(%s)', $query->quoteColumn($column));
		$query->orderBy   = [];
		$query->limit     = null;
		$query->offset    = null;

		return $query->toQuery()->value();
	}

	/**
	 * @throws DatabaseException        When table-name resolution or validation fails.
	 * @throws InvalidArgumentException When a selected column is invalid.
	 */
	public function toQuery(): Query {
		return new Query($this->database, $this->toSql(), $this->bindings());
	}

	/**
	 * @throws DatabaseException        When table-name resolution or validation fails.
	 * @throws InvalidArgumentException When a selected column is invalid.
	 */
	public function toSql(): string {
		$sql = sprintf(
			'SELECT %s FROM %s%s',
			$this->selectSql(),
			$this->database->quoteIdentifier($this->database->tableName($this->table)),
			$this->aliasSql()
		);

		if ($this->where !== []) {
			$sql .= ' WHERE ' . implode(' AND ', $this->where);
		}

		if ($this->orderBy !== []) {
			$sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
		}

		if ($this->limit !== null) {
			$sql .= ' LIMIT %d';

			if ($this->offset !== null) {
				$sql .= ' OFFSET %d';
			}
		}

		return $sql;
	}

	/**
	 * @return list<mixed>
	 */
	public function bindings(): array {
		$bindings = $this->bindings;

		if ($this->limit !== null) {
			$bindings[] = $this->limit;

			if ($this->offset !== null) {
				$bindings[] = $this->offset;
			}
		}

		return $bindings;
	}

	/**
	 * @throws DatabaseException        When table-name resolution or query preparation fails.
	 * @throws InvalidArgumentException When a selected column is invalid.
	 */
	public function toPreparedSql(): string {
		return $this->database->prepare($this->toSql(), ...$this->bindings());
	}

	/**
	 * @throws DatabaseException        When table-name resolution or query execution fails.
	 * @throws InvalidArgumentException When a selected column is invalid.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get(): array {
		return $this->toQuery()->get();
	}

	/**
	 * @throws DatabaseException        When table-name resolution or query execution fails.
	 * @throws InvalidArgumentException When a selected column is invalid.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		$query        = clone $this;
		$query->limit = 1;

		return $query->toQuery()->first();
	}

	private function selectSql(): string {
		if ($this->aggregate !== null) {
			return $this->aggregate;
		}

		return implode(', ', array_map(fn (string $column): string => $this->quoteColumn($column, true), $this->columns));
	}

	private function aliasSql(): string {
		if ($this->alias === null || $this->alias === '') {
			return '';
		}

		return ' AS ' . $this->database->quoteIdentifier($this->alias);
	}

	private function operator(string $operator): string {
		$operator = strtoupper(trim($operator));

		if (! in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE'], true)) {
			throw new InvalidArgumentException(sprintf('Unsupported query operator: %s.', $operator));
		}

		return $operator;
	}

	/**
	 * Quote each segment of a qualified column reference.
	 *
	 * For example, p.ID becomes `p`.`ID`. When wildcards are allowed,
	 * p.* becomes `p`.*.
	 *
	 * @throws InvalidArgumentException When the column contains an empty segment or a disallowed wildcard.
	 */
	private function quoteColumn(string $column, bool $allowWildcard = false): string {
		$segments = explode('.', $column);
		$last     = array_key_last($segments);
		$quoted   = [];

		foreach ($segments as $index => $segment) {
			if ($segment === '') {
				throw new InvalidArgumentException(sprintf('Invalid query column: %s.', $column));
			}

			if ($segment === '*') {
				if (! $allowWildcard || $index !== $last) {
					throw new InvalidArgumentException(sprintf('Invalid query column wildcard: %s.', $column));
				}

				$quoted[] = '*';
				continue;
			}

			$quoted[] = $this->database->quoteIdentifier($segment);
		}

		return implode('.', $quoted);
	}
}
