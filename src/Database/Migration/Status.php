<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use DateTimeImmutable;

/**
 * Read model describing whether a migration has been applied.
 */
final readonly class Status
{
	public function __construct(
		public string $migration,
		public bool $ran,
		public ?int $batch = null,
		public ?DateTimeImmutable $ranAt = null
	) {
	}

	public static function pending(string $migration): self {
		return new self($migration, false);
	}

	public static function fromRecord(Record $record): self {
		return new self(
			migration: $record->migration,
			ran: true,
			batch: $record->batch,
			ranAt: $record->ranAt
		);
	}
}
