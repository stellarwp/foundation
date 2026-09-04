<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Schema;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Blueprint;
use StellarWP\Foundation\Database\Table\Index;

/**
 * Executes the explicit operations in a table alteration blueprint.
 *
 * @internal Use the public Schema contract for application schema operations.
 */
final readonly class Editor
{
	/**
	 * Create an editor backed by the database and schema verifier.
	 */
	public function __construct(
		private Database $database,
		private Reconciler $reconciler
	) {
	}

	/**
	 * Apply one retry-safe ALTER TABLE statement and verify its requested state.
	 *
	 * Existing additions and absent removals are treated as completed work so a
	 * migration can resume after its DDL succeeded but its ledger write failed.
	 *
	 * @throws DatabaseException        When the table is missing or an alteration cannot be applied or verified.
	 * @throws InvalidArgumentException When the alteration blueprint is invalid.
	 */
	public function alter(Blueprint $blueprint): void {
		$blueprint->assertValidForAlter();
		$table = $blueprint->table();

		if (! $this->database->tableExists($table)) {
			throw new DatabaseException(sprintf(
				'Cannot alter missing table %s.',
				$this->database->tableName($table)
			));
		}

		$this->reconciler->verifyTable($table);

		$clauses = [
			...$this->indexRemovalClauses($blueprint),
			...$this->columnRemovalClauses($blueprint),
			...$this->columnAdditionClauses($blueprint),
			...$this->columnChangeClauses($blueprint),
			...$this->indexAdditionClauses($blueprint),
		];

		if ($clauses !== []) {
			$this->database->execute(sprintf(
				'ALTER TABLE %s %s',
				$this->database->quoteIdentifier($this->database->tableName($table)),
				implode(', ', $clauses)
			));
		}

		$this->reconciler->verify($blueprint);
		$this->verifyRemovedColumns($blueprint);
		$this->verifyRemovedIndexes($blueprint);
	}

	/**
	 * Build clauses for secondary indexes that still exist.
	 *
	 * @return list<string>
	 */
	private function indexRemovalClauses(Blueprint $blueprint): array {
		$clauses = [];

		foreach ($blueprint->droppedIndexes() as $index) {
			if ($this->database->indexExists($blueprint->table(), $index)) {
				$clauses[] = 'DROP INDEX ' . $this->database->quoteIdentifier($index);
			}
		}

		return $clauses;
	}

	/**
	 * Build clauses for columns that still exist after a previous attempt.
	 *
	 * @return list<string>
	 */
	private function columnRemovalClauses(Blueprint $blueprint): array {
		$clauses = [];

		foreach ($blueprint->droppedColumns() as $column) {
			if ($this->database->columnExists($blueprint->table(), $column)) {
				$clauses[] = 'DROP COLUMN ' . $this->database->quoteIdentifier($column);
			}
		}

		return $clauses;
	}

	/**
	 * Build clauses for columns not already present after a previous attempt.
	 *
	 * @return list<string>
	 */
	private function columnAdditionClauses(Blueprint $blueprint): array {
		$clauses = [];

		foreach ($blueprint->addedColumns() as $column) {
			if ($this->database->columnExists($blueprint->table(), $column->name)) {
				$this->reconciler->verifyColumn($blueprint->table(), $column);
				continue;
			}

			$clauses[] = 'ADD COLUMN ' . $column->sql();
		}

		return $clauses;
	}

	/**
	 * Build clauses for columns explicitly marked for modification.
	 *
	 * @throws DatabaseException When a column selected for modification is missing.
	 *
	 * @return list<string>
	 */
	private function columnChangeClauses(Blueprint $blueprint): array {
		$clauses = [];

		foreach ($blueprint->changedColumns() as $column) {
			if (! $this->database->columnExists($blueprint->table(), $column->name)) {
				throw new DatabaseException(sprintf(
					'Cannot change missing column %s on %s.',
					$column->name,
					$this->database->tableName($blueprint->table())
				));
			}

			$requestedState = Blueprint::for($blueprint->table());
			$requestedState->column($column);

			if ($this->reconciler->matches($requestedState)) {
				continue;
			}

			$clauses[] = 'MODIFY COLUMN ' . $column->sql();
		}

		return $clauses;
	}

	/**
	 * Build clauses for indexes not already present after a previous attempt.
	 *
	 * @return list<string>
	 */
	private function indexAdditionClauses(Blueprint $blueprint): array {
		$clauses = [];

		foreach ($blueprint->indexes() as $index) {
			if ($this->replacesIndex($blueprint, $index)) {
				$clauses[] = 'ADD ' . $index->sql();
				continue;
			}

			if ($this->database->indexExists($blueprint->table(), $index->name)) {
				$this->reconciler->verifyIndex($blueprint->table(), $index);
				continue;
			}

			$clauses[] = 'ADD ' . $index->sql();
		}

		return $clauses;
	}

	/**
	 * Determine whether the alteration replaces an index under the same name.
	 */
	private function replacesIndex(Blueprint $blueprint, Index $index): bool {
		foreach ($blueprint->droppedIndexes() as $droppedIndex) {
			if (strcasecmp($droppedIndex, $index->name) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Confirm columns requested for removal are absent after alteration.
	 *
	 * @throws DatabaseException When a removed column remains in the physical table.
	 */
	private function verifyRemovedColumns(Blueprint $blueprint): void {
		foreach ($blueprint->droppedColumns() as $column) {
			if ($this->database->columnExists($blueprint->table(), $column)) {
				throw new DatabaseException(sprintf(
					'Database schema alteration did not remove column %s from %s.',
					$column,
					$this->database->tableName($blueprint->table())
				));
			}
		}
	}

	/**
	 * Confirm indexes requested only for removal are absent after alteration.
	 *
	 * @throws DatabaseException When a removed index remains in the physical table.
	 */
	private function verifyRemovedIndexes(Blueprint $blueprint): void {
		foreach ($blueprint->droppedIndexes() as $index) {
			if ($this->blueprintAddsIndex($blueprint, $index)) {
				continue;
			}

			if ($this->database->indexExists($blueprint->table(), $index)) {
				throw new DatabaseException(sprintf(
					'Database schema alteration did not remove index %s from %s.',
					$index,
					$this->database->tableName($blueprint->table())
				));
			}
		}
	}

	/**
	 * Determine whether the blueprint declares an index under the supplied name.
	 */
	private function blueprintAddsIndex(Blueprint $blueprint, string $name): bool {
		foreach ($blueprint->indexes() as $index) {
			if (strcasecmp($index->name, $name) === 0) {
				return true;
			}
		}

		return false;
	}
}
