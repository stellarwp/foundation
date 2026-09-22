<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

/**
 * One planned or executed migration step and the SQL it produced.
 */
final readonly class Step
{
	/**
	 * Describe one planned or completed migration step.
	 *
	 * @param list<string> $sql DDL statements; empty when a prior attempt already completed the change.
	 */
	public function __construct(
		public string $id,
		public string $migration,
		public bool $reverse,
		public array $sql,
		public bool $hasDataStep,
	) {
	}
}
