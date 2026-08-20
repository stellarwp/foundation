<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;

final class RecordingSchema implements Schema
{
	/**
	 * @var list<string>
	 */
	public array $statements = [];

	/**
	 * @var array<string, bool>
	 */
	public array $tables = [];

	/**
	 * @var array<string, array<string, bool>>
	 */
	public array $indexes = [];

	public function createOrUpdate(Table $table): void {
		$name                = $table->name();
		$this->tables[$name] = true;
		$this->statements[]  = 'createOrUpdate:' . $name;
	}

	public function createOrUpdateSql(string $sql): void {
		$this->statements[] = 'createOrUpdateSql:' . $sql;
	}

	public function execute(string $sql): void {
		$this->statements[] = $sql;
	}

	public function hasTable(Table|string $table): bool {
		$name = $table instanceof Table ? $table->name() : $table;

		return $this->tables[$name] ?? false;
	}

	public function hasIndex(Table|string $table, string $index): bool {
		$name = $table instanceof Table ? $table->name() : $table;

		return $this->indexes[$name][$index] ?? false;
	}

	public function dropIndex(Table|string $table, string $index): void {
		$name = $table instanceof Table ? $table->name() : $table;

		unset($this->indexes[$name][$index]);

		$this->statements[] = sprintf('dropIndex:%s:%s', $name, $index);
	}

	public function drop(Table|string $table): void {
		$name = $table instanceof Table ? $table->name() : $table;

		unset($this->tables[$name]);

		$this->statements[] = 'drop:' . $name;
	}

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
