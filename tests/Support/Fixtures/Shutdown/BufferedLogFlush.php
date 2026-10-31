<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Shutdown;

use Monolog\Handler\BufferHandler;
use StellarWP\Foundation\Shutdown\Contracts\Terminable;

/**
 * Gives an application's shared logging buffer an explicit shutdown boundary.
 */
final readonly class BufferedLogFlush implements Terminable
{
	public function __construct(private BufferHandler $buffer) {
	}

	public function terminate(): void {
		$this->buffer->flush();
	}
}
