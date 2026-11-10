<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Closure;
use mysqli_driver;
use Throwable;

/**
 * Enable mysqli exceptions for a driver call, restoring the caller's reporting mode.
 *
 * @internal Never wrap application callbacks: mysqli reporting is shared across connections.
 */
final readonly class ErrorReporter
{
	/**
	 * Run a synchronous driver operation with the exception reporting Doctrine requires.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @throws Throwable When the driver operation fails.
	 *
	 * @return T
	 */
	public function run(Closure $operation): mixed {
		$driver              = new mysqli_driver();
		$mode                = $driver->report_mode;
		$driver->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

		try {
			return $operation();
		} finally {
			$driver->report_mode = $mode;
		}
	}
}
