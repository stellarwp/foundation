<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

use DateTimeImmutable;

/**
 * Read model describing the relationship between a configured migration and the migration ledger.
 */
final readonly class Status
{
	private const string PENDING     = 'pending';
	private const string APPLIED     = 'applied';
	private const string UNAVAILABLE = 'unavailable';

	/**
	 * @param self::PENDING|self::APPLIED|self::UNAVAILABLE $state
	 */
	private function __construct(
		public string $migration,
		private string $state,
		public ?int $batch = null,
		public ?DateTimeImmutable $ranAt = null
	) {
	}

	/**
	 * Describe a configured migration that has not been recorded.
	 */
	public static function pending(string $migration): self {
		return new self($migration, self::PENDING);
	}

	/**
	 * Describe a configured migration and its recorded ledger state.
	 */
	public static function applied(Record $record): self {
		return new self(
			migration: $record->migration,
			state: self::APPLIED,
			batch: $record->batch,
			ranAt: $record->ranAt
		);
	}

	/**
	 * Describe a ledger record whose migration implementation is unavailable.
	 */
	public static function unavailable(Record $record): self {
		return new self(
			migration: $record->migration,
			state: self::UNAVAILABLE,
			batch: $record->batch,
			ranAt: $record->ranAt
		);
	}

	/**
	 * Determine whether this configured migration has not been applied.
	 */
	public function isPending(): bool {
		return $this->state === self::PENDING;
	}

	/**
	 * Determine whether this configured migration is recorded as applied.
	 */
	public function isApplied(): bool {
		return $this->state === self::APPLIED;
	}

	/**
	 * Determine whether the recorded migration implementation is unavailable.
	 */
	public function isUnavailable(): bool {
		return $this->state === self::UNAVAILABLE;
	}

	/**
	 * Return the state name for presentation.
	 *
	 * @return self::PENDING|self::APPLIED|self::UNAVAILABLE
	 */
	public function state(): string {
		return $this->state;
	}
}
