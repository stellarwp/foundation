<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Exceptions;

use Throwable;

/**
 * Reports a failed database query with SQL context that can be logged or inspected.
 */
final class QueryException extends DatabaseException
{
	/**
	 * Capture a failed query and the available database diagnostic context.
	 *
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

	/**
	 * Return the SQL template or operation context associated with the failure.
	 */
	public function sql(): string {
		return $this->sql;
	}

	/**
	 * Return the bindings supplied for the failed SQL template.
	 *
	 * @return list<mixed>
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	/**
	 * Return the native database error when WordPress provided one.
	 */
	public function databaseError(): ?string {
		return $this->databaseError;
	}
}
