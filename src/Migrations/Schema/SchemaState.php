<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * A replayed or inspected schema with the timestamp facts Doctrine does not model.
 *
 * @internal Owned by one planning operation; copies keep both representations together.
 */
final class SchemaState
{
	/**
	 * Hold a schema snapshot and its supplemental timestamp facts.
	 *
	 * @param array<string, array{precision: int, on_update: bool}> $timestamps Physical table.column keys.
	 */
	public function __construct(
		public Schema $schema,
		public array $timestamps = [],
	) {
	}

	/**
	 * Isolate mutable Doctrine objects when computing another migration state.
	 */
	public function __clone(): void {
		$this->schema = clone $this->schema;
	}
}
