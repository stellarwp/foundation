<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use ArrayIterator;
use IteratorAggregate;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use Traversable;

/**
 * Ordered collection of migrations registered with the database package.
 *
 * @implements IteratorAggregate<string, Migration>
 */
final class Collection implements IteratorAggregate
{
	/**
	 * @var array<string, Migration>
	 */
	private array $migrations = [];

	/**
	 * @param iterable<Migration> $migrations
	 *
	 * @throws DuplicateMigration When a migration identifier is already registered.
	 * @throws InvalidMigrationId When a migration identifier cannot be stored safely.
	 */
	public function __construct(
		iterable $migrations = []
	) {
		foreach ($migrations as $migration) {
			$this->add($migration);
		}
	}

	/**
	 * @throws DuplicateMigration When a migration identifier is already registered.
	 * @throws InvalidMigrationId When a migration identifier cannot be stored safely.
	 */
	public function add(Migration ...$migrations): void {
		foreach ($migrations as $migration) {
			$id = (new Id($migration->id()))->value;

			if (isset($this->migrations[$id])) {
				throw DuplicateMigration::forMigration($id);
			}

			$this->migrations[$id] = $migration;
		}
	}

	/**
	 * Return all migrations keyed by their byte-exact identifier.
	 *
	 * @return array<string, Migration>
	 */
	public function all(): array {
		return $this->migrations;
	}

	/**
	 * Return all migrations as an ordered list.
	 *
	 * @return list<Migration>
	 */
	public function values(): array {
		return array_values($this->migrations);
	}

	/**
	 * @return Traversable<string, Migration>
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->migrations);
	}
}
