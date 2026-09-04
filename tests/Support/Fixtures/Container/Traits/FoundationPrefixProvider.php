<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container\Traits;

use StellarWP\Foundation\Container\Contracts\ConfiguredProvider;
use StellarWP\Foundation\Container\Traits\ResolvesFoundationPrefix;

final class FoundationPrefixProvider extends ConfiguredProvider
{
	use ResolvesFoundationPrefix;

	public function register(): void {
	}

	public function configuredFoundationPrefix(): string {
		return $this->foundationPrefix();
	}
}
