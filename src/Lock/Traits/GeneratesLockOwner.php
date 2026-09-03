<?php declare(strict_types=1);

namespace StellarWP\Foundation\Lock\Traits;

use Random\RandomException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;

/**
 * Generates secure ownership identifiers for lock implementations.
 */
trait GeneratesLockOwner
{
	/**
	 * @throws LockUnavailableException When secure owner entropy is unavailable.
	 */
	private function generateLockOwner(): string {
		try {
			return bin2hex(random_bytes(16));
		} catch (RandomException $exception) {
			throw new LockUnavailableException('Unable to generate a secure lock owner.', 0, $exception);
		}
	}
}
