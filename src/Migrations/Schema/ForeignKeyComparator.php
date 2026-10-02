<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;

/**
 * Preserve foreign-key identity while retaining Doctrine's platform comparison.
 *
 * Doctrine matches equivalent foreign keys across different names. Migration ownership
 * requires matching their names first so later removal and replacement remain reliable.
 *
 * @internal
 */
final class ForeignKeyComparator extends Comparator
{
	/**
	 * Receive the active platform, its native comparator, and their shared comparison configuration.
	 */
	public function __construct(
		AbstractPlatform $platform,
		private readonly Comparator $comparator,
		ComparatorConfig $config,
	) {
		parent::__construct($platform, $config);
	}

	/**
	 * Compare columns and indexes natively, then match foreign keys by identity.
	 *
	 * @throws Exception
	 */
	public function compareTables(Table $oldTable, Table $newTable): TableDiff {
		$diff           = $this->comparator->compareTables($oldTable, $newTable);
		$oldForeignKeys = $oldTable->getForeignKeys();
		$newForeignKeys = $newTable->getForeignKeys();

		if ($oldForeignKeys === [] && $newForeignKeys === []) {
			return $diff;
		}

		$added   = [];
		$dropped = [];

		foreach ($oldForeignKeys as $name => $foreignKey) {
			if (! isset($newForeignKeys[$name])) {
				$dropped[] = $foreignKey;

				continue;
			}

			if ($this->diffForeignKey($foreignKey, $newForeignKeys[$name])) {
				$dropped[] = $foreignKey;
				$added[]   = $newForeignKeys[$name];
			}
		}

		foreach ($newForeignKeys as $name => $foreignKey) {
			if (! isset($oldForeignKeys[$name])) {
				$added[] = $foreignKey;
			}
		}

		// DBAL has no diff editor. Keep this version-specific reconstruction at the comparison boundary.
		return new TableDiff(
			oldTable: $diff->getOldTable(),
			addedColumns: $diff->getAddedColumns(),
			changedColumns: $diff->getChangedColumns(),
			droppedColumns: $diff->getDroppedColumns(),
			addedIndexes: $diff->getAddedIndexes(),
			droppedIndexes: $diff->getDroppedIndexes(),
			renamedIndexes: $diff->getRenamedIndexes(),
			addedForeignKeys: $added,
			droppedForeignKeys: $dropped,
		);
	}
}
