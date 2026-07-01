<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Contracts;

/**
 * Generates string identifiers for Foundation packages and consuming applications.
 */
interface IdentifierGenerator
{
	public function generate(): string;
}
