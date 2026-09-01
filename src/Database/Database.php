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

	public function tableExists(Table $table): bool {
		return $this->row(
			'SHOW TABLES LIKE %s',
			$this->escLike($this->tableName($table))
		) !== null;
	}

	public function columnExists(Table $table, string $column): bool {
		return $this->row(
			'SHOW COLUMNS FROM %i LIKE %s',
			$this->tableName($table),
			$this->escLike($column)
		) !== null;
	}

	public function indexExists(Table $table, string $index): bool {
		return $this->row(
			'SHOW INDEX FROM %i WHERE Key_name = %s',
			$this->tableName($table),
			$index
		) !== null;
	}

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

	public function value(string $sql, mixed ...$bindings): mixed {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$result   = $this->wpdb->get_var($query);
		$this->throwIfLastError($sql, $bindings);

		return $result;
	}

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

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	public function escLike(string $value): string {
		return $this->wpdb->esc_like($value);
	}

	public function charsetCollate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * @param list<mixed> $bindings
	 */
	private function throwIfLastError(string $sql, array $bindings): void {
		$error = $this->lastError();

		if ($error !== null) {
			throw new QueryException($error, $sql, $bindings, $error);
		}
	}

	private function message(string $fallback): string {
		return $this->lastError() ?? $fallback;
	}

	private function lastError(): ?string {
		$error = $this->wpdb->last_error;

		return $error !== '' ? $error : null;
	}

	/**
	 * @param list<mixed> $bindings
	 */
	private function prepareWithWpdb(string $sql, array $bindings): mixed {
		$method = 'prepare';

		return call_user_func_array([$this->wpdb, $method], array_merge([$sql], $bindings));
	}

	/**
	 * @param array<mixed> $result
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
