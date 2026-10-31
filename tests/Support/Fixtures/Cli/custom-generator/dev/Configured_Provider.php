<?php declare(strict_types=1);

namespace Plugin\Dev;

use Plugin\Tooling\Commands\Lint_Command;
use Plugin\Tooling\Commands\Prefix_Command;
use Plugin\Tooling\Commands\Report_Command;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;

/**
 * Register the project's developer commands.
 */
final class Configured_Provider extends Provider
{
	private bool $registered = false;

	/**
	 * Wire the project's commands before the console application is resolved.
	 */
	public function register(): void {
		if ($this->registered) {
			return;
		}

		$this->container->when(Prefix_Command::class)
			->needs('$prefix')
			->give($this->config->get('foundation.prefix', 'nx'));

		$this->container->mergeArrayVar(CliProvider::COMMANDS, static fn (C $c): array => [
			$c->get(Lint_Command::class),
			$c->get(Report_Command::class),
			$c->get(Prefix_Command::class),
		]);

		$this->registered = true;
	}
}
