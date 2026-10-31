<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use StellarWP\Foundation\Migrations\Schema\ValueObjects\Rename;

/**
 * A schema snapshot that keeps relationships and supplemental timestamp facts together.
 *
 * @internal Owned by one planning operation; copies keep both representations together.
 */
final class SchemaState
{
	/**
	 * @var list<Rename> Name changes declared by the migration being planned.
	 */
	public array $renames = [];

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
	 * Rename an object and carry its references and timestamp facts with it.
	 */
	public function rename(Rename $rename): void {
		$tables = [];

		foreach ($this->schema->getTables() as $table) {
			$tables[] = $this->renamedTable($table, $rename);
		}

		$this->schema = new Schema($tables);
		$from         = strtolower($rename->table === null ? $rename->from : $rename->table . '.' . $rename->from);
		$to           = strtolower($rename->table === null ? $rename->to : $rename->table . '.' . $rename->to);

		foreach ($this->timestamps as $key => $attributes) {
			if (($rename->table === null && str_starts_with($key, $from . '.')) || $key === $from) {
				unset($this->timestamps[$key]);
				$this->timestamps[$to . substr($key, strlen($from))] = $attributes;
			}
		}

		$this->renames[] = $rename;
	}

	private function renamedTable(Table $table, Rename $rename): Table {
		$name   = $table->getObjectName()->getUnqualifiedName()->getValue();
		$editor = $this->edit($table);

		if ($rename->table === null && strcasecmp($name, $rename->from) === 0) {
			$editor->setUnquotedName($rename->to);
		}

		if ($rename->table !== null && strcasecmp($name, $rename->table) === 0) {
			$editor->renameColumnByUnquotedName($rename->from, $rename->to);
		}

		// The editor updates local index and key columns; update referenced columns afterwards.
		$table = $editor->create();

		return $this->edit($table)->setForeignKeyConstraints(...$this->renamedReferences($table, $rename))->create();
	}

	/**
	 * @return list<ForeignKeyConstraint>
	 */
	private function renamedReferences(Table $table, Rename $rename): array {
		$constraints = [];

		foreach ($table->getForeignKeys() as $key) {
			$referenced = $key->getReferencedTableName()->getUnqualifiedName()->getValue();

			if (strcasecmp($referenced, $rename->table ?? $rename->from) !== 0) {
				$constraints[] = $key;

				continue;
			}

			$constraint = $key->edit();

			if ($rename->table === null) {
				$constraint->setUnquotedReferencedTableName($rename->to);
			} else {
				$columns = array_map(
					static fn ($column) => strcasecmp($column->getIdentifier()->getValue(), $rename->from) === 0 ? $rename->to : $column->getIdentifier()->getValue(),
					$key->getReferencedColumnNames(),
				);
				$constraint->setUnquotedReferencedColumnNames(...$columns);
			}

			$constraints[] = $constraint->create();
		}

		return $constraints;
	}

	/**
	 * Keep implicit supporting indexes, which Doctrine's editor omits by default.
	 */
	private function edit(Table $table): TableEditor {
		$indexes = array_diff_key($table->getIndexes(), [
			'primary' => true,
		]);

		return $table->edit()->setIndexes(...array_values($indexes));
	}

	/**
	 * Isolate mutable Doctrine objects when computing another migration state.
	 */
	public function __clone(): void {
		$this->schema = clone $this->schema;
	}
}
