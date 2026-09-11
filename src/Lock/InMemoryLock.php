<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use InvalidArgumentException;
use StellarWP\Foundation\Lock\Contracts\Clock;
use StellarWP\Foundation\Lock\Contracts\Lock;
use StellarWP\Foundation\Lock\Traits\CalculatesLockExpiration;
use StellarWP\Foundation\Lock\Traits\GeneratesLockOwner;
use StellarWP\Foundation\Lock\Traits\ValidatesLockTtl;

/**
 * Process-local lock implementation useful for tests and single-process work.
 *
 * This implementation is not a cross-request or distributed lock. Use a
 * persistent Foundation lock implementation when multiple PHP processes
 * must coordinate ownership.
 */
final class InMemoryLock implements Lock
{
	use CalculatesLockExpiration;
	use GeneratesLockOwner;
	use ValidatesLockTtl;

	/**
	 * @var array<string, LockToken>
	 */
	private array $locks = [];

	public function __construct(
		private readonly Clock $clock
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function acquire(string $name, int $ttl): ?LockToken {
		$this->assertValidName($name);
		$this->assertValidLockTtl($ttl);
		$this->releaseIfExpired($name);

		if (isset($this->locks[$name])) {
			return null;
		}

		$token = new LockToken(
			name: $name,
			owner: $this->generateLockOwner(),
			expiresAt: $this->calculateLockExpiration($this->clock->now(), $ttl)
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
	 * {@inheritDoc}
	 */
	public function refresh(LockToken $token, int $ttl): ?LockToken {
		$this->assertValidLockTtl($ttl);
		$this->releaseIfExpired($token->name);

		if (! isset($this->locks[$token->name]) || ! $this->locks[$token->name]->matches($token)) {
			return null;
		}

		$refreshed = $token->withExpiration(
			$this->calculateLockExpiration($this->clock->now(), $ttl)
		);

		$this->locks[$token->name] = $refreshed;

		return $refreshed;
	}

	public function isAcquired(string $name): bool {
		$this->assertValidName($name);
		$this->releaseIfExpired($name);

		return isset($this->locks[$name]);
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
