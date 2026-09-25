<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\SchemaException;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Migrations\Exceptions\IrreversibleMigration;
use StellarWP\Foundation\Migrations\Exceptions\MigrationInterrupted;
use StellarWP\Foundation\Migrations\MigrationCollection;
use StellarWP\Foundation\Migrations\Schema\Factories\MigrationComparatorFactory;
use StellarWP\Foundation\Migrations\Schema\ValueObjects\SchemaPlan;

/**
 * Plan declared operations against the current database schema.
 *
 * @internal
 */
final readonly class SchemaPlanner
{
	/**
	 * Receive the shared connection, naming policy, and migration declarations.
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
	 * Plan the current declaration against live tables or earlier preview results.
	 *
	 * @param list<string> $simulatedNames Tables already decided by an earlier preview step.
	 *
	 * @throws \Throwable When a declaration is invalid or schema inspection fails.
	 */
	public function plan(string $id, bool $reverse, ?SchemaState $simulated = null, array $simulatedNames = []): SchemaPlan {
		$state     = new SchemaState(new Schema());
		$blueprint = new Blueprint($state, $this->names, $this->tableOptions);
		$migration = $this->migrations->get($id);

		try {
			$reverse ? $migration->down($blueprint) : $migration->up($blueprint);
		} catch (IrreversibleMigration $failure) {
			throw new IrreversibleMigration(sprintf('Migration "%s" cannot be reversed: %s', $id, $failure->getMessage()), 0, $failure);
		}

		$names             = $blueprint->tableNames();
		$before            = $this->inspector->inspect($names, $simulated, $simulatedNames);
		$state->schema     = clone $before->schema;
		$state->timestamps = $before->timestamps;
		$comparator        = $this->comparators->create();
		$sql               = [];

		try {
			foreach ($blueprint->apply() as $after) {
				$renameSql = $this->renames->plan($before, $after);
				$diff      = $comparator->compareSchemas($before->schema, $after->schema);
				$sql       = array_merge(
					$sql,
					$renameSql,
					$this->db->getDatabasePlatform()->getAlterSchemaSQL($diff),
					$this->timestampSql($before, $after, $diff),
					$this->commentSql($before, $after),
				);
				$before = $after;
			}
		} catch (SchemaException $failure) {
			throw new MigrationInterrupted(sprintf(
				'Migration %s cannot apply its declaration to the current schema (%s). Inspect and repair any partially applied work before retrying.',
				$id,
				$failure->getMessage(),
			), 0, $failure);
		}

		return new SchemaPlan($state, $sql, $names);
	}

	/**
	 * Emit changes to timestamp attributes DBAL cannot compare.
	 *
	 * @return list<string> SQL statements for attributes DBAL does not compare.
	 */
	private function timestampSql(SchemaState $actual, SchemaState $desired, SchemaDiff $remaining): array {
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

				if (! $actualTable->hasColumn($column->getObjectName()->toString()) || ! isset($desired->timestamps[$key]) || $desired->timestamps[$key] === ($actual->timestamps[$key] ?? [
					'precision' => 0,
					'on_update' => false,
				])) {
					continue;
				}

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
	private function commentSql(SchemaState $actual, SchemaState $after): array {
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
			$table = $tableDiff->getOldTable()->getObjectName()->toString();

			foreach ($tableDiff->getAddedColumns() as $column) {
				$keys[] = strtolower($table . '.' . $column->getObjectName()->toString());
			}

			foreach ($tableDiff->getChangedColumns() as $columnDiff) {
				$keys[] = strtolower($table . '.' . $columnDiff->getOldColumn()->getObjectName()->toString());
			}
		}

		return $keys;
	}
}
