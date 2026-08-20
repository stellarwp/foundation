<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container\Traits;

use Adbar\Dot;
use InvalidArgumentException;
use StellarWP\Foundation\Tests\Support\Fixtures\Container\Traits\FoundationPrefixProvider;
use StellarWP\Foundation\Tests\TestCase;

final class ResolvesFoundationPrefixTest extends TestCase
{
	public function test_it_returns_the_default_without_a_configured_prefix(): void {
		$provider = new FoundationPrefixProvider($this->container, new Dot());

		$this->assertSame('nx', $provider->configuredFoundationPrefix());
	}

	public function test_it_returns_the_default_for_an_empty_prefix(): void {
		$provider = new FoundationPrefixProvider($this->container, new Dot([
			'foundation' => [
				'prefix' => '',
			],
		]));

		$this->assertSame('nx', $provider->configuredFoundationPrefix());
	}

	public function test_it_provides_the_configured_foundation_prefix(): void {
		$provider = new FoundationPrefixProvider($this->container, new Dot([
			'foundation' => [
				'prefix' => 'your-plugin',
			],
		]));

		$this->assertSame('your-plugin', $provider->configuredFoundationPrefix());
	}

	public function test_it_rejects_an_invalid_foundation_prefix(): void {
		$provider = new FoundationPrefixProvider($this->container, new Dot([
			'foundation' => [
				'prefix' => 'Your Plugin',
			],
		]));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lowercase kebab-case');

		$provider->configuredFoundationPrefix();
	}

	public function test_it_rejects_a_non_string_prefix(): void {
		$provider = new FoundationPrefixProvider($this->container, new Dot([
			'foundation' => [
				'prefix' => ['your-plugin'],
			],
		]));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('lowercase kebab-case');

		$provider->configuredFoundationPrefix();
	}
}
