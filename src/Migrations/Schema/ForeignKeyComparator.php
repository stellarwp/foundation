<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;

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
	 * Retain live constraint names when a prefix change altered historical physical identities.
	 *
	 * Only previously declared relationships with Foundation-generated names may match by structure.
	 * Update private planning snapshots, leaving the live database and logical names unchanged.
	 *
	 * @throws IncompatibleSchema When ambiguous matches or changed actions prevent identifying ownership.
	 */
	public function alignForeignKeyNames(SchemaState $before, SchemaState $actual, SchemaState $after, string $id): void {
		$names = [];

		foreach ($before->schema->getTables() as $table) {
			$name = $table->getObjectName()->getUnqualifiedName()->getValue();

			if (! $actual->schema->hasTable($name)) {
				continue;
			}

			$names[strtolower($name)] = $this->historicalNames(
				$actual->schema->getTable($name),
				$before,
				$after->schema->hasTable($name) ? $after->schema->getTable($name)->getForeignKeys() : [],
				$id,
			);
		}

		$this->applyForeignKeyNames($before, $names);
		$this->applyForeignKeyNames($after, $names);
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

	/**
	 * Match missing historical names uniquely, reserving every exact identity first.
	 *
	 * @param array<string, ForeignKeyConstraint> $after
	 *
	 * @return array<string, non-empty-string> Historical name to live name.
	 */
	private function historicalNames(Table $actual, SchemaState $before, array $after, string $id): array {
		$table      = $actual->getObjectName()->getUnqualifiedName()->getValue();
		$historical = $before->schema->getTable($table)->getForeignKeys();
		$live       = $actual->getForeignKeys();
		$candidates = array_diff_key($live, $historical, $after);
		$names      = [];

		foreach (array_diff_key($historical, $live) as $name => $constraint) {
			$label   = $before->foreignKeyLabel($table, $name);
			$matches = $this->matchingNames($constraint, $after[$name] ?? null, $candidates, $label);

			if ($matches === []) {
				continue;
			}

			$matched = $matches[0];

			if (count($matches) !== 1 || in_array($matched, $names, true)) {
				throw IncompatibleSchema::forChange(
					$id,
					$table,
					'ambiguous foreign-key identity for ' . $label . ' among ' . implode(', ', $matches),
				);
			}

			$names[$name] = $matched;
		}

		return $names;
	}

	/**
	 * Find managed names matching the historical definition or its completed replacement.
	 *
	 * @param array<string, ForeignKeyConstraint> $candidates
	 *
	 * @throws IncompatibleSchema When changed actions prevent identifying a historical relationship.
	 *
	 * @return list<non-empty-string>
	 */
	private function matchingNames(ForeignKeyConstraint $before, ?ForeignKeyConstraint $after, array $candidates, string $label): array {
		$names     = [];
		$conflicts = [];

		foreach ($candidates as $candidate) {
			$name = $candidate->getObjectName()?->getIdentifier()->getValue();

			if ($name === null || preg_match('/^fk_[0-9a-f]{40}$/D', $name) !== 1) {
				continue;
			}

			// A replacement may have completed before its ledger write failed.
			if (! $this->diffForeignKey($before, $candidate) || ($after !== null && ! $this->diffForeignKey($after, $candidate))) {
				$names[] = $name;

				continue;
			}

			// Ignore actions only to detect uncertain ownership, never to accept a match.
			$expectedActions = $candidate->edit()
				->setOnUpdateAction($before->getOnUpdateAction())
				->setOnDeleteAction($before->getOnDeleteAction())
				->create();

			if (! $this->diffForeignKey($before, $expectedActions)) {
				$conflicts[] = $name;
			}
		}

		if ($names === [] && $conflicts !== []) {
			throw new IncompatibleSchema(sprintf(
				'Cannot identify historical foreign key %s: constraints %s have matching columns and references but different update/delete actions. Repair the schema or the ledger before retrying.',
				$label,
				implode(', ', $conflicts),
			));
		}

		return $names;
	}

	/**
	 * Apply physical aliases without changing definitions or implicit supporting indexes.
	 *
	 * @param array<string, array<string, non-empty-string>> $names
	 */
	private function applyForeignKeyNames(SchemaState $state, array $names): void {
		$tables = [];

		foreach ($state->schema->getTables() as $table) {
			$tableName = strtolower($table->getObjectName()->getUnqualifiedName()->getValue());
			$aliases   = $names[$tableName] ?? [];

			if ($aliases === []) {
				$tables[] = $table;

				continue;
			}

			$constraints = [];

			foreach ($table->getForeignKeys() as $name => $constraint) {
				$constraints[] = isset($aliases[$name]) ? $constraint->edit()->setUnquotedName($aliases[$name])->create() : $constraint;

				if (isset($aliases[$name], $state->foreignKeyNames[$tableName][$name])) {
					$state->foreignKeyNames[$tableName][strtolower($aliases[$name])] = $state->foreignKeyNames[$tableName][$name];
					unset($state->foreignKeyNames[$tableName][$name]);
				}
			}

			$indexes = array_diff_key($table->getIndexes(), [
				'primary' => true,
			]);
			$tables[] = $table->edit()
				->setIndexes(...array_values($indexes))
				->setForeignKeyConstraints(...$constraints)
				->create();
		}

		$state->schema = new Schema($tables);
	}
}
