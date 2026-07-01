<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Contracts;

use StellarWP\Foundation\Database\Query\QueryBuilder;

/**
 * Executes WordPress database queries and provides the package's developer-facing database API.
 */
interface Database
{
	public function table(Table|string $table, ?string $alias = null): QueryBuilder;

	public function tableName(Table|string $table): string;

	public function tableExists(Table|string $table): bool;

	public function columnExists(Table|string $table, string $column): bool;

	public function indexExists(Table|string $table, string $index): bool;

	public function prepare(string $sql, mixed ...$bindings): string;

	/**
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$bindings): ?array;

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$bindings): array;

	public function value(string $sql, mixed ...$bindings): mixed;

	public function execute(string $sql, mixed ...$bindings): int;

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(Table|string $table, array $data): int;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update(Table|string $table, array $data, array $where): int;

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete(Table|string $table, array $where): int;

	public function quoteIdentifier(string $identifier): string;

	public function escLike(string $value): string;

	public function charsetCollate(): string;
}
