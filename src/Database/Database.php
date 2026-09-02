<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;

/**
 * WordPress database service backed by wpdb.
 */
final readonly class Database implements DatabaseContract
{
	private const string ARRAY_A = 'ARRAY_A';

	/**
	 * Create the database API for a WordPress connection and naming scope.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private DatabaseScope $scope
	) {
	}

	/**
	 * Resolve and validate a table's physical name for the active WordPress database scope.
	 *
	 * @throws DatabaseException When the unprefixed name is invalid or the physical name is unsafe or exceeds MySQL's identifier limit.
	 */
	public function tableName(Table $table): string {
		$unprefixedTableName = $table->unprefixedName();

		if ($unprefixedTableName === '' || trim($unprefixedTableName) !== $unprefixedTableName) {
			throw new DatabaseException('The unprefixed database table name cannot be blank or contain surrounding whitespace.');
		}

		$tableName = $this->scope->resolveTableName($unprefixedTableName);

		if (preg_match('/\A[A-Za-z0-9_]+\z/', $tableName) !== 1) {
			throw new DatabaseException('Database table names may contain only ASCII letters, numbers, and underscores.');
		}

		if (strlen($tableName) > 64) {
			throw new DatabaseException(sprintf('Database table name "%s" exceeds MySQL\'s 64-character identifier limit.', $tableName));
		}

		return $tableName;
	}

	/**
	 * Determine whether the table exists in the active WordPress database scope.
	 *
	 * @throws DatabaseException When table-name resolution or inspection fails.
	 */
	public function tableExists(Table $table): bool {
		return $this->row(
			'SHOW TABLES LIKE %s',
			$this->escLike($this->tableName($table))
		) !== null;
	}

	/**
	 * Determine whether a named column exists on the table.
	 *
	 * @throws DatabaseException When table-name resolution or inspection fails.
	 */
	public function columnExists(Table $table, string $column): bool {
		return $this->row(
			'SHOW COLUMNS FROM %i LIKE %s',
			$this->tableName($table),
			$this->escLike($column)
		) !== null;
	}

	/**
	 * Determine whether a named index exists on the table.
	 *
	 * @throws DatabaseException When table-name resolution or inspection fails.
	 */
	public function indexExists(Table $table, string $index): bool {
		return $this->row(
			'SHOW INDEX FROM %i WHERE Key_name = %s',
			$this->tableName($table),
			$index
		) !== null;
	}

	/**
	 * Prepare a non-empty SQL template with WordPress placeholder bindings.
	 *
	 * @throws QueryException When the template is empty or WordPress cannot prepare it.
	 */
	public function prepare(string $sql, mixed ...$bindings): string {
		if (trim($sql) === '') {
			throw new QueryException('SQL statement cannot be empty.', $sql, array_values($bindings));
		}

		if ($bindings === []) {
			return $sql;
		}

		$bindings = array_values($bindings);
		$prepared = $this->prepareWithWpdb($sql, $bindings);

		if (! is_string($prepared) || $prepared === '') {
			throw new QueryException('Unable to prepare SQL statement.', $sql, $bindings, $this->lastError());
		}

		return $prepared;
	}

	/**
	 * Execute a query and return its first row when present.
	 *
	 * @throws QueryException When preparation or execution fails.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$result   = $this->wpdb->get_row($query, self::ARRAY_A);
		$this->throwIfLastError($sql, $bindings);

		if ($result === null) {
			return null;
		}

		return $this->stringKeyedRow($result);
	}

	/**
	 * Execute a query and return all rows with string keys.
	 *
	 * @throws QueryException When preparation or execution fails.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$results  = $this->wpdb->get_results($query, self::ARRAY_A);
		$this->throwIfLastError($sql, $bindings);

		if ($results === null) {
			throw new QueryException('Unable to retrieve database rows.', $sql, $bindings);
		}

		$rows = [];

		foreach ($results as $result) {
			$rows[] = $this->stringKeyedRow($result);
		}

		return $rows;
	}

	/**
	 * Execute a query and return the first column from its first row.
	 *
	 * @throws QueryException When preparation or execution fails.
	 */
	public function value(string $sql, mixed ...$bindings): mixed {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$result   = $this->wpdb->get_var($query);
		$this->throwIfLastError($sql, $bindings);

		return $result;
	}

	/**
	 * Execute a write or schema statement and return its affected-row count.
	 *
	 * @throws QueryException When preparation or execution fails.
	 */
	public function execute(string $sql, mixed ...$bindings): int {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$result   = $this->wpdb->query($query);

		if ($result === false) {
			throw new QueryException($this->message('Unable to execute SQL statement.'), $sql, $bindings, $this->lastError());
		}

		return (int) $result;
	}

	/**
	 * Insert one row into the supplied table.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the insert fails.
	 */
	public function insert(Table $table, array $data): int {
		$result = $this->wpdb->insert($this->tableName($table), $data);

		if ($result === false) {
			throw new QueryException($this->message('Unable to insert database row.'), 'INSERT', [], $this->lastError());
		}

		return (int) $result;
	}

	/**
	 * Insert one row and return the connection's auto-increment identifier.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the insert fails.
	 */
	public function insertGetId(Table $table, array $data): int {
		$this->insert($table, $data);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update rows matching equality-based column values.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the update fails.
	 */
	public function update(Table $table, array $data, array $where): int {
		$result = $this->wpdb->update($this->tableName($table), $data, $where);

		if ($result === false) {
			throw new QueryException($this->message('Unable to update database rows.'), 'UPDATE', [], $this->lastError());
		}

		return (int) $result;
	}

	/**
	 * Delete rows matching equality-based column values.
	 *
	 * @param array<string, mixed> $where
	 *
	 * @throws DatabaseException When table-name resolution or validation fails.
	 * @throws QueryException    When the delete fails.
	 */
	public function delete(Table $table, array $where): int {
		$result = $this->wpdb->delete($this->tableName($table), $where);

		if ($result === false) {
			throw new QueryException($this->message('Unable to delete database rows.'), 'DELETE', [], $this->lastError());
		}

		return (int) $result;
	}

	/**
	 * Quote one trusted SQL identifier while escaping embedded backticks.
	 */
	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	/**
	 * Escape SQL LIKE wildcard characters without adding a surrounding pattern.
	 */
	public function escLike(string $value): string {
		return $this->wpdb->esc_like($value);
	}

	/**
	 * Return the charset and collation clause configured by WordPress.
	 */
	public function charsetCollate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Throw the current WordPress database error with its original query context.
	 *
	 * @param list<mixed> $bindings
	 *
	 * @throws QueryException When WordPress reports a query error.
	 */
	private function throwIfLastError(string $sql, array $bindings): void {
		$error = $this->lastError();

		if ($error !== null) {
			throw new QueryException($error, $sql, $bindings, $error);
		}
	}

	/**
	 * Prefer the current WordPress database error over a generic fallback.
	 */
	private function message(string $fallback): string {
		return $this->lastError() ?? $fallback;
	}

	/**
	 * Return the current WordPress database error when one is present.
	 */
	private function lastError(): ?string {
		$error = $this->wpdb->last_error;

		return $error !== '' ? $error : null;
	}

	/**
	 * Invoke wpdb::prepare() without relying on its variadic signature in static analysis.
	 *
	 * @param list<mixed> $bindings
	 */
	private function prepareWithWpdb(string $sql, array $bindings): mixed {
		$method = 'prepare';

		return call_user_func_array([$this->wpdb, $method], array_merge([$sql], $bindings));
	}

	/**
	 * Validate that a WordPress row result contains only string keys.
	 *
	 * @param array<mixed> $result
	 *
	 * @throws DatabaseException When WordPress returns a non-string row key.
	 *
	 * @return array<string, mixed>
	 */
	private function stringKeyedRow(array $result): array {
		$row = [];

		foreach ($result as $key => $value) {
			if (! is_string($key)) {
				throw new DatabaseException('Database row result contained a non-string key.');
			}

			$row[$key] = $value;
		}

		return $row;
	}
}
