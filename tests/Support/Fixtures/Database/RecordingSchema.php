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
		$name                = $table->unprefixedName();
		$this->tables[$name] = true;
		$this->statements[]  = 'createOrUpdate:' . $name;
	}

	public function execute(string $sql): void {
		$this->statements[] = $sql;
	}

	public function hasTable(Table $table): bool {
		return $this->tables[$table->unprefixedName()] ?? false;
	}

	public function hasIndex(Table $table, string $index): bool {
		return $this->indexes[$table->unprefixedName()][$index] ?? false;
	}

	public function dropIndex(Table $table, string $index): void {
		$name = $table->unprefixedName();

		unset($this->indexes[$name][$index]);

		$this->statements[] = sprintf('dropIndex:%s:%s', $name, $index);
	}

	public function drop(Table $table): void {
		$name = $table->unprefixedName();

		unset($this->tables[$name]);

		$this->statements[] = 'drop:' . $name;
	}

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
