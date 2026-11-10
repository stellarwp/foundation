<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Contracts\TableNameResolver as TableNameResolverContract;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;

/**
 * Resolve and validate physical table names at the active WordPress site.
 *
 * @internal Applications inject a table instead of coordinating name resolution.
 */
final readonly class TableNameResolver implements TableNameResolverContract
{
	/**
	 * Use the application's configured database scope.
	 */
	public function __construct(
		private DatabaseScope $scope,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function tableName(Table|string $table): string {
		$unprefixed = is_string($table) ? $table : $table->unprefixedName();

		if ($unprefixed === '' || trim($unprefixed) !== $unprefixed) {
			throw new DatabaseException('The unprefixed database table name cannot be blank or contain surrounding whitespace.');
		}
		$name = $this->scope->resolveTableName($unprefixed);

		if ($name === '' || preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1 || strlen($name) > 64) {
			throw new DatabaseException('Physical table names must contain at most 64 ASCII letters, numbers, or underscores.');
		}

		return $name;
	}
}
