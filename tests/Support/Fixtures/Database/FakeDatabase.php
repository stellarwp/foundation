<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Contracts\Table;

final class FakeDatabase implements Database
{
	public string $prefix = 'wp_';

	/**
	 * @var list<string>
	 */
	public array $executed = [];

	/**
	 * @var list<string>
	 */
	public array $rowQueries = [];

	/**
	 * @var list<string>
	 */
	public array $rowsQueries = [];

	/**
	 * @var list<array<string, mixed>|callable(string, self): (array<string, mixed>|null)|null>
	 */
	public array $rowResults = [];

	/**
	 * @var list<list<array<string, mixed>>>
	 */
	public array $rowsResults = [];

	/**
	 * @var list<int>
	 */
	public array $executeResults = [];

	public int $insertId = 1;

	public int $insertResult = 1;

	/**
	 * @var array<string, mixed>|null
	 */
	public ?array $tableStatus = ['Collation' => 'utf8mb4_general_ci'];

	public function tableName(Table $table): string {
		return $this->prefix . $table->unprefixedName();
	}

	public function tableExists(Table $table): bool {
		return $this->row('SHOW TABLES LIKE %s', $this->escLike($this->tableName($table))) !== null;
	}

	public function columnExists(Table $table, string $column): bool {
		return $this->row('SHOW COLUMNS FROM %i LIKE %s', $this->tableName($table), $this->escLike($column)) !== null;
	}

	public function indexExists(Table $table, string $index): bool {
		return $this->row('SHOW INDEX FROM %i WHERE Key_name = %s', $this->tableName($table), $index) !== null;
	}

	public function execute(string $sql, mixed ...$bindings): int {
		$this->executed[] = $this->prepare($sql, ...$bindings);

		return array_shift($this->executeResults) ?? 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array {
		$query              = $this->prepare($sql, ...$bindings);
		$this->rowQueries[] = $query;

		if (str_starts_with($sql, 'SHOW TABLE STATUS ')) {
			return $this->tableStatus;
		}

		$result = array_shift($this->rowResults);

		if (is_callable($result)) {
			return $result($query, $this);
		}

		return $result;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array {
		$this->rowsQueries[] = $this->prepare($sql, ...$bindings);

		return array_shift($this->rowsResults) ?? [];
	}

	public function value(string $sql, mixed ...$bindings): mixed {
		$row = $this->row($sql, ...$bindings);

		if ($row === null) {
			return null;
		}

		return reset($row);
	}

	public function insert(Table $table, array $data): int {
		$this->executed[] = 'INSERT ' . $this->tableName($table);

		return $this->insertResult;
	}

	public function insertGetId(Table $table, array $data): int {
		$this->insert($table, $data);

		return $this->insertId;
	}

	public function update(Table $table, array $data, array $where): int {
		$this->executed[] = 'UPDATE ' . $this->tableName($table);

		return array_shift($this->executeResults) ?? 1;
	}

	public function delete(Table $table, array $where): int {
		$this->executed[] = 'DELETE ' . $this->tableName($table);

		return array_shift($this->executeResults) ?? 1;
	}

	public function prepare(string $sql, mixed ...$bindings): string {
		$position = 0;

		return preg_replace_callback('/(?<!%)%[sdi]/', static function (array $matches) use ($bindings, &$position): string {
			$value = $bindings[$position++] ?? '';

			if ($matches[0] === '%d') {
				return (string) (int) $value;
			}

			if ($matches[0] === '%i') {
				return '`' . str_replace('`', '``', (string) $value) . '`';
			}

			return "'" . addslashes((string) $value) . "'";
		}, $sql) ?? $sql;
	}

	public function escLike(string $value): string {
		return addcslashes($value, '_%\\');
	}

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	public function charsetCollate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
}
