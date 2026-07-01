<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Represents ownership of a named lock until its expiration time.
 */
final readonly class LockToken
{
	/**
	 * @param string            $name      The lock name this token owns.
	 * @param string            $owner     Opaque owner identifier used to prove ownership.
	 * @param DateTimeImmutable $expiresAt The instant this token stops owning the lock.
	 */
	public function __construct(
		public string $name,
		public string $owner,
		public DateTimeImmutable $expiresAt
	) {
		if (trim($this->name) === '') {
			throw new InvalidArgumentException('Lock name cannot be empty.');
		}

		if (trim($this->owner) === '') {
			throw new InvalidArgumentException('Lock owner cannot be empty.');
		}
	}

	/**
	 * Determine whether the token has expired at the provided time.
	 *
	 * When no time is provided, the local system clock is used as a convenience
	 * check. Lock implementations should pass their authoritative clock value.
	 */
	public function isExpired(?DateTimeImmutable $now = null): bool {
		return $this->expiresAt <= ($now ?? new DateTimeImmutable());
	}

	/**
	 * Determine whether another token represents the same lock owner.
	 */
	public function matches(self $token): bool {
		return $this->name === $token->name && $this->owner === $token->owner;
	}

	/**
	 * Return a new token for the same owner with a later expiration time.
	 */
	public function refresh(DateTimeImmutable $expiresAt): self {
		return new self(
			name: $this->name,
			owner: $this->owner,
			expiresAt: $expiresAt
		);
	}
}
