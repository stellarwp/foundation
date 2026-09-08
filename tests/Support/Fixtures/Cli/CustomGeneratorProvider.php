<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Cli;

use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Contributes generator conventions from a separately owned feature.
 */
final class CustomGeneratorProvider extends Provider
{
	/**
	 * Register namespace suffixes for custom generators.
	 */
	public function register(): void {
		$this->container->mergeArrayVar(CliProvider::GENERATOR_NAMESPACES, [
			'report' => 'Reports',
			'job'    => 'Jobs',
		]);
	}
}
