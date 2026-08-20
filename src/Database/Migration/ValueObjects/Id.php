<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\ValueObjects;

use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;

/**
 * Validates the byte-exact identifier stored in the migration ledger.
 */
final readonly class Id
{
	public const int MAX_BYTES = 191;

	/**
	 * @throws InvalidMigrationId When the identifier is blank, padded, integer-like, or too long for the ledger.
	 */
	public function __construct(
		public string $value
	) {
		if ($this->value === '' || trim($this->value) !== $this->value) {
			throw new InvalidMigrationId('Migration IDs cannot be blank or contain surrounding whitespace.');
		}

		if (strlen($this->value) > self::MAX_BYTES) {
			throw new InvalidMigrationId(sprintf('Migration IDs cannot exceed %d bytes.', self::MAX_BYTES));
		}

		if (preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $this->value) === 1) {
			throw new InvalidMigrationId('Migration IDs cannot be integer-like strings.');
		}
	}
}
