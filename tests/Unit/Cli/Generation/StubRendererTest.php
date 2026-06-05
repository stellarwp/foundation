<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Tests\TestCase;

final class StubRendererTest extends TestCase
{
	public function test_it_replaces_spaced_and_unspaced_placeholders(): void {
		$stub = tempnam(sys_get_temp_dir(), 'foundation-stub-');

		file_put_contents($stub, 'Class {{ class }} in {{namespace}}');

		try {
			$this->assertSame(
				'Class Sync_Command in Acme\\Plugin',
				(new StubRenderer())->render($stub, [
					'class'     => 'Sync_Command',
					'namespace' => 'Acme\\Plugin',
				])
			);
		} finally {
			unlink($stub);
		}
	}

	public function test_it_fails_when_the_stub_cannot_be_read(): void {
		$missingStub = sys_get_temp_dir() . '/foundation-missing-stub-' . bin2hex(random_bytes(8)) . '.stub';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('Could not read stub "%s".', $missingStub));

		(new StubRenderer())->render($missingStub, []);
	}
}
