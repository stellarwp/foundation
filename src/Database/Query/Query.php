<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use StellarWP\Foundation\Database\Contracts\Database;

/**
 * Represents a prepared-query template and bindings that can be inspected before execution.
 */
final readonly class Query
{
	/**
	 * @param list<mixed> $bindings
	 */
	public function __construct(
		private Database $database,
		private string $sql,
		private array $bindings = []
	) {
	}

	public function toSql(): string {
		return $this->sql;
	}

	/**
	 * @return list<mixed>
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	public function toPreparedSql(): string {
		return $this->database->prepare($this->sql, ...$this->bindings);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get(): array {
		return $this->database->rows($this->sql, ...$this->bindings);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		return $this->database->row($this->sql, ...$this->bindings);
	}

	public function value(): mixed {
		return $this->database->value($this->sql, ...$this->bindings);
	}
}
