<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\ManagedTable;
use StellarWP\Foundation\Database\Contracts\TableGateway;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Table\Table;
use StellarWP\Foundation\Database\Table\TableDefinition;

/**
 * Provides a concrete table gateway for database table tests.
 */
final readonly class TestDatabaseTable extends Table implements ManagedTable
{
	public function __construct(
		private string $unprefixedTableName,
		TableGateway $database
	) {
		parent::__construct($database);
	}

	public function unprefixedName(): string {
		return $this->unprefixedTableName;
	}

	public function definition(): TableDefinition {
		$table = TableDefinition::for($this);
		$table->bigIncrements('id');

		return $table;
	}

	/**
	 * Archive records matching the supplied status.
	 *
	 * @throws DatabaseException When the physical table name is invalid.
	 * @throws QueryException    When the update fails.
	 */
	public function archiveByStatus(string $status): int {
		return $this->database()->execute(
			'UPDATE %i SET status = %s WHERE status = %s',
			$this->name(),
			'archived',
			$status
		);
	}
}
