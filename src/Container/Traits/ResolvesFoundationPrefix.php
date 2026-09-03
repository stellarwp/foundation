<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Traits;

use InvalidArgumentException;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Resolves the application prefix used to scope Foundation resources.
 *
 * @mixin Provider
 *
 * @phpstan-require-extends Provider
 */
trait ResolvesFoundationPrefix
{
	/**
	 * @throws InvalidArgumentException When the configured prefix is not lowercase kebab-case.
	 */
	private function foundationPrefix(): string {
		$prefix = $this->config->get('foundation.prefix');

		if ($prefix === null || $prefix === '') {
			return 'nx';
		}

		if (! is_string($prefix) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $prefix) !== 1) {
			throw new InvalidArgumentException('The Foundation prefix must use lowercase kebab-case, for example "your-plugin".');
		}

		return $prefix;
	}
}
