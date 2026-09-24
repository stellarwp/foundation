<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Closure;
use Doctrine\DBAL\Schema\Exception\TableAlreadyExists;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;

/**
 * The schema handed to up() and down(): declare tables to create, alter, or drop.
 *
 * Table names are resolved through the current site scope, so migrations name application
 * tables by their historical unprefixed names and never repeat WordPress prefix handling.
 */
final class Blueprint
{
	/** @var list<Closure(): void> Deferred mutations of the Doctrine schema, in declaration order. */
	private array $operations = [];

	/**
	 * @param array<string, mixed> $tableOptions Default engine/charset options for created tables.
	 *
	 * @internal Constructed by Foundation; applications receive this object through provider wiring or migration callbacks.
	 */
	public function __construct(
		private readonly SchemaState $state,
		private readonly TableNameResolver $names,
		private readonly array $tableOptions = [],
	) {
	}

	/**
	 * Declare a new table and its complete initial definition.
	 */
	public function create(Table|string $table): TableBlueprint {
		$blueprint          = new TableBlueprint($this->resolve($table));
		$this->operations[] = function () use ($blueprint): void {
			$name = $blueprint->name();

			if ($this->state->schema->hasTable($name)) {
				throw TableAlreadyExists::new($name);
			}
			$table = DoctrineTable::editor()->setUnquotedName($name)->setOptions($this->tableOptions);
			$this->replaceTable($blueprint->applyTo($table, $this->state, $this->names));
		};

		return $blueprint;
	}

	/**
	 * Declare additions, changes, and removals on an existing table.
	 */
	public function table(Table|string $table): TableBlueprint {
		$blueprint          = new TableBlueprint($this->resolve($table));
		$this->operations[] = function () use ($blueprint): void {
			$table = $this->state->schema->getTable($blueprint->name());
			// Keep supporting indexes when a foreign key is removed. The editor already carries the primary key separately.
			$secondaryIndexes = array_diff_key($table->getIndexes(), ['primary' => true]);
			$editor           = $table->edit()->setIndexes(...array_values($secondaryIndexes));

			$this->replaceTable($blueprint->applyTo($editor, $this->state, $this->names));
		};

		return $blueprint;
	}

	/**
	 * Declare that the table no longer exists after this migration.
	 */
	public function drop(Table|string $table): void {
		$name               = $this->resolve($table);
		$this->operations[] = function () use ($name): void {
			$this->state->schema->dropTable($name);
			foreach (array_keys($this->state->timestamps) as $key) {
				if (str_starts_with($key, strtolower($name) . '.')) {
					unset($this->state->timestamps[$key]);
				}
			}
		};
	}

	/**
	 * Resolve the physical table name for the current site.
	 *
	 * @return non-empty-string
	 */
	public function resolve(Table|string $table): string {
		return $this->names->tableName($table);
	}

	/**
	 * Mutate the underlying Doctrine schema with the collected declarations.
	 *
	 * @internal Called by the runner after up()/down() returns.
	 */
	public function apply(): void {
		foreach ($this->operations as $operation) {
			$operation();
		}

		$this->operations = [];
	}

	private function replaceTable(DoctrineTable $table): void {
		$name                = $table->getObjectName()->toString();
		$tables              = array_filter($this->state->schema->getTables(), static fn (DoctrineTable $existing): bool => $existing->getObjectName()->toString() !== $name);
		$tables[]            = $table;
		$this->state->schema = new Schema(array_values($tables));
	}
}
