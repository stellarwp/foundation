<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use Throwable;

/**
 * Reports a failed database query with SQL context that can be logged or inspected.
 */
final class QueryException extends DatabaseException
{
	/**
	 * @param list<mixed> $bindings
	 */
	public function __construct(
		string $message,
		private readonly string $sql,
		private readonly array $bindings = [],
		private readonly ?string $databaseError = null,
		?Throwable $previous = null
	) {
		parent::__construct($message, 0, $previous);
	}

	public function sql(): string {
		return $this->sql;
	}

	/**
	 * @return list<mixed>
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	public function databaseError(): ?string {
		return $this->databaseError;
	}
}
