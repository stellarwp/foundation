<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Renames;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;

/**
 * Rename a column natively without restating its definition.
 *
 * @internal
 */
final readonly class NativeColumnRename implements ColumnRename
{
	/**
	 * Receive the connection for platform identifier quoting.
	 */
	public function __construct(
		private Connection $db,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function inspect(Table $table): Table {
		return $table;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sql(Table $table, string $from, string $to): array {
		$platform = $this->db->getDatabasePlatform();
		$name     = $platform->quoteSingleIdentifier($table->getObjectName()->getUnqualifiedName()->getValue());
		$from     = $platform->quoteSingleIdentifier($from);
		$to       = $platform->quoteSingleIdentifier($to);

		return [
			"ALTER TABLE {$name}
				RENAME COLUMN {$from} TO {$to}",
		];
	}
}
