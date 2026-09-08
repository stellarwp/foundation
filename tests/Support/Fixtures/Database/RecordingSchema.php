<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Database;

use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;
use StellarWP\Foundation\Database\Table\Blueprint;

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

	public function create(Blueprint $blueprint): void {
		$name                = $blueprint->table()->unprefixedName();
		$this->tables[$name] = true;
		$this->statements[]  = 'create:' . $name;
	}

	public function alter(Blueprint $blueprint): void {
		$this->statements[] = 'alter:' . $blueprint->table()->unprefixedName();
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

	public function drop(Table $table): void {
		$name = $table->unprefixedName();

		unset($this->tables[$name]);

		$this->statements[] = 'drop:' . $name;
	}

	public function quoteIdentifier(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
