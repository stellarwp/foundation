<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Commands\Make\Database\ValueObjects;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\ProviderRegistrationResult;
use StellarWP\Foundation\Tests\TestCase;

final class ProviderRegistrationResultTest extends TestCase
{
	public function test_it_describes_a_provider_that_is_ready_for_registration(): void {
		$result = ProviderRegistrationResult::ready();

		$this->assertTrue($result->succeeded());
		$this->assertFalse($result->wasUpdated());
		$this->assertFalse($result->wasAlreadyRegistered());
		$this->assertNull($result->failureReason());
	}

	public function test_it_describes_an_updated_provider(): void {
		$result = ProviderRegistrationResult::updated();

		$this->assertTrue($result->succeeded());
		$this->assertTrue($result->wasUpdated());
		$this->assertFalse($result->wasAlreadyRegistered());
		$this->assertNull($result->failureReason());
	}

	public function test_it_describes_an_existing_registration(): void {
		$result = ProviderRegistrationResult::alreadyRegistered();

		$this->assertTrue($result->succeeded());
		$this->assertFalse($result->wasUpdated());
		$this->assertTrue($result->wasAlreadyRegistered());
		$this->assertNull($result->failureReason());
	}

	/**
	 * @dataProvider failureProvider
	 */
	#[DataProvider('failureProvider')]
	public function test_it_describes_registration_failures(string $factory, string $reason): void {
		$result = ProviderRegistrationResult::$factory();

		$this->assertFalse($result->succeeded());
		$this->assertFalse($result->wasUpdated());
		$this->assertFalse($result->wasAlreadyRegistered());
		$this->assertSame($reason, $result->failureReason());
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function failureProvider(): iterable {
		yield 'not found' => ['notFound', 'file does not exist or is not readable'];

		yield 'read failed' => ['readFailed', 'file could not be read'];

		yield 'not writable' => ['notWritable', 'file is not writable'];

		yield 'missing anchor' => ['missingAnchor', 'file does not contain a generated database provider registration point'];

		yield 'missing marker' => ['missingMarker', 'file does not contain the generated database provider markers'];

		yield 'import collision' => ['importCollision', 'another class declaration or import uses the same short class name'];

		yield 'parse failed' => ['parseFailed', 'file could not be parsed as PHP'];

		yield 'write failed' => ['writeFailed', 'file could not be written'];
	}
}
