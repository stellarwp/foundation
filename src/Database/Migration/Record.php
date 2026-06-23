<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;

/**
 * Persisted record of a migration that has run.
 */
final readonly class Record
{
	public function __construct(
		public int $id,
		public string $migration,
		public int $batch,
		public DateTimeImmutable $ranAt
	) {
	}
}
