<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Schema\Renames;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\JsonType;
use StellarWP\Foundation\Migrations\Schema\Renames\Contracts\ColumnRename;

/**
 * Use Doctrine's CHANGE SQL while retaining attributes absent from its schema model.
 *
 * @internal
 */
final readonly class ChangeColumnRename implements ColumnRename
{
	/**
	 * Receive the shared connection for metadata, quoting, and platform SQL.
	 */
	public function __construct(
		private Connection $db,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function inspect(Table $table): Table {
		$rows = $this->db->fetchAllAssociative(
			'SELECT
				COLUMN_NAME,
				COLUMN_TYPE,
				DATA_TYPE,
				IS_NULLABLE,
				COLUMN_DEFAULT,
				CHARACTER_SET_NAME,
				COLLATION_NAME,
				COLUMN_COMMENT,
				EXTRA,
				GENERATION_EXPRESSION
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = ?',
			[
				$table->getObjectName()->getUnqualifiedName()->getValue(),
			],
		);
		$editor = $table->edit()->setIndexes(...array_values(array_diff_key($table->getIndexes(), [
			'primary' => true,
		])));

		foreach ($rows as $row) {
			$column     = $table->getColumn((string) $row['COLUMN_NAME']);
			$definition = $this->definition($row, $column);
			$editor->modifyColumn($column->getObjectName(), static function (ColumnEditor $column) use ($definition): void {
				$column->setColumnDefinition($definition);
			});
		}

		return $editor->create();
	}

	/**
	 * {@inheritDoc}
	 */
	public function sql(Table $table, string $from, string $to): array {
		$column  = $table->getColumn($from)->edit()->setQuotedName($from)->create();
		$renamed = $column->edit()->setQuotedName($to)->create();
		$diff    = new TableDiff($table, changedColumns: [
			$from => new ColumnDiff($column, $renamed),
		]);

		return $this->db->getDatabasePlatform()->getAlterTableSQL($diff);
	}

	/**
	 * Preserve catalog attributes literally where Doctrine's portable model loses information.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return non-empty-string
	 */
	private function definition(array $row, Column $column): string {
		$platform = $this->db->getDatabasePlatform();
		$json     = $column->getType() instanceof JsonType;
		// MariaDB reports JSON as LONGTEXT; restating JSON preserves its validation constraint.
		$sql = $json ? $platform->getJsonTypeDeclarationSQL($column->toArray()) : (string) $row['COLUMN_TYPE'];

		if (! $json && $row['CHARACTER_SET_NAME'] !== null) {
			$sql .= ' CHARACTER SET ' . $platform->quoteSingleIdentifier((string) $row['CHARACTER_SET_NAME']);
			$sql .= ' COLLATE ' . $platform->quoteSingleIdentifier((string) $row['COLLATION_NAME']);
		}

		$extra      = (string) $row['EXTRA'];
		$expression = (string) $row['GENERATION_EXPRESSION'];

		if ($expression !== '') {
			$sql .= ' GENERATED ALWAYS AS (' . $expression . ')';
			$sql .= stripos($extra, 'stored') !== false ? ' STORED' : ' VIRTUAL';

			if (stripos($extra, 'invisible') !== false) {
				$sql .= ' INVISIBLE';
			}
		} else {
			$sql .= $column->getNotnull() ? ' NOT NULL' : ' NULL';
			$sql .= $this->defaultSql($row, $column);
			$sql .= ' ' . trim(str_ireplace('DEFAULT_GENERATED', '', $extra));
		}

		if ($row['COLUMN_COMMENT'] !== '') {
			$sql .= ' COMMENT ' . $platform->quoteStringLiteral((string) $row['COLUMN_COMMENT']);
		}

		return $sql;
	}

	/**
	 * MariaDB reports SQL defaults; MySQL reports literal values except generated defaults.
	 *
	 * @param array<string, mixed> $row
	 */
	private function defaultSql(array $row, Column $column): string {
		if ($row['COLUMN_DEFAULT'] === null) {
			return $column->getNotnull() ? '' : ' DEFAULT NULL';
		}

		$platform = $this->db->getDatabasePlatform();
		$default  = (string) $row['COLUMN_DEFAULT'];

		if ($platform instanceof MariaDBPlatform) {
			return ' DEFAULT ' . $default;
		}

		if (in_array($row['DATA_TYPE'], [
			'datetime',
			'timestamp',
		], true) && preg_match('/^current_timestamp(?:\(\d*\))?$/i', $default) === 1) {
			return ' DEFAULT ' . $default;
		}

		if (stripos((string) $row['EXTRA'], 'DEFAULT_GENERATED') !== false) {
			return ' DEFAULT (' . $default . ')';
		}

		return ' DEFAULT ' . $platform->quoteStringLiteral($default);
	}
}
