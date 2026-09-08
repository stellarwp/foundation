<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

/**
 * Summary of migrations affected by a runner operation.
 */
final readonly class Result
{
	/**
	 * Capture the migrations affected or skipped by one operation.
	 *
	 * @param list<string> $ran
	 * @param list<string> $rolledBack
	 * @param list<string> $skipped
	 */
	public function __construct(
		public array $ran = [],
		public array $rolledBack = [],
		public array $skipped = []
	) {
	}

	/**
	 * Return the number of migrations applied or rolled back.
	 */
	public function count(): int {
		return count($this->ran) + count($this->rolledBack);
	}
}
