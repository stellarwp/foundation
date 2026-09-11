<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation\ValueObjects;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\ValueObjects\PhpNamespace;
use StellarWP\Foundation\Tests\TestCase;

final class PhpNamespaceTest extends TestCase
{
	public function test_it_accepts_a_valid_php_namespace(): void {
		$namespace = new PhpNamespace('YourPlugin\\Database');

		$this->assertSame('YourPlugin\\Database', $namespace->value);
	}

	public function test_it_rejects_an_invalid_php_namespace(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Namespace "YourPlugin\\Invalid Namespace" is not a valid PHP namespace.');

		new PhpNamespace('YourPlugin\\Invalid Namespace');
	}

	public function test_it_rejects_empty_namespace_segments(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Namespace "YourPlugin\\\\Database" is not a valid PHP namespace.');

		new PhpNamespace('YourPlugin\\\\Database');
	}
}
