<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli;

use StellarWP\Foundation\Cli\Contracts\CommandProvider;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;

/**
 * Symfony Console application for Foundation tooling.
 *
 * Use this as the CLI entry point when commands should be assembled by the
 * Foundation container, including commands contributed by command providers.
 */
final class Application extends SymfonyApplication
{
	/**
	 * @param iterable<Command>         $commands
	 * @param iterable<CommandProvider> $commandProviders
	 */
	public function __construct(iterable $commands = [], iterable $commandProviders = []) {
		parent::__construct('Foundation');

		foreach ($commands as $command) {
			$this->addCommands([$command]);
		}

		foreach ($commandProviders as $commandProvider) {
			$this->addCommandProvider($commandProvider);
		}
	}

	public function addCommandProvider(CommandProvider $commandProvider): void {
		foreach ($commandProvider->commands() as $command) {
			$this->addCommands([$command]);
		}
	}
}
