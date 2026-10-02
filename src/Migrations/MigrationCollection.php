<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations;

use InvalidArgumentException;
use StellarWP\Foundation\Migrations\Contracts\Migration;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationRegistration;

/**
 * Contributed migrations validated once and held in byte-exact identifier order.
 *
 * @internal
 *
 * The provider supplies the contributions; the planner and runner share this one view of them.
 */
final class MigrationCollection
{
	/**
	 * @var array<string, Migration>
	 */
	private array $migrations = [];

	/**
	 * Validate provider contributions and sort their persistent identities.
	 *
	 * @param iterable<MigrationRegistration> $migrations
	 *
	 * @throws InvalidArgumentException      When an identity is duplicated.
	 * @throws Exceptions\InvalidMigrationId When an identity is invalid.
	 */
	public function __construct(
		iterable $migrations = [],
	) {
		foreach ($migrations as $registration) {
			$this->add($registration);
		}

		ksort($this->migrations, SORT_STRING);
	}

	/**
	 * Validate and store a contribution without allowing duplicate identities.
	 */
	private function add(MigrationRegistration $registration): void {
		$id = (new ValueObjects\Id($registration->id))->value;

		if (isset($this->migrations[$id])) {
			throw new InvalidArgumentException('Duplicate migration identity: ' . $id);
		}

		$this->migrations[$id] = $registration->migration;
	}

	/**
	 * Return migrations keyed by their persistent identities.
	 *
	 * @return array<string, Migration> Keyed by ID; PHP may store numeric IDs as integer keys, so use ids() for strings.
	 */
	public function all(): array {
		return $this->migrations;
	}

	/**
	 * Return the globally ordered identities as strings.
	 *
	 * @return list<string>
	 */
	public function ids(): array {
		return array_map('strval', array_keys($this->migrations));
	}

	/**
	 * Check whether an identity was contributed.
	 */
	public function has(string $id): bool {
		return isset($this->migrations[$id]);
	}

	/**
	 * Resolve a registered migration by identity.
	 */
	public function get(string $id): Migration {
		return $this->migrations[$id] ?? throw new InvalidArgumentException('Unknown migration: ' . $id);
	}
}
