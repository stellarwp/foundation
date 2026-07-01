<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;

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

	public function __construct(
		private readonly Database $database,
		private readonly Table|string $table,
		private readonly ?string $alias = null
	) {
	}

	public function select(string ...$columns): self {
		$this->columns = $columns === [] ? ['*'] : array_values($columns);

		return $this;
	}

	public function where(string $column, string $operator, mixed $value): self {
		$this->where[]    = sprintf('%s %s %%s', $this->database->quoteIdentifier($column), $this->operator($operator));
		$this->bindings[] = $value;

		return $this;
	}

	public function orderBy(string $column, string $direction = 'ASC'): self {
		$direction = strtoupper($direction);

		if (! in_array($direction, ['ASC', 'DESC'], true)) {
			throw new InvalidArgumentException('Order direction must be ASC or DESC.');
		}

		$this->orderBy[] = sprintf('%s %s', $this->database->quoteIdentifier($column), $direction);

		return $this;
	}

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

	public function query(): Query {
		return new Query($this->database, $this->toSql(), $this->bindings());
	}

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

	public function toPreparedSql(): string {
		return $this->database->prepare($this->toSql(), ...$this->bindings());
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get(): array {
		return $this->queryWithLimitBindings()->get();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		return $this->queryWithLimitBindings()->first();
	}

	private function queryWithLimitBindings(): Query {
		return new Query($this->database, $this->toSql(), $this->bindings());
	}

	private function selectSql(): string {
		if ($this->columns === ['*']) {
			return '*';
		}

		return implode(', ', array_map($this->database->quoteIdentifier(...), $this->columns));
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
}
