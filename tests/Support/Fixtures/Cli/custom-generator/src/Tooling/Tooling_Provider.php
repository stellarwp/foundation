<?php declare(strict_types=1);

namespace Plugin\Tooling;

use Plugin\Tooling\Commands\Report_Command;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;

/**
 * Register the project's developer commands.
 */
final class Tooling_Provider extends Provider
{
	/**
	 * Wire the project's commands before the console application is resolved.
	 */
	public function register(): void {
		$this->container->mergeArrayVar(CliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(Report_Command::class),
		]);
	}
}
