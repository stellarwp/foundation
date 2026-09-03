<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid;

/**
 * Validates canonical uppercase ULID strings.
 */
final class UlidValidator
{
	private const string PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

	public function isValid(string $identifier): bool {
		return preg_match(self::PATTERN, $identifier) === 1;
	}
}
