<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Query;

use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Contracts\TableNameResolver;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Query\ValueObjects\TableReference;
use wpdb;

/**
 * Distinguish application names from WordPress-owned physical names.
 *
 * @internal
 */
final readonly class TableReferenceResolver
{
	/**
	 * Reuse Foundation's naming policy and WordPress's configured core tables.
	 */
	public function __construct(
		private TableNameResolver $resolver,
		private wpdb $wpdb,
		private IdentifierQuoter $quoter,
	) {
	}

	/**
	 * Quote a captured physical name and its query-local alias.
	 */
	public function sql(TableReference $table): string {
		return $this->quoter->quote($table->name) . ($table->alias === null ? '' : ' AS ' . $this->quoter->quote($table->alias));
	}

	/**
	 * Resolve application tables once when they enter a query.
	 *
	 * @throws InvalidArgumentException When an alias is invalid.
	 * @throws DatabaseException        When the application table name is invalid.
	 */
	public function resolve(Table|string|TableReference $table): TableReference {
		if ($table instanceof TableReference) {
			return $table;
		}

		$alias = null;

		if (is_string($table)) {
			$parts = preg_split('/\s+as\s+/i', $table, 2);

			if ($parts === false) {
				throw new InvalidArgumentException('Invalid table name: ' . $table);
			}

			$table = $parts[0];
			$alias = $parts[1] ?? null;
		}

		return new TableReference($this->resolver->tableName($table), $alias);
	}

	/**
	 * Resolve a known core table, including multisite and custom user-table settings.
	 *
	 * @throws InvalidArgumentException When WordPress does not own the requested table.
	 */
	public function wordpress(string $name): TableReference {
		$tables = $this->wpdb->tables('all');

		if (! isset($tables[$name])) {
			throw new InvalidArgumentException('Unknown WordPress table: ' . $name);
		}

		return new TableReference($tables[$name]);
	}
}
