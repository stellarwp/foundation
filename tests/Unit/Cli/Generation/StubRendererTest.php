<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Cli\Generation;

use RuntimeException;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Tests\TestCase;

final class StubRendererTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = $this->prepare_temp_dir('stub-renderer');
	}

	public function test_it_replaces_spaced_and_unspaced_placeholders(): void {
		$stub = $this->tempDir . '/foundation-stub-' . bin2hex(random_bytes(8)) . '.stub';

		file_put_contents($stub, 'Class {{ class }} in {{namespace}}');

		$this->assertSame(
			'Class Sync_Command in Acme\\Plugin',
			(new StubRenderer())->render($stub, [
				'class'     => 'Sync_Command',
				'namespace' => 'Acme\\Plugin',
			])
		);
	}

	public function test_it_fails_when_the_stub_cannot_be_read(): void {
		$missingStub = $this->tempDir . '/foundation-missing-stub-' . bin2hex(random_bytes(8)) . '.stub';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(sprintf('Could not read stub "%s".', $missingStub));

		(new StubRenderer())->render($missingStub, []);
	}
}
