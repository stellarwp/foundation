<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Lock;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Random\RandomException;
use StellarWP\Foundation\Database\Contracts\Database;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\SystemClock;

/**
 * Database-backed lock implementation for WordPress environments.
 */
final readonly class DatabaseLock implements Lock
{
	public function __construct(
		private Database $database,
		private string $table,
		private Clock $clock = new SystemClock()
	) {
	}

	/**
	 * @throws DateMalformedIntervalStringException
	 * @throws RandomException
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidTtl($ttl);

		$owner     = bin2hex(random_bytes(16));
		$now       = $this->format($this->clock->now());
		$expiresAt = $this->expiresAt($ttl);

		$this->database->execute(
			'INSERT INTO %i (name, owner, expires_at, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					owner = IF(expires_at <= %s, VALUES(owner), owner),
					updated_at = IF(expires_at <= %s, VALUES(updated_at), updated_at),
					expires_at = IF(expires_at <= %s, VALUES(expires_at), expires_at)',
			$this->database->tableName($this->table),
			$name,
			$owner,
			$this->format($expiresAt),
			$now,
			$now,
			$now,
			$now,
			$now
		);

		$row = $this->database->row(
			'SELECT owner, expires_at FROM %i WHERE name = %s LIMIT 1',
			$this->database->tableName($this->table),
			$name
		);

		if ($row === null || ($row['owner'] ?? '') !== $owner) {
			return null;
		}

		return new LockToken(
			name: $name,
			owner: $owner,
			expiresAt: new DateTimeImmutable((string) $row['expires_at'])
		);
	}

	public function release(LockToken $token): bool {
		return $this->database->execute(
			'DELETE FROM %i WHERE name = %s AND owner = %s AND expires_at > %s',
			$this->database->tableName($this->table),
			$token->name,
			$token->owner,
			$this->format($this->clock->now())
		) > 0;
	}

	/**
	 * @throws DateMalformedIntervalStringException
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidTtl($ttl);

		$expiresAt = $this->expiresAt($ttl);
		$updated   = $this->database->execute(
			'UPDATE %i SET expires_at = %s, updated_at = %s WHERE name = %s AND owner = %s AND expires_at > %s',
			$this->database->tableName($this->table),
			$this->format($expiresAt),
			$this->format($this->clock->now()),
			$token->name,
			$token->owner,
			$this->format($this->clock->now())
		);

		if ($updated < 1) {
			return null;
		}

		return $token->refresh($expiresAt);
	}

	public function isAcquired(string $name): bool {
		$this->assertValidName($name);

		return $this->database->row(
			'SELECT name FROM %i WHERE name = %s AND expires_at > %s LIMIT 1',
			$this->database->tableName($this->table),
			$name,
			$this->format($this->clock->now())
		) !== null;
	}

	/**
	 * @throws DateMalformedIntervalStringException
	 */
	private function expiresAt(int $ttl): DateTimeImmutable {
		$this->assertValidTtl($ttl);

		return $this->clock->now()->add(new DateInterval(sprintf('PT%dS', $ttl)));
	}

	private function assertValidTtl(int $ttl): void {
		if ($ttl < 1) {
			throw new InvalidArgumentException('Lock TTL must be greater than zero seconds.');
		}
	}

	private function assertValidName(string $name): void {
		if (trim($name) === '') {
			throw new InvalidArgumentException('Lock name cannot be empty.');
		}
	}

	private function format(DateTimeImmutable $date): string {
		return $date->format('Y-m-d H:i:s');
	}
}
