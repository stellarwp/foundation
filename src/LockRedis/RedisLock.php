<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis;

use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\Lock\Traits\CalculatesLockExpiration;
use StellarWP\Foundation\Lock\Traits\GeneratesLockOwner;
use StellarWP\Foundation\Lock\Traits\ValidatesLockTtl;
use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Coordinates expiring, owner-safe leases through an atomic Redis connection.
 */
final readonly class RedisLock implements Lock
{
	use CalculatesLockExpiration;
	use GeneratesLockOwner;
	use ValidatesLockTtl;

	private const string ACQUIRE_SCRIPT = <<<'LUA'
local acquired = redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', tonumber(ARGV[2]))
if acquired then
	return 1
end
if redis.call('GET', KEYS[1]) == ARGV[1] then
	return 1
end
return 0
LUA;
	private const string RELEASE_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
	return redis.call('DEL', KEYS[1])
end
return 0
LUA;
	private const string REFRESH_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
	return redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
end
return 0
LUA;

	/**
	 * @throws InvalidArgumentException When the Redis key prefix is empty.
	 */
	public function __construct(
		private Connection $connection,
		private Clock $clock,
		private string $prefix
	) {
		if (trim($this->prefix) === '') {
			throw new InvalidArgumentException('Redis lock prefix cannot be empty.');
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidLockTtl($ttl);

		$startedAt = $this->clock->now();
		$expiresAt = $this->calculateLockExpiration($startedAt, $ttl);
		$owner     = $this->generateLockOwner();
		$result    = $this->connection->evaluate(
			self::ACQUIRE_SCRIPT,
			[$this->key($name)],
			[$owner, $ttl]
		);

		return match ($result) {
			0       => null,
			1       => new LockToken(
				name: $name,
				owner: $owner,
				expiresAt: $expiresAt
			),
			default => throw new LockUnavailableException('Redis returned an unexpected acquisition result.'),
		};
	}

	/**
	 * @throws LockUnavailableException When Redis cannot determine the release result.
	 */
	public function release(LockToken $token): bool {
		return match ($this->connection->evaluate(
			self::RELEASE_SCRIPT,
			[$this->key($token->name)],
			[$token->owner]
		)) {
			0       => false,
			1       => true,
			default => throw new LockUnavailableException('Redis returned an unexpected release result.'),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidLockTtl($ttl);

		$startedAt = $this->clock->now();
		$expiresAt = $this->calculateLockExpiration($startedAt, $ttl);
		$result    = $this->connection->evaluate(
			self::REFRESH_SCRIPT,
			[$this->key($token->name)],
			[$token->owner, $ttl]
		);

		return match ($result) {
			0       => null,
			1       => $token->withExpiration($expiresAt),
			default => throw new LockUnavailableException('Redis returned an unexpected refresh result.'),
		};
	}

	/**
	 * @throws InvalidArgumentException When the lock name is empty.
	 * @throws LockUnavailableException When Redis cannot determine whether the lock exists.
	 */
	public function isAcquired(string $name): bool {
		$this->assertValidName($name);

		return $this->connection->exists($this->key($name));
	}

	private function key(string $name): string {
		return $this->prefix . $name;
	}

	/**
	 * @throws InvalidArgumentException When the lock name is empty.
	 */
	private function assertValidName(string $name): void {
		if (trim($name) === '') {
			throw new InvalidArgumentException('Lock name cannot be empty.');
		}
	}
}
