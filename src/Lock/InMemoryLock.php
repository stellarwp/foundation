<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Random\RandomException;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\Contracts\Lock;

/**
 * Process-local lock implementation useful for tests and single-process work.
 *
 * This implementation is not a cross-request or distributed lock. Use a
 * persistent implementation, such as a future database-backed lock, when
 * multiple PHP processes must coordinate ownership.
 */
final class InMemoryLock implements Lock
{
	/**
	 * @var array<string, LockToken>
	 */
	private array $locks = [];

	public function __construct(
		private readonly Clock $clock = new SystemClock()
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RandomException
	 * @throws DateMalformedIntervalStringException
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidTtl($ttl);
		$this->releaseIfExpired($name);

		if (isset($this->locks[$name])) {
			return null;
		}

		$token = new LockToken(
			name: $name,
			owner: bin2hex(random_bytes(16)),
			expiresAt: $this->expiresAt($ttl)
		);

		$this->locks[$name] = $token;

		return $token;
	}

	public function release(LockToken $token): bool {
		$this->releaseIfExpired($token->name);

		if (! isset($this->locks[$token->name]) || ! $this->locks[$token->name]->matches($token)) {
			return false;
		}

		unset($this->locks[$token->name]);

		return true;
	}

	/**
	 * @throws DateMalformedIntervalStringException
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidTtl($ttl);
		$this->releaseIfExpired($token->name);

		if (! isset($this->locks[$token->name]) || ! $this->locks[$token->name]->matches($token)) {
			return null;
		}

		$refreshed = $token->refresh($this->expiresAt($ttl));

		$this->locks[$token->name] = $refreshed;

		return $refreshed;
	}

	public function isAcquired(string $name): bool {
		$this->assertValidName($name);
		$this->releaseIfExpired($name);

		return isset($this->locks[$name]);
	}

	/**
	 * @throws DateMalformedIntervalStringException
	 * @throws InvalidArgumentException
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

	private function releaseIfExpired(string $name): void {
		if (! isset($this->locks[$name])) {
			return;
		}

		if (! $this->locks[$name]->isExpired($this->clock->now())) {
			return;
		}

		unset($this->locks[$name]);
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function assertValidName(string $name): void {
		if (trim($name) === '') {
			throw new InvalidArgumentException('Lock name cannot be empty.');
		}
	}
}
