<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration\Schema;

use Closure;
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
				throw \Doctrine\DBAL\Schema\Exception\TableAlreadyExists::new($name);
			}
			$table = DoctrineTable::editor()->setUnquotedName($name)->setOptions($this->tableOptions);
			$this->replaceTable($blueprint->applyTo($table, $this->state));
		};

		return $blueprint;
	}

	/**
	 * Declare additions, changes, and removals on an existing table.
	 */
	public function table(Table|string $table): TableBlueprint {
		$blueprint          = new TableBlueprint($this->resolve($table));
		$this->operations[] = function () use ($blueprint): void {
			$this->replaceTable($blueprint->applyTo($this->state->schema->getTable($blueprint->name())->edit(), $this->state));
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
		$this->state->schema = new \Doctrine\DBAL\Schema\Schema(array_values($tables));
	}
}
