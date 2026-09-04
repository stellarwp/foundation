<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Container\Configuration;

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Tests\TestCase;

final class ArrayConfigurationTest extends TestCase
{
	public function test_it_reads_nested_values_and_defaults(): void {
		$configuration = new ArrayConfiguration([
			'feature' => [
				'enabled' => true,
				'value'   => null,
			],
		]);

		$this->assertInstanceOf(Configuration::class, $configuration);
		$this->assertTrue($configuration->get('feature.enabled'));
		$this->assertNull($configuration->get('feature.value', 'fallback'));
		$this->assertSame('fallback', $configuration->get('feature.missing', 'fallback'));
	}
}
