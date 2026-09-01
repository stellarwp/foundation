<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Lock;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Table\Tables\LockTable;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\Traits\GeneratesLockOwner;
use StellarWP\Foundation\Lock\Traits\ValidatesLockTtl;

/**
 * Database-backed lock implementation for WordPress environments.
 */
final readonly class DatabaseLock implements Lock
{
	use GeneratesLockOwner;
	use ValidatesLockTtl;

	public function __construct(
		private Database $database,
		private LockTable $table
	) {
	}

	/**
	 * @throws InvalidArgumentException When the lock name is empty or exceeds 191 bytes, or the TTL is invalid.
	 * @throws LockUnavailableException When ownership cannot be generated or the database cannot determine the result.
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidLockTtl($ttl);

		$owner = $this->generateLockOwner();

		try {
			$table = $this->database->tableName($this->table);

			$this->database->execute(
				'INSERT INTO %i (name, owner, expires_at, created_at, updated_at)
					VALUES (%s, %s, TIMESTAMPADD(SECOND, %d, UTC_TIMESTAMP(6)), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
					ON DUPLICATE KEY UPDATE
						owner = IF(expires_at <= UTC_TIMESTAMP(6), %s, owner),
						updated_at = IF(expires_at <= UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), updated_at),
						expires_at = IF(
							expires_at <= UTC_TIMESTAMP(6),
							TIMESTAMPADD(SECOND, %d, UTC_TIMESTAMP(6)),
							expires_at
						)',
				$table,
				$name,
				$owner,
				$ttl,
				$owner,
				$ttl
			);

			$row = $this->database->row(
				'SELECT expires_at FROM %i
					WHERE name = %s AND owner = %s AND expires_at > UTC_TIMESTAMP(6)
					LIMIT 1',
				$table,
				$name,
				$owner
			);
		} catch (DatabaseException $exception) {
			throw new LockUnavailableException('The database could not determine the lock acquisition result.', 0, $exception);
		}

		if ($row === null) {
			return null;
		}

		return new LockToken(
			name: $name,
			owner: $owner,
			expiresAt: $this->expiration($row)
		);
	}

	/**
	 * @throws LockUnavailableException When the database cannot determine the release result.
	 */
	public function release(LockToken $token): bool {
		try {
			return $this->database->execute(
				'DELETE FROM %i WHERE name = %s AND owner = %s AND expires_at > UTC_TIMESTAMP(6)',
				$this->database->tableName($this->table),
				$token->name,
				$token->owner
			) > 0;
		} catch (DatabaseException $exception) {
			throw new LockUnavailableException('The database could not determine the lock release result.', 0, $exception);
		}
	}

	/**
	 * @throws InvalidArgumentException When the TTL is invalid.
	 * @throws LockUnavailableException When the database cannot determine the refresh result.
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidLockTtl($ttl);

		try {
			$table = $this->database->tableName($this->table);

			$this->database->execute(
				'UPDATE %i SET expires_at = TIMESTAMPADD(SECOND, %d, UTC_TIMESTAMP(6)), updated_at = UTC_TIMESTAMP(6)
					WHERE name = %s AND owner = %s AND expires_at > UTC_TIMESTAMP(6)',
				$table,
				$ttl,
				$token->name,
				$token->owner
			);

			$row = $this->database->row(
				'SELECT expires_at FROM %i WHERE name = %s AND owner = %s AND expires_at > UTC_TIMESTAMP(6) LIMIT 1',
				$table,
				$token->name,
				$token->owner
			);
		} catch (DatabaseException $exception) {
			throw new LockUnavailableException('The database could not determine the lock refresh result.', 0, $exception);
		}

		if ($row === null) {
			return null;
		}

		return $token->withExpiration($this->expiration($row));
	}

	/**
	 * @throws InvalidArgumentException When the lock name is empty or exceeds 191 bytes.
	 * @throws LockUnavailableException When the database cannot determine whether the lock exists.
	 */
	public function isAcquired(string $name): bool {
		$this->assertValidName($name);

		try {
			return $this->database->row(
				'SELECT name FROM %i WHERE name = %s AND expires_at > UTC_TIMESTAMP(6) LIMIT 1',
				$this->database->tableName($this->table),
				$name
			) !== null;
		} catch (DatabaseException $exception) {
			throw new LockUnavailableException('The database could not determine whether the lock exists.', 0, $exception);
		}
	}

	/**
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
