<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

use ArrayIterator;
use IteratorAggregate;
use StellarWP\Foundation\Database\Contracts\Schema;
use StellarWP\Foundation\Database\Contracts\Table;
use Traversable;

/**
 * Ordered collection of database tables managed by Foundation.
 *
 * @implements IteratorAggregate<int, Table>
 */
final class Collection implements IteratorAggregate
{
	/**
	 * @var list<Table>
	 */
	private array $tables = [];

	/**
	 * @param iterable<Table> $tables
	 */
	public function __construct(
		private readonly Schema $schema,
		iterable $tables = []
	) {
		foreach ($tables as $table) {
			$this->add($table);
		}
	}

	public function add(Table ...$tables): void {
		foreach ($tables as $table) {
			$this->tables[] = $table;
		}
	}

	/**
	 * @return list<Table>
	 */
	public function all(): array {
		return $this->tables;
	}

	public function create(): void {
		foreach ($this->tables as $table) {
			if (! $this->schema->hasTable($table)) {
				$this->schema->createOrUpdate($table);
			}
		}
	}

	public function drop(): void {
		foreach ($this->tables as $table) {
			$this->schema->drop($table);
		}
	}

	public function exists(): bool {
		foreach ($this->tables as $table) {
			if (! $this->schema->hasTable($table)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return Traversable<int, Table>
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->tables);
	}
}
