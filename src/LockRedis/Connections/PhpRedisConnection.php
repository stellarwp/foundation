<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis\Connections;

use Redis;
use RedisException;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Adapts the PhpRedis extension to Foundation's Redis lock operations.
 */
final readonly class PhpRedisConnection implements Connection
{
	public function __construct(
		private Redis $redis
	) {
	}

	/**
	 * @throws LockUnavailableException When PhpRedis cannot evaluate the script.
	 */
	public function evaluate(string $script, array $keys, array $arguments): int {
		$result = $this->command(
			static fn (Redis $redis): mixed => $redis->eval($script, [...$keys, ...$arguments], count($keys))
		);

		if (! is_int($result)) {
			throw new LockUnavailableException('PhpRedis returned an unexpected EVAL response.');
		}

		return $result;
	}

	/**
	 * @throws LockUnavailableException When PhpRedis cannot determine whether the key exists.
	 */
	public function exists(string $key): bool {
		$result = $this->command(
			static fn (Redis $redis): Redis|int|bool => $redis->exists($key)
		);

		if (! is_int($result)) {
			throw new LockUnavailableException('PhpRedis returned an unexpected EXISTS response.');
		}

		return $result > 0;
	}

	/**
	 * @template T
	 *
	 * @param callable(Redis): T $command
	 *
	 * @throws LockUnavailableException When PhpRedis reports an exception or command error.
	 *
	 * @return T
	 */
	private function command(callable $command): mixed {
		try {
			$this->redis->clearLastError();
			$result = $command($this->redis);
			$error  = $this->redis->getLastError();
		} catch (RedisException $exception) {
			throw new LockUnavailableException('PhpRedis could not execute the lock operation.', 0, $exception);
		}

		if ($error !== null) {
			throw new LockUnavailableException(sprintf('PhpRedis could not execute the lock operation: %s', $error));
		}

		return $result;
	}
}
