<?php declare(strict_types=1);

namespace StellarWP\Foundation\Shutdown;

use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner;
use Throwable;

/**
 * Finishes the current HTTP response before running termination work.
 */
final class ResponseFinishingRunner implements ShutdownRunner
{
	private bool $terminated = false;

	public function __construct(
		private readonly ShutdownRunner $runner
	) {
	}

	public function terminate(): void {
		if ($this->terminated) {
			return;
		}

		$this->terminated = true;

		foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $finishRequest) {
			if (! function_exists($finishRequest)) {
				continue;
			}

			try {
				if ($finishRequest()) {
					break;
				}
			} catch (Throwable) {
				// Response finishing is best-effort and must not block termination work.
			}
		}

		$this->runner->terminate();
	}
}
