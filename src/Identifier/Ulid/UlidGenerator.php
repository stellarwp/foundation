<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier\Ulid;

use OutOfRangeException;
use RuntimeException;
use StellarWP\Foundation\Identifier\Ulid\Contracts\Entropy;
use StellarWP\Foundation\Identifier\Ulid\Contracts\MillisecondClock;
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator as UlidGeneratorContract;

/**
 * Generates canonical uppercase ULID strings.
 */
final readonly class UlidGenerator implements UlidGeneratorContract
{
	private const string ALPHABET      = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	private const int MAX_TIMESTAMP    = 281_474_976_710_655;
	private const int RANDOM_BYTES     = 10;
	private const int TIMESTAMP_LENGTH = 10;

	public function __construct(
		private Entropy $entropy,
		private MillisecondClock $clock
	) {
	}

	public function generate(): string {
		return $this->encodeTimestamp($this->clock->milliseconds())
			. $this->encodeRandomness($this->entropy->bytes(self::RANDOM_BYTES));
	}

	private function encodeTimestamp(int $timestamp): string {
		if ($timestamp < 0 || $timestamp > self::MAX_TIMESTAMP) {
			throw new OutOfRangeException(sprintf('ULID timestamps must be between 0 and %d milliseconds.', self::MAX_TIMESTAMP));
		}

		$encoded = '';

		for ($i = 0; $i < self::TIMESTAMP_LENGTH; $i++) {
			$encoded   = self::ALPHABET[$timestamp % 32] . $encoded;
			$timestamp = intdiv($timestamp, 32);
		}

		return $encoded;
	}

	private function encodeRandomness(string $bytes): string {
		if (strlen($bytes) !== self::RANDOM_BYTES) {
			throw new RuntimeException(sprintf('ULID generation requires exactly %d random bytes.', self::RANDOM_BYTES));
		}

		$encoded  = '';
		$buffer   = 0;
		$bitCount = 0;

		for ($i = 0; $i < self::RANDOM_BYTES; $i++) {
			$buffer = ($buffer << 8) | ord($bytes[$i]);
			$bitCount += 8;

			while ($bitCount >= 5) {
				$bitCount -= 5;
				$encoded .= self::ALPHABET[($buffer >> $bitCount) & 31];
				$buffer &= (1 << $bitCount) - 1;
			}
		}

		return $encoded;
	}
}
