<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use ArrayIterator;
use IteratorAggregate;
use StellarWP\Foundation\Database\Contracts\Migration;
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

	public function add(Migration ...$migrations): void {
		foreach ($migrations as $migration) {
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
