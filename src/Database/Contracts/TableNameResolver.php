<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Resolves a table's physical name for the active database scope.
 */
interface TableNameResolver
{
	/**
	 * Resolve and validate a table's physical name for the active database scope.
	 *
	 * @throws DatabaseException When the table name is invalid for the active database.
	 */
	public function tableName(Table $table): string;
}
