<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\LockRedis;

use StellarWP\Foundation\LockRedis\Contracts\Connection;

/**
 * Records Redis lock operations while returning configurable results.
 */
final class RecordingConnection implements Connection
{
	public int $evaluateResult = 1;

	public bool $existsResult = false;

	/**
	 * @var list<array{script: string, keys: list<string>, arguments: list<string|int>}>
	 */
	public array $evaluateCalls = [];

	/**
	 * @var list<string>
	 */
	public array $existsCalls = [];

	public function evaluate(string $script, array $keys, array $arguments): int {
		$this->evaluateCalls[] = [
			'script'    => $script,
			'keys'      => $keys,
			'arguments' => $arguments,
		];

		return $this->evaluateResult;
	}

	public function exists(string $key): bool {
		$this->existsCalls[] = $key;

		return $this->existsResult;
	}
}
