<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database;

use StellarWP\Foundation\Database\Contracts\Database as DatabaseContract;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\QueryException;
use StellarWP\Foundation\Database\Query\QueryBuilder;

/**
 * WordPress database service backed by wpdb.
 */
final readonly class Database implements DatabaseContract
{
	private const string ARRAY_A = 'ARRAY_A';

	public function __construct(
		private \wpdb $wpdb
	) {
	}

	public function table(Table|string $table, ?string $alias = null): QueryBuilder {
		return new QueryBuilder($this, $table, $alias);
	}

	public function tableName(Table|string $table): string {
		if ($table instanceof Table) {
			return $table->name();
		}

		if (str_starts_with($table, $this->wpdb->prefix)) {
			return $table;
		}

		return $this->wpdb->prefix . $table;
	}

	public function tableExists(Table|string $table): bool {
		$tableName = $this->tableName($table);

		return $this->row(
			'SHOW TABLES LIKE %s',
			$this->escLike($tableName)
		) !== null;
	}

	public function columnExists(Table|string $table, string $column): bool {
		return $this->row(
			'SHOW COLUMNS FROM %i LIKE %s',
			$this->tableName($table),
			$this->escLike($column)
		) !== null;
	}

	public function indexExists(Table|string $table, string $index): bool {
		return $this->row(
			'SHOW INDEX FROM %i WHERE Key_name = %s',
			$this->tableName($table),
			$index
		) !== null;
	}

	public function prepare(string $sql, mixed ...$bindings): string {
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

		if ($result === null) {
			$this->throwIfLastError('Unable to retrieve database row.', $sql, $bindings);

			return null;
		}

		return $this->stringKeyedRow($result, $sql, $bindings);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$results  = $this->wpdb->get_results($query, self::ARRAY_A);

		if ($results === null) {
			$this->throwIfLastError('Unable to retrieve database rows.', $sql, $bindings);

			return [];
		}

		$rows = [];

		foreach ($results as $result) {
			$rows[] = $this->stringKeyedRow($result, $sql, $bindings);
		}

		return $rows;
	}

	public function value(string $sql, mixed ...$bindings): mixed {
		$bindings = array_values($bindings);
		$query    = $this->prepare($sql, ...$bindings);
		$result   = $this->wpdb->get_var($query);

		if ($result === null) {
			$this->throwIfLastError('Unable to retrieve database value.', $sql, $bindings);
		}

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
	 */
	public function insert(Table|string $table, array $data): int {
		$result = $this->wpdb->insert($this->tableName($table), $data);

		if ($result === false) {
			throw new QueryException($this->message('Unable to insert database row.'), 'INSERT', [], $this->lastError());
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update(Table|string $table, array $data, array $where): int {
		$result = $this->wpdb->update($this->tableName($table), $data, $where);

		if ($result === false) {
			throw new QueryException($this->message('Unable to update database rows.'), 'UPDATE', [], $this->lastError());
		}

		return (int) $result;
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete(Table|string $table, array $where): int {
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
	private function throwIfLastError(string $fallback, string $sql, array $bindings): void {
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
	 * @param list<mixed>  $bindings
	 *
	 * @return array<string, mixed>
	 */
	private function stringKeyedRow(array $result, string $sql, array $bindings): array {
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
