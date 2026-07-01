<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use ArrayIterator;
use IteratorAggregate;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use Traversable;

/**
 * Ordered collection of migrations registered with the database package.
 *
 * @implements IteratorAggregate<int, Migration>
 */
final class Collection implements IteratorAggregate
{
	/**
	 * @var list<Migration>
	 */
	private array $migrations = [];

	/**
	 * @param iterable<Migration> $migrations
	 */
	public function __construct(
		iterable $migrations = []
	) {
		foreach ($migrations as $migration) {
			$this->add($migration);
		}
	}

	/**
	 * @throws DuplicateMigration
	 */
	public function add(Migration ...$migrations): void {
		foreach ($migrations as $migration) {
			foreach ($this->migrations as $registered) {
				if ($registered->id() === $migration->id()) {
					throw DuplicateMigration::forMigration($migration->id());
				}
			}

			$this->migrations[] = $migration;
		}
	}

	/**
	 * @return list<Migration>
	 */
	public function all(): array {
		return $this->migrations;
	}

	/**
	 * @return Traversable<int, Migration>
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->migrations);
	}
}
