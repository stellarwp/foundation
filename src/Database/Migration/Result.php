<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

/**
 * Summary of migrations affected by a runner operation.
 */
final readonly class Result
{
	/**
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

	public function count(): int {
		return count($this->ran) + count($this->rolledBack);
	}
}
