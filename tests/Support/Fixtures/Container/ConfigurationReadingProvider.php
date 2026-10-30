<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use StellarWP\Foundation\Container\Contracts\Provider;

final class ConfigurationReadingProvider extends Provider
{
	public const string VALUE = self::class . '.value';

	public function register(): void {
		$this->container->singleton(self::VALUE, $this->config->get('fixture.value'));
	}
}
