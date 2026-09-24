<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Migrations\Exceptions\IncompatibleSchema;
use StellarWP\Foundation\Migrations\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\MigrationCollection;
use StellarWP\Foundation\Migrations\Schema\Factories\MigrationComparatorFactory;
use StellarWP\Foundation\Migrations\Schema\ValueObjects\SchemaPlan;

/**
 * Compute desired schema states from migration declarations and compare them with the live schema.
 *
 * @internal
 */
final readonly class SchemaPlanner
{
	/**
	 * Receive the shared connection, naming policy, and historical declarations.
	 *
	 * @param array<string, mixed> $tableOptions Defaults such as charset/collation applied to created tables.
	 */
	public function __construct(
		private Connection $db,
		private TableNameResolver $names,
		private MigrationCollection $migrations,
		private MigrationComparatorFactory $comparators,
		private RenamePlanner $renames,
		private SchemaInspector $inspector,
		private array $tableOptions = [],
	) {
	}

	/**
	 * Authorize only the remaining changes declared by one historical migration.
	 *
	 * @param list<string> $applied
	 * @param list<string> $simulatedNames Tables already decided by an earlier preview step.
	 *
	 * @throws IncompatibleSchema When live drift would require undeclared changes.
	 * @throws \Throwable         When a declaration or schema inspection fails.
	 */
	public function plan(array $applied, string $id, bool $reverse, ?SchemaState $simulated = null, array $simulatedNames = []): SchemaPlan {
		$before = $this->replay($applied);
		$after  = $reverse ? $this->reverse($before, $id) : $this->replay([
			...$applied,
			$id,
		], $id);
		$comparator = $this->comparators->create();
		$names      = $this->tableNames($before, $after);

		foreach ($after->renames as $rename) {
			if ($rename->table === null) {
				$names[] = $rename->from;
				$names[] = $rename->to;
			}
		}

		$names     = array_values(array_unique($names));
		$actual    = $this->inspector->inspect($names, $simulated, $simulatedNames);
		$renameSql = $this->renames->plan($before, $actual, $after, $id);
		$comparator->alignForeignKeyNames($before, $actual, $after, $id);
		$expected  = $comparator->compareSchemas($before->schema, $after->schema);
		$desired   = $this->preserveUndeclared($actual, $before, $after);
		$remaining = $comparator->compareSchemas($actual->schema, $desired->schema);
		$this->assertContained($remaining, $expected, $before, $after, $id);
		$sql = array_merge(
			$renameSql,
			$this->sql($remaining),
			$this->timestampSql($actual, $desired, $remaining, $this->timestampChanges($before, $after), $id),
			$this->commentSql($actual, $before, $after, $id),
		);

		return new SchemaPlan($desired, $sql, $names);
	}

	/**
	 * Replay the given IDs' up() declarations in byte order on an empty schema. Pure PHP; no queries.
	 *
	 * @param list<string> $ids
	 */
	private function replay(array $ids, ?string $renamedBy = null): SchemaState {
		$schema  = new SchemaState(new Schema());
		$renames = [];
		sort($ids, SORT_STRING);

		foreach ($ids as $id) {
			$schema->renames = [];
			$blueprint       = new Blueprint($schema, $this->names, $this->tableOptions);
			$this->migrations->get($id)->up($blueprint);
			$this->apply($blueprint, $id);

			if ($id === $renamedBy) {
				$renames = $schema->renames;
			}
		}

		$schema->renames = $renames;

		return $schema;
	}

	private function apply(Blueprint $blueprint, string $id): void {
		try {
			$blueprint->apply();
		} catch (SchemaException $failure) {
			throw new MigrationInterrupted(sprintf(
				'Migration %s cannot be applied to the schema that recorded history produces (%s). Check the declaration and migration order, or repair the ledger.',
				$id,
				$failure->getMessage(),
			), 0, $failure);
		}
	}

	/**
	 * Apply one migration's down() to a copy of the schema it was applied on.
	 *
	 * @throws MigrationInterrupted When the inverse cannot be applied to recorded history.
	 */
	private function reverse(SchemaState $before, string $id): SchemaState {
		$after     = clone $before;
		$blueprint = new Blueprint($after, $this->names, $this->tableOptions);

		try {
			$this->migrations->get($id)->down($blueprint);
		} catch (IrreversibleMigration $failure) {
			throw new IrreversibleMigration(sprintf('Migration "%s" cannot be reversed: %s', $id, $failure->getMessage()), 0, $failure);
		}

		$this->apply($blueprint, $id);

		return $after;
	}

	/**
	 * Return a copy of the desired schema that keeps columns and indexes the history never declared.
	 *
	 * Unrelated additions made outside migrations are preserved rather than dropped.
	 */
	private function preserveUndeclared(SchemaState $actual, SchemaState $before, SchemaState $after): SchemaState {
		$preserved = clone $after;
		$tables    = [];

		foreach ($preserved->schema->getTables() as $target) {
			$name = $target->getObjectName()->toString();

			if (! $actual->schema->hasTable($name)) {
				$tables[] = $target;
				continue;
			}

			$actualTable = $actual->schema->getTable($name);
			$beforeTable = $before->schema->hasTable($name) ? $before->schema->getTable($name) : null;
			$editor      = $target->edit();

			foreach ($actualTable->getColumns() as $column) {
				$columnName = $column->getObjectName()->toString();
				$definition = $column->getColumnDefinition();

				if ($target->hasColumn($columnName)) {
					// Retain the live SQL definition in preview snapshots when this step leaves it unchanged.
					if (($beforeTable?->hasColumn($columnName) ?? false)
						&& $beforeTable->getColumn($columnName)->toArray() == $target->getColumn($columnName)->toArray()
						&& $definition !== null && $definition !== '') {
						$editor->modifyColumn($column->getObjectName(), static function (ColumnEditor $targetColumn) use ($definition): void {
							$targetColumn->setColumnDefinition($definition);
						});
					}

					continue;
				}

				if ($beforeTable?->hasColumn($columnName) ?? false) {
					continue;
				}
				$editor->addColumn($column);
				$key = strtolower($name . '.' . $columnName);

				if (isset($actual->timestamps[$key])) {
					$preserved->timestamps[$key] = $actual->timestamps[$key];
				}
			}

			$primary = $actualTable->getPrimaryKeyConstraint()?->getObjectName()?->toString() ?? 'primary';

			foreach ($actualTable->getIndexes() as $index) {
				$indexName = $index->getObjectName()->toString();

				if (strcasecmp($indexName, $primary) === 0 || $target->hasIndex($indexName) || ($beforeTable?->hasIndex($indexName) ?? false)) {
					continue;
				}
				$editor->addIndex($index);
			}

			foreach ($actualTable->getForeignKeys() as $foreignKey) {
				$key = $foreignKey->getObjectName()?->getIdentifier()->getValue();

				if ($key !== null && ($target->hasForeignKey($key) || ($beforeTable?->hasForeignKey($key) ?? false))) {
					continue;
				}
				$editor->addForeignKeyConstraint($foreignKey);
			}

			$tables[] = $editor->create();
		}

		$preserved->schema = new Schema($tables);

		return $preserved;
	}

	/**
	 * Authorize SQL for timestamp attributes DBAL cannot compare.
	 *
	 * @param list<string> $declaredChanges Lower-cased "table.column" keys.
	 *
	 * @return list<string> SQL statements for attributes DBAL does not compare.
	 */
	private function timestampSql(SchemaState $actual, SchemaState $desired, SchemaDiff $remaining, array $declaredChanges, string $id): array {
		$platform = $this->db->getDatabasePlatform();
		$sql      = [];
		$covered  = $this->columnsRewrittenBy($remaining);

		foreach ($desired->schema->getTables() as $table) {
			if (! $actual->schema->hasTable($table->getObjectName()->toString())) {
				continue; // CREATE TABLE already carries the full column definitions.
			}

			$actualTable = $actual->schema->getTable($table->getObjectName()->toString());

			foreach ($table->getColumns() as $column) {
				$key = strtolower($table->getObjectName()->toString() . '.' . $column->getObjectName()->toString());

				if (in_array($key, $covered, true)) {
					continue; // Doctrine's CHANGE/ADD already applies the complete declaration.
				}

				if (! $actualTable->hasColumn($column->getObjectName()->toString()) || ! isset($desired->timestamps[$key]) || $desired->timestamps[$key] === ($actual->timestamps[$key] ?? ['precision' => 0, 'on_update' => false])) {
					continue;
				}

				$this->require(in_array($key, $declaredChanges, true), $id, $table->getObjectName()->toString(), 'column ' . $column->getObjectName()->toString() . ' timestamp precision or ON UPDATE differs from its declaration');
				$quotedTable = $platform->quoteSingleIdentifier($table->getObjectName()->toString());
				$declaration = $platform->getColumnDeclarationSQL($platform->quoteSingleIdentifier($column->getObjectName()->toString()), $column->toArray());
				$sql[]       = "ALTER TABLE {$quotedTable}
					MODIFY {$declaration}";
			}
		}

		return $sql;
	}

	/**
	 * Compare table comments, which DBAL does not include in its table differences.
	 *
	 * @return list<string>
	 */
	private function commentSql(SchemaState $actual, SchemaState $before, SchemaState $after, string $id): array {
		$sql      = [];
		$platform = $this->db->getDatabasePlatform();

		foreach ($after->schema->getTables() as $table) {
			$name = $table->getObjectName()->toString();

			if (! $actual->schema->hasTable($name)) {
				continue;
			}
			$comment = $table->getComment() ?? '';

			if (($actual->schema->getTable($name)->getComment() ?? '') === $comment) {
				continue;
			}
			$previous = $before->schema->hasTable($name) ? ($before->schema->getTable($name)->getComment() ?? '') : $comment;
			$this->require($previous !== $comment, $id, $name, 'table comment differs from its declaration');
			$quotedTable   = $platform->quoteSingleIdentifier($name);
			$quotedComment = $platform->quoteStringLiteral($comment);
			$sql[]         = "ALTER TABLE {$quotedTable}
				COMMENT = {$quotedComment}";
		}

		return $sql;
	}

	/**
	 * Columns whose complete declaration Doctrine's alteration SQL already emits.
	 *
	 * @return list<string> Lower-cased "table.column" keys.
	 */
	private function columnsRewrittenBy(SchemaDiff $diff): array {
		$keys = [];

		foreach ($diff->getAlteredTables() as $tableDiff) {
			$table = $this->tableName($tableDiff);
			foreach ($tableDiff->getAddedColumns() as $column) {
				$keys[] = strtolower($table . '.' . $column->getObjectName()->toString());
			}
			foreach ($tableDiff->getChangedColumns() as $columnDiff) {
				$keys[] = strtolower($table . '.' . $columnDiff->getOldColumn()->getObjectName()->toString());
			}
		}

		return $keys;
	}

	/**
	 * Timestamp columns whose declared attributes change between two desired states.
	 *
	 * @return list<string> Lower-cased "table.column" keys.
	 */
	private function timestampChanges(SchemaState $before, SchemaState $after): array {
		$changes = [];

		foreach ($after->timestamps as $key => $attributes) {
			if (($before->timestamps[$key] ?? null) !== $attributes) {
				$changes[] = $key;
			}
		}

		return $changes;
	}

	/**
	 * Generate platform DDL for the supplied differences.
	 *
	 * @return list<string>
	 */
	private function sql(SchemaDiff $diff): array {
		return $this->db->getDatabasePlatform()->getAlterSchemaSQL($diff);
	}

	/**
	 * Names of every table present in either schema.
	 *
	 * @return list<string>
	 */
	private function tableNames(SchemaState ...$schemas): array {
		$names = [];

		foreach ($schemas as $schema) {
			foreach ($schema->schema->getTables() as $table) {
				$names[] = $table->getObjectName()->toString();
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * Fail when the remaining work contains anything the migration did not declare.
	 *
	 * Remaining work is the difference between the live schema and the desired state; expected work
	 * is the difference the migration itself declares. Remaining must be a subset of expected, except
	 * that a table the migration creates may already exist with a subset of its declared columns.
	 *
	 * @throws IncompatibleSchema
	 */
	private function assertContained(SchemaDiff $remaining, SchemaDiff $expected, SchemaState $before, SchemaState $after, string $id): void {
		$expectedCreated = $this->names($expected->getCreatedTables());
		$expectedDropped = $this->names($expected->getDroppedTables());
		$expectedAltered = [];

		foreach ($expected->getAlteredTables() as $diff) {
			$expectedAltered[strtolower($this->tableName($diff))] = $diff;
		}

		foreach ($remaining->getCreatedTables() as $table) {
			$this->require(in_array(strtolower($table->getObjectName()->toString()), $expectedCreated, true), $id, $table->getObjectName()->toString(), 'table would be created');
		}

		foreach ($remaining->getDroppedTables() as $table) {
			$this->require(in_array(strtolower($table->getObjectName()->toString()), $expectedDropped, true), $id, $table->getObjectName()->toString(), 'table would be dropped');
		}

		foreach ($remaining->getAlteredTables() as $diff) {
			$name = $this->tableName($diff);
			$key  = strtolower($name);

			if (isset($expectedAltered[$key])) {
				$this->assertAlterationContained($diff, $expectedAltered[$key], $before, $after, $id);

				continue;
			}

			if (in_array($key, $expectedCreated, true)) {
				// The table already exists; only missing declared columns, indexes, and constraints may be added.
				$this->require($diff->getChangedColumns()        === [] && $diff->getDroppedColumns() === []
					&& $diff->getDroppedIndexes()                   === [] && $diff->getRenamedIndexes() === []
					&& $diff->getDroppedForeignKeyConstraintNames() === [], $id, $name, 'existing table differs from the declared initial definition: ' . $this->describe($diff, $before, $after));

				continue;
			}

			$this->require(false, $id, $name, $this->describe($diff, $before, $after));
		}
	}

	private function assertAlterationContained(TableDiff $remaining, TableDiff $expected, SchemaState $before, SchemaState $after, string $id): void {
		$table    = $this->tableName($remaining);
		$added    = $this->names($expected->getAddedColumns());
		$modified = array_map(static fn ($diff): string => strtolower($diff->getOldColumn()->getObjectName()->toString()), $expected->getChangedColumns());
		$dropped  = $this->names($expected->getDroppedColumns());
		$indexes  = [
			'added'   => $this->names($expected->getAddedIndexes()),
			'dropped' => $this->names($expected->getDroppedIndexes()),
		];
		$addedForeignKeys   = array_map(static fn ($key): string => strtolower($key->getObjectName()?->getIdentifier()->getValue() ?? ''), $expected->getAddedForeignKeys());
		$droppedForeignKeys = array_map(static fn ($name): string => strtolower($name->getIdentifier()->getValue()), $expected->getDroppedForeignKeyConstraintNames());

		foreach ($remaining->getAddedColumns() as $column) {
			$this->require(in_array(strtolower($column->getObjectName()->toString()), $added, true), $id, $table, 'column ' . $column->getObjectName()->toString() . ' would be added');
		}

		foreach ($remaining->getChangedColumns() as $diff) {
			$name = $diff->getOldColumn()->getObjectName()->toString();
			$this->require(! $diff->hasNameChanged() && in_array(strtolower($name), $modified, true), $id, $table, 'column ' . $name . ' differs from its declaration');
		}

		foreach ($remaining->getDroppedColumns() as $column) {
			$this->require(in_array(strtolower($column->getObjectName()->toString()), $dropped, true), $id, $table, 'column ' . $column->getObjectName()->toString() . ' would be dropped');
		}

		$this->require($remaining->getRenamedIndexes() === [], $id, $table, 'a rename would be required');

		foreach ($remaining->getAddedForeignKeys() as $foreignKey) {
			$name = $foreignKey->getObjectName()?->getIdentifier()->getValue() ?? '';
			$this->require(in_array(strtolower($name), $addedForeignKeys, true), $id, $table, 'foreign key ' . $after->foreignKeyLabel($table, $name) . ' would be added');
		}

		foreach ($remaining->getDroppedForeignKeyConstraintNames() as $foreignKey) {
			$name = $foreignKey->getIdentifier()->getValue();
			$this->require(in_array(strtolower($name), $droppedForeignKeys, true), $id, $table, 'existing foreign key ' . $before->foreignKeyLabel($table, $name, $after) . ' conflicts with its declaration');
		}

		// A replacement authorizes both a drop and an addition. An interrupted drop leaves only
		// the authorized addition; a conflicting addition would require an unauthorized drop.
		foreach ($remaining->getAddedIndexes() as $index) {
			$name = strtolower($index->getObjectName()->toString());
			$this->require(in_array($name, $indexes['added'], true), $id, $table, 'index ' . $index->getObjectName()->toString() . ' would be added');
		}

		foreach ($remaining->getDroppedIndexes() as $index) {
			$this->require(in_array(strtolower($index->getObjectName()->toString()), $indexes['dropped'], true), $id, $table, 'existing index ' . $index->getObjectName()->toString() . ' conflicts with its declaration');
		}
	}

	private function describe(TableDiff $diff, SchemaState $before, SchemaState $after): string {
		$table = $this->tableName($diff);
		$parts = [];

		foreach ($diff->getAddedColumns() as $column) {
			$parts[] = 'column ' . $column->getObjectName()->toString() . ' would be added';
		}

		foreach ($diff->getChangedColumns() as $columnDiff) {
			$parts[] = 'column ' . $columnDiff->getOldColumn()->getObjectName()->toString() . ' differs from its declaration';
		}

		foreach ($diff->getDroppedColumns() as $column) {
			$parts[] = 'column ' . $column->getObjectName()->toString() . ' would be dropped';
		}

		foreach ($diff->getAddedForeignKeys() as $foreignKey) {
			$name    = $foreignKey->getObjectName()?->getIdentifier()->getValue() ?? '';
			$parts[] = 'foreign key ' . $after->foreignKeyLabel($table, $name) . ' would be added';
		}

		foreach ($diff->getDroppedForeignKeyConstraintNames() as $foreignKey) {
			$parts[] = 'existing foreign key ' . $before->foreignKeyLabel($table, $foreignKey->getIdentifier()->getValue(), $after) . ' conflicts with its declaration';
		}

		return $parts === [] ? 'table would be altered' : implode('; ', $parts);
	}

	/**
	 * @param array<array-key, Table|Column|Index> $assets
	 *
	 * @return list<string>
	 */
	private function names(array $assets): array {
		return array_values(array_map(static fn (Table|Column|Index $asset): string => strtolower($asset->getObjectName()->toString()), $assets));
	}

	private function tableName(TableDiff $diff): string {
		return $diff->getOldTable()->getObjectName()->toString();
	}

	private function require(bool $condition, string $id, string $table, string $change): void {
		if (! $condition) {
			throw IncompatibleSchema::forChange($id, $table, $change);
		}
	}
}
