<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;

/**
 * Read a schema snapshot with the database facts required for migration comparison and renames.
 *
 * @internal
 */
final readonly class SchemaInspector
{
	/**
	 * Receive the shared connection and column rename strategy without inspecting the database.
	 */
	public function __construct(
		private Connection $db,
		private ColumnRename $columnRenames,
	) {
	}

	/**
	 * Introspect the owned tables that currently exist, overlaying tables a preview has already simulated.
	 *
	 * A simulated table that is absent from the overlay was deliberately dropped by an earlier step,
	 * so it must not be reloaded from the database.
	 *
	 * @param list<string> $names
	 * @param list<string> $simulated Lower-cased names of every table an earlier preview step decided on.
	 *
	 * @throws Exception When the database schema or supplemental metadata cannot be read.
	 */
	public function inspect(array $names, ?SchemaState $overlay = null, array $simulated = []): SchemaState {
		foreach ($overlay?->schema->getTables() ?? [] as $table) {
			$names[] = $table->getObjectName()->toString();
		}

		$manager    = $this->db->createSchemaManager();
		$tables     = [];
		$timestamps = $this->timestampAttributes($names);

		foreach (array_unique($names) as $name) {
			if ($overlay?->schema->hasTable($name) ?? false) {
				$tables[] = $overlay->schema->getTable($name);
			} elseif (in_array(strtolower($name), $simulated, true)) {
				continue; // Dropped by an earlier simulated step.
			} elseif ($manager->tablesExist([
				$name,
			])) {
				$table    = $this->normalize($manager->introspectTable($name));
				$tables[] = $this->columnRenames->inspect($table);
			}
		}

		$facts = [];

		foreach ($tables as $table) {
			$name = strtolower($table->getObjectName()->toString());

			if ($overlay?->schema->hasTable($name)) {
				foreach ($overlay->timestamps as $key => $attributes) {
					if (str_starts_with($key, $name . '.')) {
						$facts[$key] = $attributes;
					}
				}
			} else {
				foreach ($timestamps[$name] ?? [] as $column => $attributes) {
					$facts[$name . '.' . $column] = $attributes;
				}
			}
		}

		return new SchemaState(new Schema($tables), $facts);
	}

	/**
	 * Read what DBAL does not: fractional precision and ON UPDATE for datetime columns.
	 *
	 * @param list<string> $names
	 *
	 * @return array<string, array<string, array{precision: int, on_update: bool}>> Lower-cased table, then column.
	 */
	private function timestampAttributes(array $names): array {
		if ($names === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($names), '?'));
		$rows         = $this->db->fetchAllAssociative(
			"SELECT
				TABLE_NAME,
				COLUMN_NAME,
				DATETIME_PRECISION,
				EXTRA
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND DATA_TYPE IN ('datetime', 'timestamp')
				AND TABLE_NAME IN ({$placeholders})",
			$names,
		);

		$attributes = [];

		foreach ($rows as $row) {
			$table  = strtolower((string) $row['TABLE_NAME']);
			$column = strtolower((string) $row['COLUMN_NAME']);

			$attributes[$table][$column] = [
				'precision' => (int) $row['DATETIME_PRECISION'],
				'on_update' => stripos((string) $row['EXTRA'], 'on update') !== false,
			];
		}

		return $attributes;
	}

	/**
	 * Align engine-reported values with Foundation's declarations where DBAL does not.
	 *
	 * MariaDB reports fractional timestamp defaults as `current_timestamp(6)`; DBAL only recognizes
	 * the exact `CURRENT_TIMESTAMP`. Declarations always use upper case, so upper-case the report.
	 */
	private function normalize(Table $table): Table {
		$editor = $table->edit();

		foreach ($table->getColumns() as $column) {
			$default = $column->getDefault();

			if (is_string($default) && preg_match('/^current_timestamp(\(\d+\))?$/i', $default) === 1) {
				$editor->modifyColumn($column->getObjectName(), static function (ColumnEditor $column) use ($default): void {
					$column->setDefaultValue(strtoupper($default));
				});
			}
		}

		return $editor->create();
	}
}
