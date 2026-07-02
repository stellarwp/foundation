<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\ContainerWordPress;

use StellarWP\Foundation\ContainerWordPress\Contracts\Container;
use StellarWP\Foundation\ContainerWordPress\Contracts\Provider;

/**
 * Provider fixture used to verify WordPress-aware provider container typing.
 */
final class WordPressAwareProvider extends Provider
{
	public const string ACTION = 'foundation_fixture_wordpress_aware_provider_ready';

	public function register(): void {
		$this->container->registerOnAction(self::ACTION, FirstProvider::class);
	}

	public function container(): Container {
		return $this->container;
	}
}
