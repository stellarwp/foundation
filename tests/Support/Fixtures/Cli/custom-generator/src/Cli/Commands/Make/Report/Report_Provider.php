<?php declare(strict_types=1);

namespace Plugin\Cli\Commands\Make\Report;

use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Container\Contracts\Provider;

/**
 * Register the report generator and its default location.
 */
final class Report_Provider extends Provider
{
	private bool $registered = false;

	/**
	 * Contribute report defaults before generator commands are resolved.
	 */
	public function register(): void {
		if ($this->registered) {
			return;
		}

		$this->container->singleton(Report_Command::class);
		$this->container->mergeArrayVar(CliProvider::GENERATOR_NAMESPACES, [
			Report_Command::CONFIG_KEY => 'Reports',
		]);

		$this->registered = true;
	}
}
