<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Traits;

use StellarWP\Foundation\Tests\TestCase;

/**
 * @mixin TestCase
 */
trait WithDataDir
{
	/**
	 * Retrieve a path from the tests data directory.
	 */
	protected function data_dir(string $appendPath = ''): string {
		return $this->container->get(TestCase::DATA_DIR) . $appendPath;
	}
}
