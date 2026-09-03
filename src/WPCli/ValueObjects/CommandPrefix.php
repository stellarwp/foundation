<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli\ValueObjects;

use InvalidArgumentException;

/**
 * Configured root command prefix shared by Foundation WP-CLI commands.
 */
final readonly class CommandPrefix
{
	/**
	 * @throws InvalidArgumentException When the prefix is empty or contains surrounding whitespace.
	 */
	public function __construct(
		public string $value
	) {
		if ($this->value === '' || trim($this->value) !== $this->value) {
			throw new InvalidArgumentException('The WP-CLI command prefix cannot be empty or contain surrounding whitespace.');
		}
	}
}
