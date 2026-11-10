<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Table\Table;

/**
 * Mirrors the constructorless table shape emitted by the database generator.
 */
final readonly class GeneratedStyleTable extends Table
{
	public function unprefixedName(): string {
		return 'generated_style';
	}
}
