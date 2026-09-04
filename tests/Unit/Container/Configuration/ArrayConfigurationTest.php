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

	public function test_it_determines_whether_nested_values_exist(): void {
		$configuration = new ArrayConfiguration([
			'feature' => [
				'enabled' => true,
				'value'   => null,
			],
		]);

		$this->assertTrue($configuration->has('feature.enabled'));
		$this->assertTrue($configuration->has('feature.value'));
		$this->assertFalse($configuration->has('feature.missing'));
	}

	public function test_it_returns_every_configuration_value(): void {
		$values = [
			'feature' => [
				'enabled' => true,
			],
		];

		$configuration = new ArrayConfiguration($values);

		$this->assertSame($values, $configuration->all());
	}

	public function test_an_exact_key_takes_priority_over_its_dotted_path(): void {
		$configuration = new ArrayConfiguration([
			'feature.value' => 'exact',
			'feature'       => [
				'value' => 'nested',
			],
		]);

		$this->assertTrue($configuration->has('feature.value'));
		$this->assertSame('exact', $configuration->get('feature.value'));
	}
}
