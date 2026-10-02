<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\ValueObjects;

/**
 * Status row for one known or recorded migration identity.
 */
final readonly class MigrationStatus
{
	/**
	 * Describe a registered or recorded migration.
	 */
	public function __construct(
		public string $id,
		public ?string $migration,
		public string $description,
		public ?string $appliedAt,
	) {
	}

	/**
	 * Report the state shown to operators.
	 */
	public function state(): string {
		if ($this->migration === null) {
			return 'missing';
		}

		return $this->appliedAt === null ? 'pending' : 'applied';
	}
}
