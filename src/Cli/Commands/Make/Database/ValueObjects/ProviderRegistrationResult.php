<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects;

/**
 * Describes the outcome of checking or updating a generated database provider.
 */
final readonly class ProviderRegistrationResult
{
	private function __construct(
		private bool $updated,
		private bool $alreadyRegistered,
		private ?string $failureReason
	) {
	}

	/**
	 * Describe a provider that can accept the requested registration.
	 */
	public static function ready(): self {
		return new self(false, false, null);
	}

	/**
	 * Describe a provider that was updated successfully.
	 */
	public static function updated(): self {
		return new self(true, false, null);
	}

	/**
	 * Describe a provider that already contains the requested registration.
	 */
	public static function alreadyRegistered(): self {
		return new self(false, true, null);
	}

	/**
	 * Describe a provider file that does not exist or cannot be read.
	 */
	public static function notFound(): self {
		return self::failure('file does not exist or is not readable');
	}

	/**
	 * Describe a provider file whose contents could not be read.
	 */
	public static function readFailed(): self {
		return self::failure('file could not be read');
	}

	/**
	 * Describe a provider file that cannot be replaced safely.
	 */
	public static function notWritable(): self {
		return self::failure('file is not writable');
	}

	/**
	 * Describe a provider without a supported migration registration point.
	 */
	public static function missingAnchor(): self {
		return self::failure('file does not contain a generated database provider registration point');
	}

	/**
	 * Describe a provider without the expected table registration marker.
	 */
	public static function missingMarker(): self {
		return self::failure('file does not contain the generated database provider markers');
	}

	/**
	 * Describe a provider whose imports conflict with the generated class name.
	 */
	public static function importCollision(): self {
		return self::failure('another class declaration or import uses the same short class name');
	}

	/**
	 * Describe a provider that could not be parsed as PHP.
	 */
	public static function parseFailed(): self {
		return self::failure('file could not be parsed as PHP');
	}

	/**
	 * Describe a provider whose updated contents could not be written.
	 */
	public static function writeFailed(): self {
		return self::failure('file could not be written');
	}

	/**
	 * Determine whether the registration check or update completed successfully.
	 */
	public function succeeded(): bool {
		return $this->failureReason === null;
	}

	/**
	 * Determine whether the operation changed the provider source.
	 */
	public function wasUpdated(): bool {
		return $this->updated;
	}

	/**
	 * Determine whether every requested registration was already present.
	 */
	public function wasAlreadyRegistered(): bool {
		return $this->alreadyRegistered;
	}

	/**
	 * Explain why the registration check or update failed.
	 */
	public function failureReason(): ?string {
		return $this->failureReason;
	}

	/**
	 * Build a failed result with an actionable explanation.
	 */
	private static function failure(string $reason): self {
		return new self(false, false, $reason);
	}
}
