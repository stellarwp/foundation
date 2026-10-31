<?php declare(strict_types=1);

namespace StellarWP\Foundation\LockRedis\Connections;

use Predis\ClientInterface;
use Predis\PredisException;
use Predis\Response\ErrorInterface;
use StellarWP\Foundation\Lock\Exceptions\LockUnavailableException;
use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Adapts a Predis client to Foundation's Redis lock operations.
 */
final readonly class PredisConnection implements Connection
{
	public function __construct(
		private ClientInterface $redis
	) {
	}

	/**
	 * @throws LockUnavailableException When Predis cannot evaluate the script.
	 */
	public function evaluate(string $script, array $keys, array $arguments): int {
		$result = $this->command('EVAL', [$script, count($keys), ...$keys, ...$arguments]);

		if (! is_int($result)) {
			throw new LockUnavailableException('Predis returned an unexpected EVAL response.');
		}

		return $result;
	}

	/**
	 * @throws LockUnavailableException When Predis cannot determine whether the key exists.
	 */
	public function exists(string $key): bool {
		$result = $this->command('EXISTS', [$key]);

		if (! is_int($result)) {
			throw new LockUnavailableException('Predis returned an unexpected EXISTS response.');
		}

		return $result > 0;
	}

	/**
	 * @param list<string|int> $arguments
	 *
	 * @throws LockUnavailableException When Predis reports an exception or command error.
	 */
	private function command(string $name, array $arguments): mixed {
		try {
			$result = $this->redis->executeCommand($this->redis->createCommand($name, $arguments));
		} catch (PredisException $exception) {
			throw new LockUnavailableException('Predis could not execute the lock operation.', 0, $exception);
		}

		if ($result instanceof ErrorInterface) {
			throw new LockUnavailableException(sprintf('Predis could not execute the lock operation: %s', $result->getMessage()));
		}

		return $result;
	}
}
