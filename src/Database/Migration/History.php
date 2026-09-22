<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use StellarWP\Foundation\Database\Table\Tables\MigrationTable;

/**
 * Ledger of applied migration identities, created on first use under the migration lock.
 *
 * @internal Storage detail of the runner; applications inspect it through Migrator::status().
 */
final readonly class History
{
	/**
	 * Receive the shared connection and configured ledger table.
	 */
	public function __construct(
		private Connection $connection,
		private MigrationTable $table,
	) {
	}

	/**
	 * Physical ledger name for the current site.
	 *
	 * @return non-empty-string
	 */
	public function table(): string {
		return $this->table->name();
	}

	/**
	 * Create the history table when first running migrations.
	 *
	 * @throws Exception When inspection or creation fails.
	 */
	public function initialize(): void {
		$manager = $this->connection->createSchemaManager();

		if ($manager->tablesExist([$this->table()])) {
			return;
		}

		$table = Table::editor()->setUnquotedName($this->table())
			->setOptions(['engine' => 'InnoDB'])
			->setColumns(
				Column::editor()->setUnquotedName('version')->setTypeName(Types::BINARY)->setLength(191)->setNotNull(true)->create(),
				Column::editor()->setUnquotedName('applied_at')->setTypeName(Types::DATETIME_MUTABLE)
					->setColumnDefinition('DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)')->create(),
			)
			->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('version')->create())
			->create();
		$manager->createTable($table);
	}

	/**
	 * Applied identities keyed by ID with their timestamps, in byte order.
	 *
	 * @throws Exception
	 *
	 * @return array<string, string>
	 */
	public function applied(): array {
		if (! $this->connection->createSchemaManager()->tablesExist([$this->table()])) {
			return [];
		}
		$rows = $this->connection->fetchAllKeyValue('SELECT version, applied_at FROM ' . $this->quoted() . ' ORDER BY version');

		return array_map('strval', $rows);
	}

	/**
	 * Record successful completion after migration work.
	 *
	 * @throws Exception When the ledger write fails; the schema change has already committed.
	 */
	public function record(string $id): void {
		$this->connection->insert($this->quoted(), ['version' => $id]);
	}

	/**
	 * Remove history after successful reversal.
	 *
	 * @throws Exception When the ledger delete fails; the inverse has already committed.
	 */
	public function remove(string $id): void {
		$this->connection->delete($this->quoted(), ['version' => $id]);
	}

	private function quoted(): string {
		return $this->table->quotedName();
	}
}
