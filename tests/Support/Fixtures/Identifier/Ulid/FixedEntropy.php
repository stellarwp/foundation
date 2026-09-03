<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid;

use StellarWP\Foundation\Identifier\Ulid\Contracts\Entropy;

final class FixedEntropy implements Entropy
{
	/**
	 * @var int[]
	 */
	private array $requestedLengths = [];

	public function __construct(
		private readonly string $bytes
	) {
	}

	public function bytes(int $length): string {
		$this->requestedLengths[] = $length;

		return $this->bytes;
	}

	/**
	 * @return int[]
	 */
	public function requestedLengths(): array {
		return $this->requestedLengths;
	}
}
