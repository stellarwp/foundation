<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid\Contracts;

use OutOfRangeException;
use Random\RandomException;
use RuntimeException;
use StellarWP\Foundation\Identifier\Contracts\IdentifierGenerator;

/**
 * Generates canonical ULID identifier strings.
 */
interface UlidGenerator extends IdentifierGenerator
{
	/**
	 * @throws OutOfRangeException When the current timestamp is outside the ULID range.
	 * @throws RandomException     When secure random bytes cannot be generated.
	 * @throws RuntimeException    When the entropy source returns an invalid byte count.
	 */
	public function generate(): string;
}
