<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\Lock\LockToken;
use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Coordinates expiring, owner-safe leases through an atomic Redis connection.
 */
final readonly class RedisLock implements Lock
{
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
	 * @throws InvalidArgumentException             When the lock name is empty or the TTL is invalid.
	 * @throws LockUnavailableException             When Redis cannot determine the acquisition result.
	 * @throws DateMalformedIntervalStringException When PHP cannot represent the requested TTL.
	 * @throws \Random\RandomException              When a secure owner token cannot be generated.
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidTtl($ttl);

		$startedAt = $this->clock->now();
		$expiresAt = $this->expiresAt($startedAt, $ttl);
		$owner     = bin2hex(random_bytes(16));
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
	 * @throws InvalidArgumentException             When the TTL is invalid.
	 * @throws LockUnavailableException             When Redis cannot determine the refresh result.
	 * @throws DateMalformedIntervalStringException When PHP cannot represent the requested TTL.
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidTtl($ttl);

		$startedAt = $this->clock->now();
		$expiresAt = $this->expiresAt($startedAt, $ttl);
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

	/**
	 * @throws DateMalformedIntervalStringException When PHP cannot represent the requested TTL.
	 */
	private function expiresAt(DateTimeImmutable $startedAt, int $ttl): DateTimeImmutable {
		return $startedAt->add(new DateInterval(sprintf('PT%dS', $ttl)));
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

	/**
	 * @throws InvalidArgumentException When the TTL is less than one second.
	 */
	private function assertValidTtl(int $ttl): void {
		if ($ttl < 1) {
			throw new InvalidArgumentException('Lock TTL must be greater than zero seconds.');
		}
	}
}
