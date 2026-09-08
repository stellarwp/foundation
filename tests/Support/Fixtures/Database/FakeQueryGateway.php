<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\QueryGateway;
use StellarWP\Foundation\Database\Contracts\Table;

final class FakeQueryGateway implements QueryGateway
{
	/** @var list<string> */
	public array $queries = [];

	public function prepare(string $sql, mixed ...$bindings): string {
		return $sql;
	}

	public function row(string $sql, mixed ...$bindings): ?array {
		$this->queries[] = $sql;

		return null;
	}

	public function rows(string $sql, mixed ...$bindings): array {
		$this->queries[] = $sql;

		return [];
	}

	public function value(string $sql, mixed ...$bindings): mixed {
		$this->queries[] = $sql;

		return null;
	}

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}

	public function tableName(Table $table): string {
		return 'wp_' . $table->unprefixedName();
	}
}
