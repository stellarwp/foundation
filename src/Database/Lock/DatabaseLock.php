<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Lock;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\Traits\GeneratesLockOwner;
use StellarWP\Foundation\Lock\Traits\ValidatesLockTtl;
use Throwable;

/**
 * Database-backed lock implementation for WordPress environments.
 */
final readonly class DatabaseLock implements Lock
{
	use GeneratesLockOwner;
	use ValidatesLockTtl;

	/**
	 * Create a lock backend using the configured WordPress lock table.
	 */
	public function __construct(
		private Connection $db,
		private LockTable $table
	) {
	}

	/**
	 * Create lock storage during application activation or deployment.
	 *
	 * @throws Exception When storage cannot be inspected or created.
	 */
	public function initialize(): void {
		$manager = $this->db->createSchemaManager();

		if ($manager->tablesExist([$this->table->name()])) {
			return;
		}
		$table = Table::editor()->setUnquotedName($this->table->name())->setOptions(['engine' => 'InnoDB'])
			->setColumns(
				Column::editor()->setUnquotedName('name')->setTypeName(Types::BINARY)->setLength(191)->setNotNull(true)->create(),
				Column::editor()->setUnquotedName('owner')->setTypeName(Types::BINARY)->setLength(64)->setNotNull(true)->create(),
				Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::DATETIME_MUTABLE)->setColumnDefinition('DATETIME(6) NOT NULL')->create(),
				Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_MUTABLE)->setColumnDefinition('DATETIME(6) NOT NULL')->create(),
				Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::DATETIME_MUTABLE)->setColumnDefinition('DATETIME(6) NOT NULL')->create(),
			)
			->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('name')->create())->create();

		try {
			$manager->createTable($table);
		} catch (\Doctrine\DBAL\Exception\TableExistsException) {
			// Another activation created the same configured storage concurrently.
		}
	}

	/**
	 * Acquire an unexpired lock or return null when another owner holds it.
	 *
	 * @throws InvalidArgumentException When the lock name is empty or exceeds 191 bytes, or the TTL is invalid.
	 * @throws LockUnavailableException When ownership cannot be generated or the database cannot determine the result.
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidLockTtl($ttl);

		$owner = $this->generateLockOwner();

		try {
			$table = $this->table->quotedName();

			$this->db->executeStatement(
				"INSERT INTO {$table} (name, owner, expires_at, created_at, updated_at)
					VALUES (?, ?, TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
					ON DUPLICATE KEY UPDATE
						owner = IF(expires_at <= UTC_TIMESTAMP(6), ?, owner),
						updated_at = IF(expires_at <= UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), updated_at),
						expires_at = IF(
							expires_at <= UTC_TIMESTAMP(6),
							TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)),
							expires_at
						)",
				[$name, $owner, $ttl, $owner, $ttl]
			);

			$row = $this->db->fetchAssociative(
				"SELECT expires_at FROM {$table}
					WHERE name = ? AND owner = ? AND expires_at > UTC_TIMESTAMP(6)
					LIMIT 1",
				[$name, $owner]
			);
		} catch (Throwable $exception) {
			throw new LockUnavailableException('The database could not determine the lock acquisition result.', 0, $exception);
		}

		if ($row === false) {
			return null;
		}

		return new LockToken(
			name: $name,
			owner: $owner,
			expiresAt: $this->expiration($row)
		);
	}

	/**
	 * Release a lock only when its owner and unexpired token still match.
	 *
	 * @throws LockUnavailableException When the database cannot determine the release result.
	 */
	public function release(LockToken $token): bool {
		try {
			return $this->db->executeStatement(
				'DELETE FROM ' . $this->table->quotedName() . ' WHERE name = ? AND owner = ? AND expires_at > UTC_TIMESTAMP(6)',
				[$token->name, $token->owner]
			) > 0;
		} catch (Throwable $exception) {
			throw new LockUnavailableException('The database could not determine the lock release result.', 0, $exception);
		}
	}

	/**
	 * Extend an owned lock and return its updated expiration token.
	 *
	 * @throws InvalidArgumentException When the TTL is invalid.
	 * @throws LockUnavailableException When the database cannot determine the refresh result.
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidLockTtl($ttl);

		try {
			$table = $this->table->quotedName();

			$this->db->executeStatement(
				"UPDATE {$table} SET expires_at = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), updated_at = UTC_TIMESTAMP(6)
					WHERE name = ? AND owner = ? AND expires_at > UTC_TIMESTAMP(6)",
				[$ttl, $token->name, $token->owner]
			);

			$row = $this->db->fetchAssociative(
				"SELECT expires_at FROM {$table} WHERE name = ? AND owner = ? AND expires_at > UTC_TIMESTAMP(6) LIMIT 1",
				[$token->name, $token->owner]
			);
		} catch (Throwable $exception) {
			throw new LockUnavailableException('The database could not determine the lock refresh result.', 0, $exception);
		}

		if ($row === false) {
			return null;
		}

		return $token->withExpiration($this->expiration($row));
	}

	/**
	 * Determine whether an unexpired lock exists for the supplied name.
	 *
	 * @throws InvalidArgumentException When the lock name is empty or exceeds 191 bytes.
	 * @throws LockUnavailableException When the database cannot determine whether the lock exists.
	 */
	public function isAcquired(string $name): bool {
		$this->assertValidName($name);

		try {
			return $this->db->fetchAssociative(
				'SELECT name FROM ' . $this->table->quotedName() . ' WHERE name = ? AND expires_at > UTC_TIMESTAMP(6) LIMIT 1',
				[$name]
			) !== false;
		} catch (Throwable $exception) {
			throw new LockUnavailableException('The database could not determine whether the lock exists.', 0, $exception);
		}
	}

	/**
	 * Reject lock names that cannot be stored safely in the lock table.
	 *
	 * @throws InvalidArgumentException When the lock name is empty or exceeds 191 bytes.
	 */
	private function assertValidName(string $name): void {
		if (trim($name) === '') {
			throw new InvalidArgumentException('Lock name cannot be empty.');
		}

		if (strlen($name) > 191) {
			throw new InvalidArgumentException('A database lock name cannot exceed 191 bytes.');
		}
	}

	/**
	 * Convert a database expiration value into an immutable UTC timestamp.
	 *
	 * @param array{expires_at?: mixed} $row
	 *
	 * @throws LockUnavailableException When the database returns an invalid expiration.
	 */
	private function expiration(array $row): DateTimeImmutable {
		$expiration = $row['expires_at'] ?? null;

		if (! is_string($expiration) || $expiration === '') {
			throw new LockUnavailableException('The database returned an invalid lock expiration.');
		}

		try {
			return new DateTimeImmutable($expiration, new DateTimeZone('UTC'));
		} catch (DateMalformedStringException $exception) {
			throw new LockUnavailableException('The database returned an invalid lock expiration.', 0, $exception);
		}
	}
}
