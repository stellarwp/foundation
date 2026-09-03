<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * Represents a prepared-query template and bindings that can be inspected before execution.
 *
 * The SQL is materialized for the active database scope when this object is created.
 * Rebuild the query after changing the active WordPress site.
 */
final readonly class Query
{
	/**
	 * Capture a SQL template and its bindings for later inspection or execution.
	 *
	 * @param list<mixed> $bindings
	 */
	public function __construct(
		private Database $database,
		private string $sql,
		private array $bindings = []
	) {
	}

	/**
	 * Return the SQL template before bindings are prepared.
	 */
	public function toSql(): string {
		return $this->sql;
	}

	/**
	 * Return the bindings in placeholder order.
	 *
	 * @return list<mixed>
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	/**
	 * Prepare the SQL template with its captured bindings.
	 *
	 * @throws QueryException When WordPress cannot prepare the statement.
	 */
	public function toPreparedSql(): string {
		return $this->database->prepare($this->sql, ...$this->bindings);
	}

	/**
	 * Execute the query and return every matching row.
	 *
	 * @throws QueryException When the query fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get(): array {
		return $this->database->rows($this->sql, ...$this->bindings);
	}

	/**
	 * Execute the query and return its first row when present.
	 *
	 * @throws QueryException When the query fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		return $this->database->row($this->sql, ...$this->bindings);
	}

	/**
	 * Execute the query and return the first column from its first row.
	 *
	 * @throws QueryException When the query fails.
	 */
	public function value(): mixed {
		return $this->database->value($this->sql, ...$this->bindings);
	}
}
