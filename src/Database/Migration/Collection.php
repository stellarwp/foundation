<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use ArrayIterator;
use IteratorAggregate;
use StellarWP\Foundation\Database\Contracts\Migration;
use StellarWP\Foundation\Database\Migration\Exceptions\DuplicateMigration;
use StellarWP\Foundation\Database\Migration\Exceptions\InvalidMigrationId;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;
use Traversable;

/**
 * Collection of migrations ordered by their byte-exact identifiers.
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
	 * Create a globally ordered collection from configured migration contributions.
	 *
	 * @param iterable<Migration> $migrations
	 *
	 * @throws DuplicateMigration When a migration identifier is already registered.
	 * @throws InvalidMigrationId When a migration identifier cannot be stored safely.
	 */
	public function __construct(
		iterable $migrations = []
	) {
		$this->addMigrations($migrations);
	}

	/**
	 * Add migrations and retain ascending byte-exact identifier order.
	 *
	 * @throws DuplicateMigration When a migration identifier is already registered.
	 * @throws InvalidMigrationId When a migration identifier cannot be stored safely.
	 */
	public function add(Migration ...$migrations): void {
		$this->addMigrations($migrations);
	}

	/**
	 * Validate and atomically merge one migration contribution into the ordered collection.
	 *
	 * @param iterable<Migration> $migrations
	 *
	 * @throws DuplicateMigration When a migration identifier is already registered.
	 * @throws InvalidMigrationId When a migration identifier cannot be stored safely.
	 */
	private function addMigrations(iterable $migrations): void {
		$additions = [];

		foreach ($migrations as $migration) {
			$id = (new Id($migration->id()))->value;

			if (isset($this->migrations[$id]) || isset($additions[$id])) {
				throw DuplicateMigration::forMigration($id);
			}

			$additions[$id] = $migration;
		}

		foreach ($additions as $id => $migration) {
			$this->migrations[$id] = $migration;
		}

		uksort(
			$this->migrations,
			static fn (string $left, string $right): int => strcmp($left, $right)
		);
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
	 * Iterate over migrations keyed by their byte-exact identifier.
	 *
	 * @return Traversable<string, Migration>
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->migrations);
	}
}
