<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

use RuntimeException;

/**
 * Represents a validated PHP namespace used by generated source.
 */
final readonly class PhpNamespace
{
	/**
	 * Validate a PHP namespace without changing its declared segments.
	 *
	 * @throws RuntimeException When the namespace is invalid.
	 */
	public function __construct(public string $value) {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $this->value) !== 1) {
			throw new RuntimeException(sprintf('Namespace "%s" is not a valid PHP namespace.', $this->value));
		}
	}
}
