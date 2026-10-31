<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\ValueObjects;

use StellarWP\Foundation\Migrations\Schema\SchemaState;

/**
 * Authorized SQL and the resulting snapshot for one migration step.
 *
 * @internal
 */
final readonly class SchemaPlan
{
	/**
	 * Retain the planned schema for execution or a subsequent preview step.
	 *
	 * @param list<string> $sql
	 * @param list<string> $tableNames
	 */
	public function __construct(
		public SchemaState $schema,
		public array $sql,
		public array $tableNames,
	) {
	}
}
